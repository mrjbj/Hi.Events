<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetAttendeeActionPublic extends BaseAction
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface    $eventRepository,
        private readonly ContactSignedTokenService   $contactTokenService,
    )
    {
    }

    /**
     * @todo move to handler
     */
    public function __invoke(int $eventId, string $attendeeShortId): JsonResponse|Response
    {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: ProductPriceDomainObject::class,
                    ),
                ], name: 'product'))
            ->findFirstWhere([
                AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId
            ]);

        if (!$attendee) {
            return $this->notFoundResponse();
        }

        $this->attachContactToken($attendee, $eventId);

        return $this->resourceResponse(AttendeeResourcePublic::class, $attendee);
    }

    /**
     * Mint a signed contact token for the attendee's linked contact so the
     * ticket page can offer inline profile edit. Skips silently when the
     * attendee isn't linked to a contact (legacy orders pre-contact FKs).
     */
    private function attachContactToken(\HiEvents\DomainObjects\AttendeeDomainObject $attendee, int $eventId): void
    {
        $contactId = $attendee->getContactId();
        if ($contactId === null) {
            return;
        }

        $event = $this->eventRepository->findById($eventId);
        if ($event === null) {
            return;
        }

        $attendee->setContactToken(
            $this->contactTokenService->generate((int) $contactId, (int) $event->getAccountId()),
        );
    }
}
