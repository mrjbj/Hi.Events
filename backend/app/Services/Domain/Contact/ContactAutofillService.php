<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactAutofillService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly QuestionAnswerRepositoryInterface $questionAnswerRepository,
    ) {}

    /**
     * For each question on the event that is linked to a contact attribute definition,
     * create a question_answer record from the matching contact's stored attribute
     * when the client did not already supply one. Never overwrites a client-provided
     * answer — it only fills blanks.
     *
     * ORDER-level questions resolve against the buyer's email (orders.email).
     * PRODUCT-level questions resolve per-attendee against attendees.email.
     */
    public function fillOrderBlanksFromContact(int $orderId, int $eventId, int $accountId): int
    {
        $linkedQuestions = DB::table('questions')
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

        if ($linkedQuestions->isEmpty()) {
            return 0;
        }

        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null) {
            return 0;
        }

        $attendees = DB::table('attendees')
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->select('id', 'email', 'product_id')
            ->get();

        $existingSet = DB::table('question_answers')
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->get(['question_id', 'attendee_id'])
            ->mapWithKeys(fn ($row) => [($row->attendee_id ?? '') . ':' . $row->question_id => true])
            ->toArray();

        $attributesCache = [];
        $getAttributes = function (?string $email) use ($accountId, &$attributesCache): array {
            if ($email === null || $email === '') {
                return [];
            }
            $key = strtolower($email);
            if (array_key_exists($key, $attributesCache)) {
                return $attributesCache[$key];
            }
            $contact = $this->contactRepository->findByEmailAndAccountId($email, $accountId);
            $attrs = $contact === null ? [] : ContactBackfillService::normalizeAttributes($contact->getAttributes());
            $attributesCache[$key] = $attrs;

            return $attrs;
        };

        $created = 0;
        foreach ($linkedQuestions as $q) {
            if ($q->belongs_to === QuestionBelongsTo::ORDER->name) {
                if (isset($existingSet[':' . $q->question_id])) {
                    continue;
                }
                $attributes = $getAttributes($order->email);
                $value = $attributes[$q->attribute_name] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $this->createAnswer($q->question_id, $value, $orderId, null, null);
                $created++;
                continue;
            }

            if ($q->belongs_to !== QuestionBelongsTo::PRODUCT->name) {
                continue;
            }

            foreach ($attendees as $attendee) {
                $key = $attendee->id . ':' . $q->question_id;
                if (isset($existingSet[$key])) {
                    continue;
                }
                $attributes = $getAttributes($attendee->email);
                $value = $attributes[$q->attribute_name] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $this->createAnswer($q->question_id, $value, $orderId, $attendee->product_id, $attendee->id);
                $created++;
            }
        }

        return $created;
    }

    private function createAnswer(int $questionId, mixed $value, int $orderId, ?int $productId, ?int $attendeeId): void
    {
        try {
            $this->questionAnswerRepository->create([
                'question_id' => $questionId,
                'answer' => is_array($value) ? json_encode($value) : (string) $value,
                'order_id' => $orderId,
                'product_id' => $productId,
                'attendee_id' => $attendeeId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ContactAutofillService: failed to insert autofilled answer', [
                'order_id' => $orderId,
                'question_id' => $questionId,
                'attendee_id' => $attendeeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
