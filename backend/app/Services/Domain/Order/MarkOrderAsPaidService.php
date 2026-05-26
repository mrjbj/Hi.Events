<?php

namespace HiEvents\Services\Domain\Order;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderItemDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentAdjustmentDomainObjectAbstract;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\InvoiceStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\InvoiceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderItemRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderPaymentAdjustmentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Database\DatabaseManager;
use Throwable;

class MarkOrderAsPaidService
{
    public function __construct(
        private readonly OrderRepositoryInterface                  $orderRepository,
        private readonly OrderItemRepositoryInterface              $orderItemRepository,
        private readonly DatabaseManager                           $databaseManager,
        private readonly AffiliateRepositoryInterface              $affiliateRepository,
        private readonly InvoiceRepositoryInterface                $invoiceRepository,
        private readonly AttendeeRepositoryInterface               $attendeeRepository,
        private readonly DomainEventDispatcherService              $domainEventDispatcherService,
        private readonly OrderApplicationFeeCalculationService     $orderApplicationFeeCalculationService,
        private readonly EventRepositoryInterface                  $eventRepository,
        private readonly OrderApplicationFeeService                $orderApplicationFeeService,
        private readonly SendOrderDetailsService                   $sendOrderDetailsService,
        private readonly OrderPaymentAdjustmentRepositoryInterface $orderPaymentAdjustmentRepository,
    )
    {
    }

    /**
     * @throws ResourceConflictException|Throwable
     */
    public function markOrderAsPaid(MarkOrderAsPaidDTO $dto): OrderDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            /** @var OrderDomainObject $order */
            $order = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->loadRelation(AttendeeDomainObject::class)
                ->loadRelation(InvoiceDomainObject::class)
                ->findFirstWhere([
                    OrderDomainObjectAbstract::ID => $dto->orderId,
                    OrderDomainObjectAbstract::EVENT_ID => $dto->eventId,
                ]);

            $event = $this->eventRepository
                ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
                ->loadRelation(new Relationship(EventSettingDomainObject::class))
                ->findById($order->getEventId());

            if ($order->getStatus() !== OrderStatus::AWAITING_OFFLINE_PAYMENT->name) {
                throw new ResourceConflictException(__('Order is not awaiting offline payment'));
            }

            $this->applyAmountOverrideIfNeeded($order, $dto);

            $this->updateOrderStatusAndMethod($dto, $order);

            $this->updateOrderInvoice($dto->orderId);

            $updatedOrder = $this->orderRepository
                ->loadRelation(new Relationship(
                    domainObject: OrderItemDomainObject::class,
                    nested: [new Relationship(ProductDomainObject::class, name: 'product')],
                ))
                ->findById($dto->orderId);

            // Update affiliate sales if this order has an affiliate
            if ($updatedOrder->getAffiliateId()) {
                $this->affiliateRepository->incrementSales(
                    $updatedOrder->getAffiliateId(),
                    $updatedOrder->getTotalGross()
                );
            }

            $this->updateAttendeeStatuses($updatedOrder);

