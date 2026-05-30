<?php

namespace HiEvents\Services\Domain\Order;

use HiEvents\DataTransferObjects\OrderBalanceDTO;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;

/**
 * Derives an order's status and payment status from its outstanding balance and
 * persists them, activating attendees once the order is settled. This is the one
 * place the §5 status matrix lives — callers record money on the ledger, then ask
 * this service to reconcile the order's status. The order's totals are never touched.
 */
class ApplyOrderBalanceStatusService
{
    public function __construct(
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly OrderBalanceService         $orderBalanceService,
    )
    {
    }

    public function apply(OrderDomainObject $order): OrderBalanceDTO
    {
        $balance = $this->orderBalanceService->getBalanceForOrder($order);

        if ($balance->amountOwed <= 0.0) {
            $orderStatus = OrderStatus::COMPLETED->name;
            $paymentStatus = OrderPaymentStatus::NO_PAYMENT_REQUIRED->name;
        } elseif ($balance->isSettled) {
            $orderStatus = OrderStatus::COMPLETED->name;
            $paymentStatus = OrderPaymentStatus::PAYMENT_RECEIVED->name;
        } else {
            $orderStatus = OrderStatus::AWAITING_OFFLINE_PAYMENT->name;
            $paymentStatus = OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name;
        }

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::STATUS => $orderStatus,
            OrderDomainObjectAbstract::PAYMENT_STATUS => $paymentStatus,
        ]);

        if ($balance->isSettled) {
            $this->attendeeRepository->updateWhere(
                attributes: [
                    'status' => AttendeeStatus::ACTIVE->name,
                ],
                where: [
                    'order_id' => $order->getId(),
                    'status' => AttendeeStatus::AWAITING_PAYMENT->name,
                ],
            );
        }

        return $balance;
    }
}
