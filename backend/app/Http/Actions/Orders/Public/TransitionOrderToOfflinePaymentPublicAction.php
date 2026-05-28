<?php

namespace HiEvents\Http\Actions\Orders\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Resources\Order\OrderResourcePublic;
use HiEvents\Services\Application\Handlers\Order\DTO\TransitionOrderToOfflinePaymentPublicDTO;
use HiEvents\Services\Application\Handlers\Order\TransitionOrderToOfflinePaymentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransitionOrderToOfflinePaymentPublicAction extends BaseAction
{
    public function __construct(
        private readonly TransitionOrderToOfflinePaymentHandler $initializeOrderOfflinePaymentPublicHandler,
        private readonly EventRepositoryInterface               $eventRepository,
        private readonly OrderRepositoryInterface               $orderRepository,
    )
    {
    }

    public function __invoke(Request $request, int $eventId, string $orderShortId): JsonResponse
    {
        $skipOfflineProviderCheck = $this->shouldGrantAdminOverride($eventId, $orderShortId);

        $order = $this->initializeOrderOfflinePaymentPublicHandler->handle(
            TransitionOrderToOfflinePaymentPublicDTO::fromArray([
                'orderShortId' => $orderShortId,
                'skipOfflineProviderCheck' => $skipOfflineProviderCheck,
            ]),
        );

        return $this->resourceResponse(
            resource: OrderResourcePublic::class,
            data: $order,
        );
    }

    /**
     * Resolve admin override against the order's own event (not the URL's
     * eventId). Trusting the URL eventId would let an admin of event A grant
     * themselves an override on an unrelated order from event B by crafting the
     * URL — a confused-deputy / IDOR pattern. We re-fetch the event by the
     * order's stored event_id and also assert the URL eventId matches, so any
     * mismatch refuses the override silently.
     */
    private function shouldGrantAdminOverride(int $urlEventId, string $orderShortId): bool
    {
        if (!$this->isUserAuthenticated()) {
            return false;
        }

        $order = $this->orderRepository->findByShortId($orderShortId);
        if ($order === null || $order->getEventId() !== $urlEventId) {
            return false;
        }

        $event = $this->eventRepository->findById($order->getEventId());
        if ($event === null) {
            return false;
        }

        return $this->isAuthorizedAdminViewer($event->getAccountId());
    }
}
