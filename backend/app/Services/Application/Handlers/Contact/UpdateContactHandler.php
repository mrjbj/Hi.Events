<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpsertContactDTO;
use HiEvents\Services\Domain\Contact\ContactUpsertService;
use Illuminate\Support\Facades\DB;
use Throwable;

readonly class UpdateContactHandler
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
        private AttendeeRepositoryInterface $attendeeRepository,
        private ContactUpsertService $contactUpsertService,
    ) {}

    /**
     * @throws ContactEmailConflictException
     * @throws Throwable
     */
    public function handle(int $contactId, int $accountId, int $userId, UpsertContactDTO $dto): ContactDomainObject
    {
        $contact = $this->contactRepository->findFirstWhere([
            ContactDomainObject::ID => $contactId,
            ContactDomainObject::ACCOUNT_ID => $accountId,
        ]);

        $updates = [];
        if ($dto->wasProvided('first_name')) {
            $updates[ContactDomainObject::FIRST_NAME] = $dto->first_name;
        }
        if ($dto->wasProvided('last_name')) {
            $updates[ContactDomainObject::LAST_NAME] = $dto->last_name;
        }

        $emailChanging = $dto->wasProvided('email')
            && strtolower($dto->email) !== strtolower($contact->getEmail());

        if ($emailChanging) {
            $existing = $this->contactRepository->findByEmailAndAccountId($dto->email, $accountId);
            if ($existing !== null && $existing->getId() !== $contactId) {
                throw new ContactEmailConflictException;
            }
        }

        if ($emailChanging || ! empty($updates)) {
            DB::transaction(function () use ($contactId, $updates, $emailChanging, $dto, $userId) {
                if (! empty($updates)) {
                    $this->contactRepository->updateFromArray($contactId, $updates);
                }
                if ($emailChanging) {
                    try {
                        // Update only the contact (the canonical current address).
                        // Linked attendee rows are intentionally left untouched:
                        // each attendee.email is the historical fact of the address
                        // used at that event. Cross-event/marketing sends already
                        // read the contact's current email, and same-event sends
                        // fall back to it when an attendee's address is suppressed.
                        // Attribute the change to the editing user so it shows in
                        // the contact's History tab.
                        $this->contactRepository->updateEmail($contactId, $dto->email, 'manual_edit', $userId);
                    } catch (Throwable $e) {
                        if ($this->isUniqueViolation($e)) {
                            throw new ContactEmailConflictException;
                        }
                        throw $e;
                    }
                }
            });
        }

        if ($dto->wasProvided('attributes') && ! empty($dto->attributes)) {
            $contact = $this->contactRepository->findById($contactId);

            return $this->contactUpsertService->updateContactAttributes($contact, $dto->attributes, $userId);
        }

        if ($emailChanging || ! empty($updates)) {
            return $this->contactRepository->findById($contactId);
        }

        return $contact;
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        return method_exists($e, 'getCode') && (string) $e->getCode() === '23505';
    }
}
