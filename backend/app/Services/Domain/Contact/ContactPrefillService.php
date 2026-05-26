<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
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
                'questions.type as question_type',
                'questions.options as question_options',
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
            // A stored value that isn't a current option (typo, case mismatch,
            // retired label, freeform import) would later fail the checkout
            // validator on a hidden field — silent submit failure for that
            // buyer. Leave the question visible so they can pick a real value.
            if (!self::acceptsValue($row->question_type, $row->question_options, $value)) {
                continue;
            }
            $answers[(string) $row->question_id] = $value;
            $answeredIds[] = (int) $row->question_id;
        }

        return ['answers' => $answers, 'answered_ids' => $answeredIds];
    }

    /**
     * For predefined-choice questions (dropdown / radio / checkbox /
     * multi-select dropdown), the stored value must be present in the
     * question's option list. Mirrors QuestionDomainObject::isAnswerValid so
     * the prefill/autofill pair agrees with the checkout validator.
     *
     * $rawOptions is the raw `questions.options` column — jsonb returned as a
     * JSON string by DB::table queries (no Eloquent cast on this path), or
     * already-decoded array in some tests.
     */
    public static function acceptsValue(?string $questionType, mixed $rawOptions, mixed $value): bool
    {
        $predefinedChoice = [
            QuestionTypeEnum::DROPDOWN->name,
            QuestionTypeEnum::RADIO->name,
            QuestionTypeEnum::CHECKBOX->name,
            QuestionTypeEnum::MULTI_SELECT_DROPDOWN->name,
        ];
        if (!in_array($questionType, $predefinedChoice, true)) {
            return true;
        }

        $options = is_array($rawOptions)
            ? $rawOptions
            : (is_string($rawOptions) ? (json_decode($rawOptions, true) ?? []) : []);

        if (is_string($value)) {
            return in_array($value, $options, true);
        }
        if (is_array($value)) {
            return array_diff($value, $options) === [];
        }
        return false;
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
