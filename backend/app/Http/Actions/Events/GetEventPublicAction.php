<?php

namespace HiEvents\Http\Actions\Events;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Event\EventResourcePublic;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicEventDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

class GetEventPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetPublicEventHandler $getPublicEventHandler,
        private readonly LoggerInterface       $logger,
    )
    {
    }

    public function __invoke(int $eventId, Request $request): Response|JsonResponse
    {
        $event = $this->getPublicEventHandler->handle(GetPublicEventDTO::fromArray([
            'eventId' => $eventId,
            'ipAddress' => $this->getClientIp($request),
            'promoCode' => strtolower($request->string('promo_code')),
            'isAuthenticated' => $this->isUserAuthenticated(),
        ]));

        if (!$this->canUserViewEvent($event)) {
            $this->logger->debug(__('Event with ID :eventId is not live and user is not authenticated', [
                'eventId' => $eventId
            ]));

            return $this->notFoundResponse();
        }

        if ($this->isAuthorizedAdminViewer($event->getAccountId())) {
            $this->injectAdminPaymentProviders($event);
        }

        return $this->resourceResponse(EventResourcePublic::class, $event);
    }

    /**
     * Admins viewing the public checkout see OFFLINE as an available payment
     * provider even if the event hasn't enabled it for customers — lets them
     * create awaiting-offline orders on behalf of someone without temporarily
     * toggling the event-wide setting.
     */
    private function injectAdminPaymentProviders(EventDomainObject $event): void
    {
        $settings = $event->getEventSettings();
        if ($settings === null) {
            return;
        }

        $providers = $settings->getPaymentProviders();
        $providers = is_array($providers) ? $providers : [];

        if (!in_array(PaymentProviders::OFFLINE->value, $providers, true)) {
            $providers[] = PaymentProviders::OFFLINE->value;
            $settings->setPaymentProviders($providers);
        }
    }

    private function canUserViewEvent(EventDomainObject $event): bool
    {
        if ($event->getStatus() === EventStatus::LIVE->name) {
            return true;
        }

        if ($this->isUserAuthenticated() && $event->getAccountId() === $this->getAuthenticatedAccountId()) {
            return true;
        }

        if ($this->isUserAuthenticated() && $this->getAuthenticatedUserRole() === Role::SUPERADMIN) {
            $this->logger->debug(__('Superadmin user is viewing non-live event with ID :eventId', [
                'eventId' => $event->getId(),
                'accountId' => $this->getAuthenticatedAccountId(),
            ]));
            return true;
        }

        return false;
    }
}
