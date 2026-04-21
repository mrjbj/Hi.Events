<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\GetOrderPublicDTO;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetOrderPublicHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface         $orderRepository,
        private readonly CheckoutSessionManagementService $sessionIdentifierService,
        private readonly ContactSignedTokenService        $contactTokenService,
        private readonly ContactRepositoryInterface       $contactRepository,
    )
    {
    }

    public function handle(GetOrderPublicDTO $getOrderData): OrderDomainObject
    {
        $order = $this->getOrderDomainObject($getOrderData);

        if (!$order) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        if ($order->getStatus() === OrderStatus::RESERVED->name) {
            if ($order->getSessionId() === null) {
                throw new UnauthorizedException(
                    __('Sorry, we could not verify your session. Please restart your order.')
                );
            }
            $this->verifySessionId($order->getSessionId());
        }

        $this->attachAttendeeContactTokens($order);
        $this->attachBuyerContactToken($order);

        return $order;
    }

    /**
     * Mint a signed contact token for the buyer (order.email's contact) if
     * one exists on this account. Surfaces as buyer_contact_token on the
     * order response so the confirmation page can offer a "My Profile"
     * button next to Order Details — handy when the buyer isn't also an
     * attendee, or for single-attendee orders where they don't need to
     * scroll to the guest list.
     */
    private function attachBuyerContactToken(OrderDomainObject $order): void
    {
        $accountId = $this->resolveAccountId($order);
        $email = $order->getEmail();
        if ($accountId === null || !is_string($email) || $email === '') {
            $order->setBuyerContactToken(null);
            return;
        }

        try {
            $contact = $this->contactRepository->findByEmailAndAccountId($email, $accountId);
        } catch (\Throwable) {
            $order->setBuyerContactToken(null);
            return;
        }

        if ($contact === null) {
            $order->setBuyerContactToken(null);
            return;
        }

        $order->setBuyerContactToken([
            'contact_id' => (int) $contact->getId(),
            'token' => $this->contactTokenService->generate((int) $contact->getId(), $accountId),
        ]);
    }

    /**
     * For each unique contact_id linked to an attendee on this order, mint a
     * signed contact token the confirmation page can pass to /contacts/me
     * for profile review + update. De-duplicates so two attendees on the same
     * contact get one entry (first attendee wins).
     */
    private function attachAttendeeContactTokens(OrderDomainObject $order): void
    {
        $attendees = $order->getAttendees();
        if ($attendees === null || $attendees->isEmpty()) {
            $order->setAttendeeContactTokens([]);
            return;
        }

        $accountId = $this->resolveAccountId($order);
        if ($accountId === null) {
            $order->setAttendeeContactTokens([]);
            return;
        }

        $seen = [];
        $tokens = [];
        foreach ($attendees as $attendee) {
            $contactId = $attendee->getContactId();
            if ($contactId === null || isset($seen[$contactId])) {
                continue;
            }
            $seen[$contactId] = true;
            $tokens[] = [
                'contact_id' => (int) $contactId,
                'token' => $this->contactTokenService->generate((int) $contactId, $accountId),
            ];
        }

        $order->setAttendeeContactTokens($tokens);
    }

    private function resolveAccountId(OrderDomainObject $order): ?int
    {
        $event = $order->getEvent();
        if ($event !== null) {
            return $event->getAccountId();
        }

        $accountId = DB::table('events')
            ->where('id', $order->getEventId())
            ->value('account_id');

        return $accountId !== null ? (int) $accountId : null;
    }

    private function verifySessionId(string $orderSessionId): void
    {
        if (!$this->sessionIdentifierService->verifySession($orderSessionId)) {
            throw new UnauthorizedException(
                __('Sorry, we could not verify your session. Please restart your order.')
            );
        }
    }

    private function getOrderDomainObject(GetOrderPublicDTO $getOrderData): ?OrderDomainObject
    {
        $orderQuery = $this->orderRepository
            ->loadRelation(new Relationship(
                domainObject: AttendeeDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: ProductDomainObject::class,
                        nested: [
                            new Relationship(
                                domainObject: ProductPriceDomainObject::class,
                            )
                        ],
                        name: ProductDomainObjectAbstract::SINGULAR_NAME,
                    )
                ],
            ))
            ->loadRelation(new Relationship(domainObject: InvoiceDomainObject::class))
            ->loadRelation(new Relationship(
                domainObject: OrderItemDomainObject::class,
            ));

        if ($getOrderData->includeEventInResponse) {
            $orderQuery->loadRelation(new Relationship(
                domainObject: EventDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: EventSettingDomainObject::class,
                    ),
                    new Relationship(
                        domainObject: OrganizerDomainObject::class,
                        name: OrganizerDomainObjectAbstract::SINGULAR_NAME,
                    ),
                    new Relationship(
                        domainObject: ImageDomainObject::class,
                    )
                ],
                name: EventDomainObjectAbstract::SINGULAR_NAME
            ));
        }

        return $orderQuery->findByShortId($getOrderData->orderShortId);
    }
}
