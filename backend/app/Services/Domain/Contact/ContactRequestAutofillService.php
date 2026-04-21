<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mutates a CompleteOrder request payload pre-validation, filling blank
 * question responses for questions linked to contact attributes from the
 * matching contact's stored values.
 *
 * Needed because:
 *   - The frontend hides questions whose contact-linked attribute already
 *     has a value (so the user doesn't re-enter it).
 *   - Hidden questions still submit with empty responses.
 *   - The validator rejects required+empty before the order is created,
 *     which is when ContactAutofillService would normally fill the blanks.
 *
 * Running the fill BEFORE validation lets the request pass as if the user
 * had typed the values themselves, and ContactAutofillService then becomes
 * a no-op for these pre-filled questions.
 */
class ContactRequestAutofillService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    public function fillRequestInput(array $input, int $eventId): array
    {
        $event = DB::table('events')->where('id', $eventId)->first(['id', 'account_id']);
        if ($event === null) {
            return $input;
        }
        $accountId = (int) $event->account_id;

        $linked = DB::table('questions')
            ->join('contact_attribute_definitions', 'contact_attribute_definitions.id', '=', 'questions.contact_attribute_definition_id')
            ->where('questions.event_id', $eventId)
            ->whereNotNull('questions.contact_attribute_definition_id')
            ->whereNull('questions.deleted_at')
            ->where('contact_attribute_definitions.account_id', $accountId)
            ->whereNull('contact_attribute_definitions.deleted_at')
            ->select([
                'questions.id as question_id',
                'questions.belongs_to',
                'contact_attribute_definitions.name as attribute_name',
            ])
            ->get();

        if ($linked->isEmpty()) {
            return $input;
        }

        // Map: question_id => ['belongs_to' => string, 'attribute_name' => string]
        $questionMap = [];
        foreach ($linked as $row) {
            $questionMap[(int) $row->question_id] = [
                'belongs_to' => $row->belongs_to,
                'attribute_name' => $row->attribute_name,
            ];
        }

        $attrCache = [];
        $lookupAttrs = function (?string $email) use ($accountId, &$attrCache): array {
            if ($email === null || $email === '') {
                return [];
            }
            $key = strtolower($email);
            if (array_key_exists($key, $attrCache)) {
                return $attrCache[$key];
            }
            $contact = $this->contactRepository->findByEmailAndAccountId($email, $accountId);
            $attrs = $contact === null ? [] : ContactBackfillService::normalizeAttributes($contact->getAttributes());
            $attrCache[$key] = $attrs;
            return $attrs;
        };

        // Order-level
        $orderEmail = $input['order']['email'] ?? null;
        if (is_string($orderEmail) && $orderEmail !== '' && isset($input['order']['questions']) && is_array($input['order']['questions'])) {
            $attrs = $lookupAttrs($orderEmail);
            if (!empty($attrs)) {
                foreach ($input['order']['questions'] as $idx => $q) {
                    $qid = (int) ($q['question_id'] ?? 0);
                    if ($qid === 0 || !isset($questionMap[$qid])) continue;
                    if ($questionMap[$qid]['belongs_to'] !== QuestionBelongsTo::ORDER->name) continue;
                    if ($this->hasAnswer($q)) continue;
                    $value = $attrs[$questionMap[$qid]['attribute_name']] ?? null;
                    if ($value === null || $value === '') continue;
                    $input['order']['questions'][$idx]['response'] = ['answer' => $this->normalizeValue($value)];
                }
            }
        }

        // Product-level
        if (isset($input['products']) && is_array($input['products'])) {
            foreach ($input['products'] as $pIdx => $product) {
                $email = $product['email'] ?? null;
                if (!is_string($email) || $email === '') continue;
                if (!isset($product['questions']) || !is_array($product['questions'])) continue;
                $attrs = $lookupAttrs($email);
                if (empty($attrs)) continue;
                foreach ($product['questions'] as $qIdx => $q) {
                    $qid = (int) ($q['question_id'] ?? 0);
                    if ($qid === 0 || !isset($questionMap[$qid])) continue;
                    if ($questionMap[$qid]['belongs_to'] !== QuestionBelongsTo::PRODUCT->name) continue;
                    if ($this->hasAnswer($q)) continue;
                    $value = $attrs[$questionMap[$qid]['attribute_name']] ?? null;
                    if ($value === null || $value === '') continue;
                    $input['products'][$pIdx]['questions'][$qIdx]['response'] = ['answer' => $this->normalizeValue($value)];
                }
            }
        }

        return $input;
    }

    private function hasAnswer(array $question): bool
    {
        $response = $question['response'] ?? null;
        if (!is_array($response) || empty($response)) return false;
        $answer = $response['answer'] ?? null;
        if (is_string($answer)) return trim($answer) !== '';
        if (is_array($answer)) return !empty(array_filter($answer, fn ($v) => $v !== null && $v !== ''));
        return $answer !== null;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        return (string) $value;
    }
}
