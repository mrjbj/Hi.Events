<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use Illuminate\Support\Facades\DB;

/**
 * Read-only helper that turns a contact's stored attributes into the shape
 * the checkout frontend needs: a map of question_id → answer value (for
 * token-proven prefill) and a list of question_ids the contact has answered
 * (for the hide-if-answered logic, safe to return even from the public
 * name-only lookup because it leaks only question membership, not values).
 *
 * The ContactAutofillService handles the equivalent WRITE path (inserting
 * question_answers on order submission). This service is read-only.
 */
class ContactPrefillService
{
    /**
     * Return ['answers' => ['qid_as_string' => mixed, ...], 'answered_ids' => [qid, ...]].
     *
     * $attributes should already be normalized via ContactBackfillService::normalizeAttributes.
     *
     * @return array{answers: array<string, mixed>, answered_ids: int[]}
     */
    public function resolveForEvent(int $accountId, int $eventId, array $attributes): array
    {
        $empty = ['answers' => [], 'answered_ids' => []];
        if (empty($attributes)) {
            return $empty;
        }

        $linkedQuestions = DB::table('questions')
            ->join('contact_attribute_definitions', 'contact_attribute_definitions.id', '=', 'questions.contact_attribute_definition_id')
            ->where('questions.event_id', $eventId)
            ->whereNotNull('questions.contact_attribute_definition_id')
            ->whereNull('questions.deleted_at')
            ->where('contact_attribute_definitions.account_id', $accountId)
            ->whereNull('contact_attribute_definitions.deleted_at')
            ->select([
                'questions.id as question_id',
                'contact_attribute_definitions.name as attribute_name',
            ])
            ->get();

        if ($linkedQuestions->isEmpty()) {
            return $empty;
        }

        $answers = [];
        $answeredIds = [];
        foreach ($linkedQuestions as $row) {
            $name = $row->attribute_name;
            if (!array_key_exists($name, $attributes)) {
                continue;
            }
            $value = $attributes[$name];
            if ($value === null || $value === '') {
                continue;
            }
            $answers[(string) $row->question_id] = $value;
            $answeredIds[] = (int) $row->question_id;
        }

        return ['answers' => $answers, 'answered_ids' => $answeredIds];
    }

    public function resolveForContact(ContactDomainObject $contact, int $eventId): array
    {
        return $this->resolveForEvent(
            accountId: $contact->getAccountId(),
            eventId: $eventId,
            attributes: ContactBackfillService::normalizeAttributes($contact->getAttributes()),
        );
    }
}