            event(new OrderStatusChangedEvent(
                order: $updatedOrder,
                sendEmails: false
            ));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_MARKED_AS_PAID,
                    orderId: $dto->orderId,
                ),
            );

            $this->storeApplicationFeePayment($updatedOrder);

            $this->sendOrderDetailsService->sendCustomerOrderSummary(
                order: $updatedOrder,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                invoice: $order->getLatestInvoice(),
            );

            return $updatedOrder;
        });
    }

    /**
     * If the collected amount differs from the order's total, adjust order + item
     * totals and write an audit row. Defensive choice: any override clears
     * taxes/fees entirely — the collected amount becomes the gross. This keeps
     * the math predictable for door-collected payments.
     */
    private function applyAmountOverrideIfNeeded(OrderDomainObject $order, MarkOrderAsPaidDTO $dto): void
    {
        if ($dto->collectedAmount === null) {
            return;
        }

        $originalTotal = round((float) $order->getTotalGross(), 2);
        $collected = round($dto->collectedAmount, 2);

        if ($originalTotal === $collected) {
            return;
        }

        $this->orderPaymentAdjustmentRepository->create([
            OrderPaymentAdjustmentDomainObjectAbstract::ORDER_ID => $order->getId(),
            OrderPaymentAdjustmentDomainObjectAbstract::ORIGINAL_TOTAL_GROSS => $originalTotal,
            OrderPaymentAdjustmentDomainObjectAbstract::ORIGINAL_TOTAL_BEFORE_ADDITIONS => (float) $order->getTotalBeforeAdditions(),
            OrderPaymentAdjustmentDomainObjectAbstract::ORIGINAL_TOTAL_TAX => (float) $order->getTotalTax(),
            OrderPaymentAdjustmentDomainObjectAbstract::ORIGINAL_TOTAL_FEE => (float) $order->getTotalFee(),
            OrderPaymentAdjustmentDomainObjectAbstract::ADJUSTED_TOTAL_GROSS => $collected,
            OrderPaymentAdjustmentDomainObjectAbstract::PAYMENT_METHOD => $dto->paymentMethod->value,
            OrderPaymentAdjustmentDomainObjectAbstract::PAYMENT_REFERENCE => $dto->paymentReference,
            OrderPaymentAdjustmentDomainObjectAbstract::ADJUSTED_BY_USER_ID => $dto->adjustedByUserId,
            OrderPaymentAdjustmentDomainObjectAbstract::ADJUSTED_BY_IP => $dto->adjustedByIp,
            OrderPaymentAdjustmentDomainObjectAbstract::CREATED_AT => now()->toDateTimeString(),
        ]);

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::TOTAL_GROSS => $collected,
            OrderDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS => $collected,
            OrderDomainObjectAbstract::TOTAL_TAX => 0,
            OrderDomainObjectAbstract::TOTAL_FEE => 0,
            OrderDomainObjectAbstract::TAXES_AND_FEES_ROLLUP => null,
        ]);

        $this->rescaleOrderItems($order, $collected);
    }

    /**
     * Scale per-item amounts proportionally to the new order total. Taxes/fees
     * on items are zeroed (matching the order-level defensive policy). The last
     * item absorbs any rounding remainder so the items sum back to the override.
     */
    private function rescaleOrderItems(OrderDomainObject $order, float $newTotal): void
    {
        $items = $order->getOrderItems();
        if (!$items || $items->isEmpty()) {
            return;
        }

        $originalTotal = (float) $order->getTotalGross();
        $ratio = $originalTotal > 0 ? $newTotal / $originalTotal : 0;

        $itemsArray = $items->all();
        $count = count($itemsArray);
        $running = 0.0;

        foreach ($itemsArray as $index => $item) {
            /** @var OrderItemDomainObject $item */
            if ($index === $count - 1) {
                $itemTotal = round($newTotal - $running, 2);
            } else {
                $itemTotal = $originalTotal > 0
                    ? round((float) $item->getTotalGross() * $ratio, 2)
                    : round($newTotal / $count, 2);
                $running += $itemTotal;
            }

            $this->orderItemRepository->updateFromArray($item->getId(), [
                OrderItemDomainObjectAbstract::PRICE => $itemTotal,
                OrderItemDomainObjectAbstract::TOTAL_BEFORE_ADDITIONS => $itemTotal,
                OrderItemDomainObjectAbstract::TOTAL_GROSS => $itemTotal,
                OrderItemDomainObjectAbstract::TOTAL_TAX => 0,
                OrderItemDomainObjectAbstract::TOTAL_SERVICE_FEE => 0,
                OrderItemDomainObjectAbstract::TAXES_AND_FEES_ROLLUP => null,
            ]);
        }
    }

    private function updateOrderInvoice(int $orderId): void
    {
        $invoice = $this->invoiceRepository->findLatestInvoiceForOrder($orderId);

        if ($invoice) {
            $this->invoiceRepository->updateFromArray($invoice->getId(), [
                'status' => InvoiceStatus::PAID->name,
            ]);
        }
    }

    private function updateOrderStatusAndMethod(MarkOrderAsPaidDTO $dto, OrderDomainObject $order): void
    {
        $attributes = [
            OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            OrderDomainObjectAbstract::OFFLINE_PAYMENT_METHOD => $dto->paymentMethod->value,
            OrderDomainObjectAbstract::OFFLINE_PAYMENT_REFERENCE => $dto->paymentReference,
        ];

        if (!$order->getPaymentProvider()) {
            $attributes[OrderDomainObjectAbstract::PAYMENT_PROVIDER] = PaymentProviders::OFFLINE->value;
        }

        $this->orderRepository->updateFromArray($order->getId(), $attributes);
    }

    private function updateAttendeeStatuses(OrderDomainObject $updatedOrder): void
    {
        $this->attendeeRepository->updateWhere(
            attributes: [
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            where: [
                'order_id' => $updatedOrder->getId(),
                'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            ],
        );
    }

    /**
     * @throws MathException
     */
    private function storeApplicationFeePayment(OrderDomainObject $updatedOrder): void
    {
        /** @var EventDomainObject $event */
        $event = $this->eventRepository
            ->loadRelation(new Relationship(
                domainObject: AccountDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: AccountConfigurationDomainObject::class,
                        name: 'configuration',
                    ),
                ],
                name: 'account'
            ))
            ->findById($updatedOrder->getEventId());

        /** @var AccountConfigurationDomainObject $config */
        $config = $event->getAccount()->getConfiguration();

        $this->orderApplicationFeeService->createOrderApplicationFee(
            orderId: $updatedOrder->getId(),
            applicationFeeAmountMinorUnit: $this->orderApplicationFeeCalculationService->calculateApplicationFee(
                accountConfiguration: $config,
                order: $updatedOrder,
            )?->netApplicationFee?->toMinorUnit() ?? 0,
            orderApplicationFeeStatus: OrderApplicationFeeStatus::AWAITING_PAYMENT,
            paymentMethod: PaymentProviders::OFFLINE,
            currency: $updatedOrder->getCurrency(),
        );
    }
}
