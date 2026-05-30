<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
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
        private readonly OrderRepositoryInterface        $orderRepository,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
        private readonly ApplyOrderBalanceStatusService  $applyOrderBalanceStatusService,
        private readonly DatabaseManager                 $databaseManager,
    )
    {
    }

    /**
     * @throws ResourceConflictException|ResourceNotFoundException|Throwable
     */
    public function record(RecordOrderPaymentDTO $dto): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            /** @var OrderDomainObject|null $order */
            $order = $this->orderRepository
                ->loadRelation(StripePaymentDomainObject::class)
                ->findFirstWhere([
                    OrderDomainObjectAbstract::ID => $dto->orderId,
                    OrderDomainObjectAbstract::EVENT_ID => $dto->eventId,
                ]);

            if ($order === null) {
                throw new ResourceNotFoundException(__('Order not found'));
            }

            if (!in_array($order->getStatus(), [OrderStatus::AWAITING_OFFLINE_PAYMENT->name, OrderStatus::COMPLETED->name], true)) {
                throw new ResourceConflictException(
                    __('Payments can only be recorded against completed or offline-pending orders')
                );
            }

            $this->orderPaymentRepository->create([
                OrderPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
                OrderPaymentDomainObjectAbstract::TYPE => $dto->type->value,
                OrderPaymentDomainObjectAbstract::AMOUNT => round($dto->amount, 2),
                OrderPaymentDomainObjectAbstract::CURRENCY => $order->getCurrency(),
                OrderPaymentDomainObjectAbstract::REFERENCE => $dto->reference,
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
}
