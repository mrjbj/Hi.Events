<?php

namespace HiEvents\Services\Domain\Order;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderPaymentDomainObjectAbstract;
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
use HiEvents\Repository\Interfaces\OrderPaymentRepositoryInterface;
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
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly OrderApplicationFeeCalculationService $orderApplicationFeeCalculationService,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrderApplicationFeeService $orderApplicationFeeService,
        private readonly SendOrderDetailsService $sendOrderDetailsService,
        private readonly OrderPaymentRepositoryInterface $orderPaymentRepository,
    ) {}

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

            $this->recordSettlementPayment($order, $dto);

            $this->updateOrderStatusAndProvider($order);

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

    private function updateOrderInvoice(int $orderId): void
    {
        $invoice = $this->invoiceRepository->findLatestInvoiceForOrder($orderId);

        if ($invoice) {
            $this->invoiceRepository->updateFromArray($invoice->getId(), [
                'status' => InvoiceStatus::PAID->name,
            ]);
        }
    }

    /**
     * Record the settling receipt on the payments ledger so the order's balance
     * stays consistent with the ledger model. The order's totals are untouched.
     *
     * The recorded amount is the cash actually received (`amountReceived`) when the
     * caller supplies it — so an over-the-counter payment that exceeds the balance
     * (e.g. cash with no change given) is reconcilable against the drawer, with the
     * excess surfaced as overpaid. It defaults to the full balance when not given.
     */
    private function recordSettlementPayment(OrderDomainObject $order, MarkOrderAsPaidDTO $dto): void
    {
        $amount = $dto->amountReceived !== null
            ? round($dto->amountReceived, 2)
            : round((float) $order->getTotalGross(), 2);

        if ($amount <= 0.0) {
            return;
        }

        $this->orderPaymentRepository->create([
            OrderPaymentDomainObjectAbstract::ORDER_ID => $order->getId(),
            OrderPaymentDomainObjectAbstract::TRANSACTION_TYPE => PaymentTransactionType::PAYMENT->value,
            OrderPaymentDomainObjectAbstract::PAYMENT_METHOD => $dto->paymentMethod->value,
            OrderPaymentDomainObjectAbstract::AMOUNT => $amount,
            OrderPaymentDomainObjectAbstract::CURRENCY => $order->getCurrency(),
            OrderPaymentDomainObjectAbstract::REFERENCE => $dto->paymentReference,
            OrderPaymentDomainObjectAbstract::RECORDED_BY_USER_ID => null,
            OrderPaymentDomainObjectAbstract::RECORDED_BY_IP => $dto->recordedByIp,
            OrderPaymentDomainObjectAbstract::CREATED_AT => now()->toDateTimeString(),
            OrderPaymentDomainObjectAbstract::UPDATED_AT => now()->toDateTimeString(),
        ]);
    }

    private function updateOrderStatusAndProvider(OrderDomainObject $order): void
    {
        $attributes = [
            OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
            OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
        ];

        if (! $order->getPaymentProvider()) {
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
