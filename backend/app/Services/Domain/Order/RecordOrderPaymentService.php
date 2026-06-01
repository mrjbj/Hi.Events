<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Records a single credit (cash receipt, donation, comp or write-off) on the
 * order's payments ledger, then reconciles the order's status from the resulting
 * balance. The order's totals/line items are never modified — the order is the
 * receivable; this only records money against it.
 */
class RecordOrderPaymentService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
        private readonly ApplyOrderBalanceStatusService $applyOrderBalanceStatusService,
        private readonly OrderBalanceService $orderBalanceService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ResourceConflictException|ResourceNotFoundException|Throwable
     */
    public function record(RecordOrderPaymentDTO $dto): OrderDomainObject
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
                    __('Payments can only be recorded against completed or offline-pending orders')
                );
            }

            foreach ($this->resolvePaymentRows($order, $dto) as $row) {
                $this->orderPaymentRepository->create($row);
            }

            $this->applyOrderBalanceStatusService->apply($order);

            return $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->loadRelation(AttendeeDomainObject::class)
                ->findById($order->getId());
        });
    }

    /**
     * A PAYMENT that exceeds the outstanding balance can be split into the
     * settling receipt plus a DONATION row for the excess (same method), so an
     * over-the-counter overpayment is recorded as a donation rather than left as
     * an unattributed "overpaid". Any other transaction type, or a payment that
     * doesn't actually overshoot, records a single row as-is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolvePaymentRows(OrderDomainObject $order, RecordOrderPaymentDTO $dto): array
    {
        $amount = round($dto->amount, 2);

        if ($dto->splitExcessAsDonation && $dto->transactionType === PaymentTransactionType::PAYMENT) {
            $outstanding = round(max(0.0, $this->orderBalanceService->getBalanceForOrder($order)->balance), 2);
            $excess = round($amount - $outstanding, 2);

            if ($outstanding > 0.0 && $excess > 0.0) {
                return [
                    $this->buildRow($order, $dto, PaymentTransactionType::PAYMENT, $outstanding, $dto->note),
                    $this->buildRow($order, $dto, PaymentTransactionType::DONATION, $excess, $dto->note ?? __('Overpayment recorded as donation')),
                ];
            }
        }

        return [$this->buildRow($order, $dto, $dto->transactionType, $amount, $dto->note)];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(
        OrderDomainObject $order,
        RecordOrderPaymentDTO $dto,
        PaymentTransactionType $transactionType,
        float $amount,
        ?string $note,
    ): array {
        return [
            OrderPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE => $transactionType->value,
            OrderPaymentDomainObjectAbstract::PAYMENT_METHOD => $dto->paymentMethod?->value,
            OrderPaymentDomainObjectAbstract::AMOUNT => round($amount, 2),
            OrderPaymentDomainObjectAbstract::CURRENCY => $order->getCurrency(),
            OrderPaymentDomainObjectAbstract::REFERENCE => $dto->reference,
            OrderPaymentDomainObjectAbstract::NOTE => $note,
            OrderPaymentDomainObjectAbstract::RECORDED_BY_USER_ID => $dto->recordedByUserId,
            OrderPaymentDomainObjectAbstract::RECORDED_BY_IP => $dto->recordedByIp,
            OrderPaymentDomainObjectAbstract::CREATED_AT => now()->toDateTimeString(),
            OrderPaymentDomainObjectAbstract::UPDATED_AT => now()->toDateTimeString(),
        ];
    }
}
