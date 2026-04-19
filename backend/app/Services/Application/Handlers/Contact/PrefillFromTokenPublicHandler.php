<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactPrefillResultDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\PrefillFromTokenPublicDTO;
use HiEvents\Services\Domain\Contact\ContactPrefillService;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;

readonly class PrefillFromTokenPublicHandler
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private ContactRepositoryInterface $contactRepository,
        private ContactSignedTokenService $tokenService,
        private ContactPrefillService $prefillService,
    ) {}

    public function handle(PrefillFromTokenPublicDTO $dto): ContactPrefillResultDTO
    {
        $payload = $this->tokenService->verify($dto->token);
        if ($payload === null) {
            return new ContactPrefillResultDTO(found: false);
        }

        $event = $this->eventRepository->findFirst($dto->eventId);
        if ($event === null) {
            return new ContactPrefillResultDTO(found: false);
        }

        if ($event->getAccountId() !== $payload->accountId) {
            throw new ResourceConflictException(__('Token is not valid for this event.'));
        }

        $contact = $this->contactRepository->findFirst($payload->contactId);
        if ($contact === null || $contact->getAccountId() !== $payload->accountId) {
            return new ContactPrefillResultDTO(found: false);
        }

        $resolved = $this->prefillService->resolveForContact($contact, $dto->eventId);

        return new ContactPrefillResultDTO(
            found: true,
            first_name: $contact->getFirstName(),
            last_name: $contact->getLastName(),
            question_answers: $resolved['answers'],
            answered_question_ids: $resolved['answered_ids'],
        );
    }
}
