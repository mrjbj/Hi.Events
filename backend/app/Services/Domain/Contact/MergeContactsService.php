<?php

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Exceptions\ContactMergeException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Collapses a duplicate contact into a surviving canonical contact: the
 * duplicate's attendees are reassigned to the survivor, the survivor's empty
 * fields are filled from the duplicate (survivor's own values always win), the
 * histories and processed/ignored question sets are merged, and the duplicate is
 * soft-deleted (which frees its email for reuse).
 */
class MergeContactsService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws ContactMergeException|Throwable
     */
    public function merge(int $survivorId, int $sourceId, int $accountId): ContactDomainObject
    {
        if ($survivorId === $sourceId) {
            throw new ContactMergeException(__('A contact cannot be merged into itself'));
        }

        $survivor = $this->contactRepository->findFirstWhere([
            ContactDomainObjectAbstract::ID => $survivorId,
            ContactDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        $source = $this->contactRepository->findFirstWhere([
            ContactDomainObjectAbstract::ID => $sourceId,
            ContactDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        if ($survivor === null || $source === null) {
            throw new ContactMergeException(__('One or both contacts could not be found'));
        }

        return $this->databaseManager->transaction(function () use ($survivor, $source) {
            $this->attendeeRepository->updateWhere(
                attributes: [AttendeeDomainObject::CONTACT_ID => $survivor->getId()],
                where: [AttendeeDomainObject::CONTACT_ID => $source->getId()],
            );

            $this->contactRepository->updateFromArray(
                $survivor->getId(),
                $this->buildSurvivorUpdate($survivor, $source),
            );

            // Soft-delete the duplicate; the partial unique index on
            // (account_id, lower(email)) WHERE deleted_at IS NULL frees its email.
            $this->contactRepository->deleteById($source->getId());

            return $this->contactRepository->findById($survivor->getId());
        });
    }

    /**
     * Survivor wins; the duplicate only fills gaps where the survivor's value is
     * empty. Histories merge, question-id sets union, and a merge marker is
     * appended to attributes_history for the audit trail.
     *
     * @return array<string, mixed>
     */
    private function buildSurvivorUpdate(ContactDomainObject $survivor, ContactDomainObject $source): array
    {
        $update = [];
        $gapFills = [];

        if (trim((string) $survivor->getFirstName()) === '' && trim((string) $source->getFirstName()) !== '') {
            $update[ContactDomainObjectAbstract::FIRST_NAME] = $source->getFirstName();
            $gapFills[] = 'first_name';
        }

        if (trim((string) $survivor->getLastName()) === '' && trim((string) $source->getLastName()) !== '') {
            $update[ContactDomainObjectAbstract::LAST_NAME] = $source->getLastName();
            $gapFills[] = 'last_name';
        }

        $mergedAttributes = $this->asArray($survivor->getAttributes());
        foreach ($this->asArray($source->getAttributes()) as $key => $value) {
            $existing = $mergedAttributes[$key] ?? null;
            if ($existing === null || $existing === '' || $existing === []) {
                $mergedAttributes[$key] = $value;
                $gapFills[] = "attributes.$key";
            }
        }
        $update[ContactDomainObjectAbstract::ATTRIBUTES] = $mergedAttributes;

        $history = array_merge(
            $this->asArray($survivor->getAttributesHistory()),
            $this->asArray($source->getAttributesHistory()),
        );
        $history[] = [
            'type' => 'merge',
            'merged_contact_id' => $source->getId(),
            'merged_email' => $source->getEmail(),
            'gap_fills' => $gapFills,
            'at' => now()->toIso8601String(),
        ];
        $update[ContactDomainObjectAbstract::ATTRIBUTES_HISTORY] = $history;

        $update[ContactDomainObjectAbstract::PROCESSED_QUESTION_ANSWER_IDS] = array_values(array_unique(array_merge(
            $this->asArray($survivor->getProcessedQuestionAnswerIds()),
            $this->asArray($source->getProcessedQuestionAnswerIds()),
        )));
        $update[ContactDomainObjectAbstract::IGNORED_QUESTION_ANSWER_IDS] = array_values(array_unique(array_merge(
            $this->asArray($survivor->getIgnoredQuestionAnswerIds()),
            $this->asArray($source->getIgnoredQuestionAnswerIds()),
        )));

        return $update;
    }

    /**
     * @return array<mixed>
     */
    private function asArray(array|string|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
