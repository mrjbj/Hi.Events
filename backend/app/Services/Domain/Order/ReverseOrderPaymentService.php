<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ReverseOrderPaymentDTO;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reverses a previously recorded ledger credit by writing a linked, negative-amount
 * row of the same type. The original row is never mutated or deleted, so the reversal
 * is itself the audit trail. Because OrderBalanceService sums signed amounts, the
 * negative row restores the balance and reduces "collected" without touching the
 * order's totals. Stripe receipts are not ledger rows and are corrected via a refund,
 * never here.
 */
class ReverseOrderPaymentService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
        private readonly ApplyOrderBalanceStatusService $applyOrderBalanceStatusService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceConflictException|ResourceNotFoundException|Throwable
     */
    public function reverse(ReverseOrderPaymentDTO $dto): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            /** @var OrderDomainObject|null $order */
            $order = $this->orderRepository
                ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
                ->findFirstWhere([
                    OrderDomainObjectAbstract::ID => $dto->orderId,
                    OrderDomainObjectAbstract::EVENT_ID => $dto->eventId,
                ]);

            if ($order === null) {
                throw new ResourceNotFoundException(__('Order not found'));
            }

            if (! in_array($order->getStatus(), [OrderStatus::AWAITING_OFFLINE_PAYMENT->name, OrderStatus::COMPLETED->name], true)) {
                throw new ResourceConflictException(
                    __('Payments can only be reversed on completed or offline-pending orders')
                );
            }

            $original = $this->findReversablePayment($dto->paymentId, $order->getId());

            $this->orderPaymentRepository->create([
                OrderPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
                OrderPaymentDomainObjectAbstract::REVERSES_PAYMENT_ID => $original->getId(),
                OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE => $original->getTransactionType(),
                OrderPaymentDomainObjectAbstract::PAYMENT_METHOD => $original->getPaymentMethod(),
                OrderPaymentDomainObjectAbstract::AMOUNT => round($original->getAmount() * -1, 2),
                OrderPaymentDomainObjectAbstract::CURRENCY => $original->getCurrency(),
                OrderPaymentDomainObjectAbstract::REFERENCE => $original->getReference(),
                OrderPaymentDomainObjectAbstract::NOTE => $dto->note,
                OrderPaymentDomainObjectAbstract::RECORDED_BY_USER_ID => $dto->recordedByUserId,
                OrderPaymentDomainObjectAbstract::RECORDED_BY_IP => $dto->recordedByIp,
                OrderPaymentDomainObjectAbstract::CREATED_AT => now()->toDateTimeString(),
                OrderPaymentDomainObjectAbstract::UPDATED_AT => now()->toDateTimeString(),
            ]);

            $this->applyOrderBalanceStatusService->apply($order);

            return $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->loadRelation(AttendeeDomainObject::class)
                ->findById($order->getId());
        });
    }

    /**
     * @throws ResourceConflictException|ResourceNotFoundException
     */
    private function findReversablePayment(int $paymentId, int $orderId): OrderPaymentDomainObject
    {
        /** @var OrderPaymentDomainObject|null $original */
        $original = $this->orderPaymentRepository->findFirstWhere([
            OrderPaymentDomainObjectAbstract::ID => $paymentId,
        ]);

        if ($original === null || $original->getOrderId() !== $orderId) {
            throw new ResourceNotFoundException(__('Payment not found'));
        }

        if ($original->getReversesPaymentId() !== null) {
            throw new ResourceConflictException(__('A reversal cannot itself be reversed'));
        }

        $existingReversal = $this->orderPaymentRepository->findFirstWhere([
            OrderPaymentDomainObjectAbstract::REVERSES_PAYMENT_ID => $paymentId,
        ]);

        if ($existingReversal !== null) {
            throw new ResourceConflictException(__('This payment has already been reversed'));
        }

        return $original;
    }
}
