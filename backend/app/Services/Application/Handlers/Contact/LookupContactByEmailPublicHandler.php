<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactLookupResultDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\LookupContactByEmailPublicDTO;

readonly class LookupContactByEmailPublicHandler
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private ContactRepositoryInterface $contactRepository,
    ) {}

    public function handle(LookupContactByEmailPublicDTO $dto): ContactLookupResultDTO
    {
        $event = $this->eventRepository->findFirst($dto->eventId);
        if ($event === null) {
            return new ContactLookupResultDTO(found: false);
        }

        $contact = $this->contactRepository->findByEmailAndAccountId(
            email: $dto->email,
            accountId: $event->getAccountId(),
        );
        if ($contact === null) {
            return new ContactLookupResultDTO(found: false);
        }

        return new ContactLookupResultDTO(
            found: true,
            first_name: $contact->getFirstName(),
            last_name: $contact->getLastName(),
        );
    }
}
