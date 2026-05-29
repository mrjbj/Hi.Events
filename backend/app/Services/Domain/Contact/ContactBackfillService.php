<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactBackfillService
{
    public function __construct(
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactUpsertService $contactUpsertService,
    ) {}

    private function loadExistingContactAttributesByEmail(int $accountId): array
    {
        $rows = DB::table('contacts')
            ->select('email', 'attributes')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $attrs = self::normalizeAttributes($row->attributes);
            $out[strtolower($row->email)] = $attrs;
        }

        return $out;
    }

    /**
     * @return array<string, array<int, true>> Map of lowercase email → set of question_answer ids already evaluated.
     */
    private function loadProcessedQaIdsByContactEmail(int $accountId): array
    {
        $rows = DB::table('contacts')
            ->select('email', 'processed_question_answer_ids')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $ids = self::decodeJsonArrayOrEmpty($row->processed_question_answer_ids);
            $map[strtolower($row->email)] = array_flip(array_map('intval', $ids));
        }

        return $map;
    }

    /**
     * @return array<string, array<int, string>> Map of lowercase email → {qa_id → iso8601 timestamp} for QAs whose
     *                                           "Update" decision wrote a value to the contact. Derived from each contact's attributes_history entries.
     */
    private function loadUpdateTimestampsByContactEmail(int $accountId): array
    {
        $rows = DB::table('contacts')
            ->select('email', 'attributes_history')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            // Legacy data: a few prod rows (e.g. ones written by an older
            // email-change flow) have a double-encoded JSON string here, so a
            // single decode still returns a string and the foreach blows up.
            // Use the shared array-safe decoder so we degrade gracefully
            // instead of 500-ing the whole Sync tab.
            $history = self::decodeJsonArrayOrEmpty($row->attributes_history);
            $perQa = [];
            foreach ($history as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $at = $entry['changed_at'] ?? null;
                if ($at === null) {
                    continue;
                }
                $sourceIds = $entry['source_question_answer_ids'] ?? [];
                foreach ($sourceIds as $qaId) {
                    $perQa[(int) $qaId] = $at;
                }
            }
            $map[strtolower($row->email)] = $perQa;
        }

        return $map;
    }

    /**
     * @return array<string, array<int, true>> Map of lowercase email → set of question_answer ids marked "Ignore".
     */
    private function loadIgnoredQaIdsByContactEmail(int $accountId): array
    {
        $rows = DB::table('contacts')
            ->select('email', 'ignored_question_answer_ids')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $ids = self::decodeJsonArrayOrEmpty($row->ignored_question_answer_ids);
            $map[strtolower($row->email)] = array_flip(array_map('intval', $ids));
        }

        return $map;
    }

    private function walkLinkedAnswers(int $accountId, callable $consumer): void
    {
        DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->join('orders', 'orders.id', '=', 'question_answers.order_id')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'question_answers.attendee_id')
            ->leftJoin('contact_attribute_definitions', 'contact_attribute_definitions.id', '=', 'questions.contact_attribute_definition_id')
            ->whereNotNull('questions.contact_attribute_definition_id')
            ->whereNull('question_answers.deleted_at')
            ->where('events.account_id', $accountId)
            ->orderBy('question_answers.created_at')
            ->select([
                'question_answers.id as answer_id',
                'question_answers.order_id',
                'question_answers.attendee_id',
                'question_answers.answer',
                'question_answers.created_at as answer_created_at',
                'contact_attribute_definitions.name as definition_name',
                'contact_attribute_definitions.type as definition_type',
                'contact_attribute_definitions.options as definition_options',
                'attendees.email as attendee_email',
                'attendees.first_name as attendee_first_name',
                'attendees.last_name as attendee_last_name',
                'orders.email as buyer_email',
                'orders.first_name as buyer_first_name',
                'orders.last_name as buyer_last_name',
                'events.id as event_id',
                'events.title as event_title',
            ])
            ->cursor()
            ->each(fn ($row) => $consumer((array) $row));
    }

    /**
     * Phase A only, for a specific set of attendee ids. Each attendee is resolved to a contact
     * (find-or-create by email + account) and the attendee.contact_id is set.
     *
     * @param  int[]  $attendeeIds
     * @return int Number of attendees linked.
     */
    public function linkAttendeesById(int $accountId, array $attendeeIds): int
    {
        if (empty($attendeeIds)) {
            return 0;
        }

        $linked = 0;

        $rows = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->whereIn('attendees.id', $attendeeIds)
            ->whereNull('attendees.contact_id')
            ->whereNull('attendees.deleted_at')
            ->whereNull('attendees.contact_link_ignored_at')
            // Never promote a guest still flagged for check-in confirmation.
            ->where('attendees.confirm_at_checkin', false)
            ->where('events.account_id', $accountId)
            ->select('attendees.id', 'attendees.email', 'attendees.first_name', 'attendees.last_name')
            ->get();

        foreach ($rows as $row) {
            try {
                $contact = $this->contactUpsertService->findOrCreateContact(
                    accountId: $accountId,
                    email: $row->email,
                    firstName: $row->first_name,
                    lastName: $row->last_name,
                );
                $this->attendeeRepository->updateFromArray((int) $row->id, [
                    AttendeeDomainObjectAbstract::CONTACT_ID => $contact->getId(),
                ]);
                $linked++;
            } catch (\Throwable $e) {
                Log::error('Contact backfill: failed to link attendee (bulk add)', [
                    'attendee_id' => $row->id,
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $linked;
    }

    /**
     * Apply a set of per-conflict decisions. Each decision is either 'update' (overwrite the
     * contact attribute with the event answer) or 'ignore' (keep existing; mark as no-update).
     * Both outcomes append the QA id to contacts.processed_question_answer_ids so the conflict
     * does not reappear on subsequent previews. 'ignore' also appends to ignored_question_answer_ids
     * so the UI can distinguish Updated vs No Update when showing processed rows. 'update' removes
     * the id from ignored_question_answer_ids if it was previously ignored.
     *
     * @param  array<array{question_answer_id:int,decision:string}>  $decisions
     * @return int Number of decisions processed.
     */
    public function applyConflictDecisions(int $accountId, array $decisions, int $changedByUserId): int
    {
        if (empty($decisions)) {
            return 0;
        }

        $decisionMap = [];
        foreach ($decisions as $d) {
            $decisionMap[(int) $d['question_answer_id']] = $d['decision'];
        }
        $qaIds = array_keys($decisionMap);

        $rows = DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->join('orders', 'orders.id', '=', 'question_answers.order_id')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'question_answers.attendee_id')
            ->leftJoin('contact_attribute_definitions', 'contact_attribute_definitions.id', '=', 'questions.contact_attribute_definition_id')
            ->whereIn('question_answers.id', $qaIds)
            ->whereNotNull('questions.contact_attribute_definition_id')
            ->whereNull('question_answers.deleted_at')
            ->where('events.account_id', $accountId)
            ->select([
                'question_answers.id as answer_id',
                'question_answers.order_id',
                'question_answers.attendee_id',
                'question_answers.answer',
                'contact_attribute_definitions.name as definition_name',
                'attendees.email as attendee_email',
                'attendees.first_name as attendee_first_name',
                'attendees.last_name as attendee_last_name',
                'orders.email as buyer_email',
                'orders.first_name as buyer_first_name',
                'orders.last_name as buyer_last_name',
            ])
            ->get();

        $pendingByContactId = [];
        $qaIdsByContactId = [];
        $ignoredAddByContactId = [];
        $ignoredRemoveByContactId = [];
        $processed = 0;

        foreach ($rows as $row) {
            $rowArr = (array) $row;
            $contactEmail = self::resolveContactEmail($rowArr);
            if ($contactEmail === null) {
                continue;
            }
            $decision = $decisionMap[(int) $rowArr['answer_id']] ?? 'ignore';

            $contact = $this->contactUpsertService->findOrCreateContact(
                accountId: $accountId,
                email: $contactEmail,
                firstName: $rowArr['buyer_first_name'] ?? null,
                lastName: $rowArr['buyer_last_name'] ?? null,
            );

            $contactId = $contact->getId();
            $qaId = (int) $rowArr['answer_id'];
            $qaIdsByContactId[$contactId][] = $qaId;

            if ($decision === 'update') {
                $proposed = self::decodeAnswer($rowArr['answer']);
                $pendingByContactId[$contactId][$rowArr['definition_name']] = $proposed;
                $ignoredRemoveByContactId[$contactId][] = $qaId;
            } else {
                $ignoredAddByContactId[$contactId][] = $qaId;
            }

            $processed++;
        }

        $contactIds = array_unique(array_merge(
            array_keys($pendingByContactId),
            array_keys($qaIdsByContactId),
            array_keys($ignoredAddByContactId),
            array_keys($ignoredRemoveByContactId),
        ));
        foreach ($contactIds as $contactId) {
            $attributes = $pendingByContactId[$contactId] ?? [];
            $qaIds = $qaIdsByContactId[$contactId] ?? [];
            $ignoredAdd = $ignoredAddByContactId[$contactId] ?? [];
            $ignoredRemove = $ignoredRemoveByContactId[$contactId] ?? [];
            if (empty($attributes) && empty($qaIds) && empty($ignoredAdd) && empty($ignoredRemove)) {
                continue;
            }
            $contact = $this->contactRepository->findById($contactId);
            $this->contactUpsertService->updateContactAttributes(
                contact: $contact,
                newAttributes: $attributes,
                changedByUserId: $changedByUserId,
                sourceQuestionAnswerIds: $qaIds,
                addedIgnoredQuestionAnswerIds: $ignoredAdd,
                removedIgnoredQuestionAnswerIds: $ignoredRemove,
            );
        }

        return $processed;
    }

    public function applyOrderAnswers(int $orderId, int $accountId, int $changedByUserId): int
    {
        $rows = DB::table('question_answers')
            ->join('questions', 'questions.id', '=', 'question_answers.question_id')
            ->join('orders', 'orders.id', '=', 'question_answers.order_id')
            ->leftJoin('attendees', 'attendees.id', '=', 'question_answers.attendee_id')
            ->leftJoin('contact_attribute_definitions', 'contact_attribute_definitions.id', '=', 'questions.contact_attribute_definition_id')
            ->where('question_answers.order_id', $orderId)
            ->whereNotNull('questions.contact_attribute_definition_id')
            ->whereNull('question_answers.deleted_at')
            ->select([
                'question_answers.id as answer_id',
                'question_answers.order_id',
                'question_answers.attendee_id',
                'question_answers.answer',
                'contact_attribute_definitions.name as definition_name',
                'attendees.email as attendee_email',
                'attendees.first_name as attendee_first_name',
                'attendees.last_name as attendee_last_name',
                'orders.email as buyer_email',
                'orders.first_name as buyer_first_name',
                'orders.last_name as buyer_last_name',
            ])
            ->get();

        $pendingByContactId = [];
        $qaIdsByContactId = [];
        $writtenCount = 0;

        foreach ($rows as $row) {
            $rowArr = (array) $row;
            $contactEmail = self::resolveContactEmail($rowArr);
            if ($contactEmail === null) {
                continue;
            }

            $contact = $this->contactUpsertService->findOrCreateContact(
                accountId: $accountId,
                email: $contactEmail,
                firstName: $rowArr['buyer_first_name'] ?? null,
                lastName: $rowArr['buyer_last_name'] ?? null,
            );

            $contactId = $contact->getId();
            $attributeName = $rowArr['definition_name'];
            $proposed = self::decodeAnswer($rowArr['answer']);
            $currentAttributes = self::normalizeAttributes($contact->getAttributes());
            $pending = $pendingByContactId[$contactId] ?? [];
            $effective = array_merge($currentAttributes, $pending);
            $existing = $effective[$attributeName] ?? null;

            if ($existing === null) {
                $pending[$attributeName] = $proposed;
                $qaIdsByContactId[$contactId][] = (int) $rowArr['answer_id'];
                $writtenCount++;
            } elseif ($existing === $proposed) {
                $qaIdsByContactId[$contactId][] = (int) $rowArr['answer_id'];
            }

            $pendingByContactId[$contactId] = $pending;
        }

        $contactIds = array_unique(array_merge(array_keys($pendingByContactId), array_keys($qaIdsByContactId)));
        foreach ($contactIds as $contactId) {
            $attributes = $pendingByContactId[$contactId] ?? [];
            $qaIds = $qaIdsByContactId[$contactId] ?? [];
            if (empty($attributes) && empty($qaIds)) {
                continue;
            }
            $contact = $this->contactRepository->findById($contactId);
            $this->contactUpsertService->updateContactAttributes(
                contact: $contact,
                newAttributes: $attributes,
                changedByUserId: $changedByUserId,
                sourceQuestionAnswerIds: $qaIds,
            );
        }

        return $writtenCount;
    }

    public static function resolveContactEmail(array $row): ?string
    {
        if (($row['attendee_id'] ?? null) !== null) {
            return $row['attendee_email'] ?? null;
        }

        return $row['buyer_email'] ?? null;
    }

    public static function normalizeAttributes(mixed $attributes): array
    {
        return self::decodeJsonArrayOrEmpty($attributes);
    }

    /**
     * Decode a JSON column value and guarantee an array back, returning [] for
     * anything that's not actually an array after decoding. Catches: nulls,
     * scalars, malformed JSON, AND double-encoded values (a JSON string of a
     * JSON string — single decode yields the inner string, not an array).
     *
     * Used wherever we read attributes_history / processed_question_answer_ids
     * / ignored_question_answer_ids / attributes from raw DB rows, so a single
     * bad legacy row can't 500 the whole endpoint.
     */
    public static function decodeJsonArrayOrEmpty(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function decodeAnswer(mixed $answer): mixed
    {
        if (! is_string($answer)) {
            return $answer;
        }

        $trimmed = trim($answer);
        if ($trimmed === '') {
            return $answer;
        }

        $first = $trimmed[0];
        if ($first !== '[' && $first !== '"' && $first !== '{') {
            return $answer;
        }

        $decoded = json_decode($answer, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $answer;
    }

    /**
     * Scan every contact for select/multi_select attribute values that aren't in the
     * attribute definition's current option list (option list edited since, typo from
     * import, case mismatch, retired label). Each (contact, attribute) stale pair is
     * one row.
     *
     * Why this matters: a stale value is silently invalidated by the checkout prefill
     * (since the validity-aware fix shipped 2026-05-26) and silently rendered blank
     * in the contact edit modal — both surfaces hide the data from admins, so the
     * stale rows accumulate and the buyer's order keeps failing. This sub-tab is
     * the remediation surface.
     */
    public function getStaleValues(int $accountId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $rows = $this->collectStaleValueRows($accountId);

        if ($params->query !== null && $params->query !== '') {
            $needle = strtolower($params->query);
            $rows = array_values(array_filter($rows, function (array $r) use ($needle) {
                $haystack = strtolower(
                    ($r['contact_email'] ?? '')
                    .' '.($r['attribute_label'] ?? '')
                    .' '.($r['attribute_name'] ?? '')
                    .' '.(is_array($r['current_value']) ? implode(' ', $r['current_value']) : (string) $r['current_value'])
                );

                return str_contains($haystack, $needle);
            }));
        }

        $sortable = ['contact_email', 'attribute_name', 'attribute_label'];
        $sortBy = in_array($params->sort_by, $sortable, true) ? $params->sort_by : 'contact_email';
        $sortDir = strtolower($params->sort_direction ?? 'asc') === 'desc' ? 'desc' : 'asc';
        usort($rows, function (array $a, array $b) use ($sortBy, $sortDir) {
            $cmp = strcmp((string) ($a[$sortBy] ?? ''), (string) ($b[$sortBy] ?? ''));

            return $sortDir === 'desc' ? -$cmp : $cmp;
        });

        $perPage = max(1, (int) ($params->per_page ?? 25));
        $page = max(1, (int) ($params->page ?? 1));
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator($slice, $total, $perPage, $page);
    }

    /**
     * Apply per-row remaps. Each remap names a contact + attribute and EITHER
     *   - a new_value to write (a valid option string, an array for multi_select,
     *     or null/empty to clear), OR
     *   - an add_values_to_options list to extend the attribute definition's
     *     option list with one or more stale values (so they become valid as-is,
     *     no contact write needed), OR
     *   - both (rare, but a frontend may pick "Add" AND set a value).
     *
     * Option-list extensions are applied first, atomically per definition, and
     * deduplicated across remaps — picking "+ Add 'cheerokee'" on 5 rows that
     * all reference the same County attribute only adds the value once.
     *
     * Writes go through ContactUpsertService so attribute history is recorded.
     *
     * @param  array<array{contact_id:int,attribute_name:string,new_value?:mixed,add_values_to_options?:string[]}>  $remaps
     * @return array{attributes_written:int,options_added:int}
     */
    public function applyStaleValueRemaps(int $accountId, array $remaps, int $changedByUserId): array
    {
        if (empty($remaps)) {
            return ['attributes_written' => 0, 'options_added' => 0];
        }

        $optionsAdded = $this->extendDefinitionOptionsFromRemaps($accountId, $remaps);

        // Bucket attribute writes by contact so each contact is updated in one
        // go (one history entry).
        $byContact = [];
        foreach ($remaps as $r) {
            $contactId = (int) ($r['contact_id'] ?? 0);
            $name = (string) ($r['attribute_name'] ?? '');
            if ($contactId <= 0 || $name === '') {
                continue;
            }
            // Skip remaps that don't carry a new_value — those were pure
            // option-list extensions handled above; the contact's existing value
            // is now valid as-is and doesn't need rewriting.
            if (!array_key_exists('new_value', $r)) {
                continue;
            }
            $value = $r['new_value'];
            if ($value === '' || $value === []) {
                $value = null;
            }
            $byContact[$contactId][$name] = $value;
        }

        // Load definitions AFTER the option-list extension so validation sees
        // any newly-added options.
        $defs = DB::table('contact_attribute_definitions')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->select(['name', 'type', 'options'])
            ->get()
            ->keyBy('name');

        $applied = 0;
        foreach ($byContact as $contactId => $attrs) {
            $contact = $this->contactRepository->findById($contactId);
            if ($contact === null) {
                continue;
            }
            if ((int) $contact->getAccountId() !== $accountId) {
                continue;
            }

            $valid = [];
            foreach ($attrs as $name => $value) {
                $def = $defs->get($name);
                if ($def === null) {
                    continue;
                }
                if ($value === null) {
                    $valid[$name] = null;
                    continue;
                }
                $options = is_string($def->options) ? (json_decode($def->options, true) ?? []) : [];
                if ($def->type === 'select' && is_string($value)) {
                    if (!in_array($value, $options, true)) {
                        continue;
                    }
                    $valid[$name] = $value;
                } elseif ($def->type === 'multi_select') {
                    $arr = is_array($value) ? $value : [$value];
                    $rejected = array_diff($arr, $options);
                    if (!empty($rejected)) {
                        continue;
                    }
                    $valid[$name] = array_values($arr);
                } else {
                    // text / phone / date / etc. — no option list to validate against.
                    $valid[$name] = $value;
                }
            }

            if (empty($valid)) {
                continue;
            }

            $this->contactUpsertService->updateContactAttributes(
                contact: $contact,
                newAttributes: $valid,
                changedByUserId: $changedByUserId,
            );
            $applied += count($valid);
        }

        return ['attributes_written' => $applied, 'options_added' => $optionsAdded];
    }

    /**
     * First pass of applyStaleValueRemaps: collect every requested
     * add_values_to_options across remaps, group by attribute_name, dedupe,
     * and write the extensions back to the definition rows. Returns the count
     * of options actually added (skipping ones already present).
     *
     * @param  array<array{attribute_name:string,add_values_to_options?:string[]}>  $remaps
     */
    private function extendDefinitionOptionsFromRemaps(int $accountId, array $remaps): int
    {
        $perDef = [];
        foreach ($remaps as $r) {
            $name = (string) ($r['attribute_name'] ?? '');
            $toAdd = $r['add_values_to_options'] ?? null;
            if ($name === '' || !is_array($toAdd) || empty($toAdd)) {
                continue;
            }
            foreach ($toAdd as $v) {
                if (!is_string($v) || $v === '') {
                    continue;
                }
                $perDef[$name][$v] = true;
            }
        }
        if (empty($perDef)) {
            return 0;
        }

        $defs = DB::table('contact_attribute_definitions')
            ->where('account_id', $accountId)
            ->whereIn('name', array_keys($perDef))
            ->whereNull('deleted_at')
            ->whereIn('type', ['select', 'multi_select'])
            ->select(['id', 'name', 'options'])
            ->get();

        $added = 0;
        foreach ($defs as $def) {
            $current = self::decodeJsonArrayOrEmpty($def->options);
            $current = array_values(array_filter($current, 'is_string'));
            $merged = $current;
            foreach (array_keys($perDef[$def->name] ?? []) as $candidate) {
                if (!in_array($candidate, $merged, true)) {
                    $merged[] = $candidate;
                    $added++;
                }
            }
            if ($merged === $current) {
                continue;
            }
            DB::table('contact_attribute_definitions')
                ->where('id', $def->id)
                ->update([
                    'options' => json_encode(array_values($merged)),
                    'updated_at' => now(),
                ]);
        }

        return $added;
    }

    /**
     * Shared by getStaleValues and getSummaryCounts. Returns one synthetic row per
     * (contact, attribute) where the stored value isn't in the definition's options.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectStaleValueRows(int $accountId): array
    {
        $defs = DB::table('contact_attribute_definitions')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('type', ['select', 'multi_select'])
            ->select(['id', 'name', 'label', 'type', 'options'])
            ->get();

        if ($defs->isEmpty()) {
            return [];
        }

        $defsByName = [];
        foreach ($defs as $def) {
            $options = is_string($def->options) ? (json_decode($def->options, true) ?? []) : [];
            $defsByName[$def->name] = [
                'label' => $def->label,
                'type' => $def->type,
                'options' => is_array($options) ? $options : [],
            ];
        }

        $contacts = DB::table('contacts')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->select(['id', 'email', 'first_name', 'last_name', 'attributes'])
            ->get();

        $rows = [];
        foreach ($contacts as $contact) {
            $attrs = self::normalizeAttributes($contact->attributes);
            foreach ($defsByName as $name => $def) {
                if (!array_key_exists($name, $attrs)) {
                    continue;
                }
                $value = $attrs[$name];
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                $options = $def['options'];
                $invalid = [];
                if ($def['type'] === 'select') {
                    if (!is_string($value) || !in_array($value, $options, true)) {
                        $invalid = is_array($value) ? $value : [(string) $value];
                    }
                } else { // multi_select
                    $arr = is_array($value) ? $value : [$value];
                    foreach ($arr as $v) {
                        if (!in_array($v, $options, true)) {
                            $invalid[] = $v;
                        }
                    }
                }
                if (empty($invalid)) {
                    continue;
                }

                // Synthetic id makes per-row selection in the frontend easier.
                $rows[] = [
                    'id' => (int) $contact->id.'_'.$name,
                    'contact_id' => (int) $contact->id,
                    'contact_email' => $contact->email,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'attribute_name' => $name,
                    'attribute_label' => $def['label'],
                    'attribute_type' => $def['type'],
                    'current_value' => $value,
                    'invalid_values' => array_values($invalid),
                    'options' => array_values($options),
                ];
            }
        }

        return $rows;
    }

    public function getSummaryCounts(int $accountId): array
    {
        $unlinkedAttendees = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->whereNull('attendees.contact_id')
            ->whereNull('attendees.deleted_at')
            ->whereNull('attendees.contact_link_ignored_at')
            ->where('events.account_id', $accountId)
            ->count();

        $unmappedQuestions = DB::table('questions')
            ->join('events', 'events.id', '=', 'questions.event_id')
            ->join('question_answers', 'question_answers.question_id', '=', 'questions.id')
            ->whereNull('questions.contact_attribute_definition_id')
            ->whereNull('questions.deleted_at')
            ->whereNull('questions.contact_link_ignored_at')
            ->whereNull('question_answers.deleted_at')
            ->where('events.account_id', $accountId)
            ->distinct('questions.id')
            ->count('questions.id');

        $conflictsTotal = $this->getConflicts(
            $accountId,
            QueryParamsDTO::fromArray(['per_page' => 1, 'page' => 1]),
            includeProcessed: false,
        )->total();

        $staleValuesTotal = count($this->collectStaleValueRows($accountId));

        return [
            'unlinked_attendees_count' => (int) $unlinkedAttendees,
            'unmapped_questions_count' => (int) $unmappedQuestions,
            'conflicts_count' => (int) $conflictsTotal,
            'stale_values_count' => (int) $staleValuesTotal,
        ];
    }

    public function getUnlinkedAttendees(int $accountId, QueryParamsDTO $params, bool $includeProcessed = false): LengthAwarePaginator
    {
        $query = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->whereNull('attendees.deleted_at')
            ->where('events.account_id', $accountId)
            ->select(
                'attendees.id',
                'attendees.email',
                'attendees.first_name',
                'attendees.last_name',
                'attendees.event_id',
                'events.title as event_title',
                'attendees.created_at',
                'attendees.contact_id',
                'attendees.contact_link_ignored_at',
                'attendees.confirm_at_checkin',
            );

        if (! $includeProcessed) {
            $query->whereNull('attendees.contact_id');
            $query->whereNull('attendees.contact_link_ignored_at');
        }

        if ($params->query !== null && $params->query !== '') {
            $needle = '%'.$params->query.'%';
            $query->where(function ($q) use ($needle) {
                $q->where('attendees.email', 'ilike', $needle)
                    ->orWhere('attendees.first_name', 'ilike', $needle)
                    ->orWhere('attendees.last_name', 'ilike', $needle);
            });
        }

        foreach ($params->filter_fields ?? [] as $filter) {
            if ($filter->field === 'event_id' && $filter->value) {
                $query->where('attendees.event_id', $filter->value);
            }
        }

        $sortableColumns = [
            'email' => 'attendees.email',
            'first_name' => 'attendees.first_name',
            'last_name' => 'attendees.last_name',
            'event_title' => 'events.title',
            'created_at' => 'attendees.created_at',
        ];
        $sortColumn = $sortableColumns[$params->sort_by] ?? 'attendees.id';
        $sortDirection = strtolower($params->sort_direction ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumn, $sortDirection);

        $paginator = $query->paginate($params->per_page ?: 25, ['*'], 'page', $params->page ?: 1);

        foreach ($paginator->items() as $item) {
            if ($item->contact_id !== null) {
                $item->status = 'added';
            } elseif ($item->contact_link_ignored_at !== null) {
                $item->status = 'ignored';
            } else {
                $item->status = null;
            }
        }

        return $paginator;
    }

    public function getUnmappedQuestions(int $accountId, QueryParamsDTO $params, bool $includeProcessed = false): LengthAwarePaginator
    {
        $query = DB::table('questions')
            ->join('events', 'events.id', '=', 'questions.event_id')
            ->join('question_answers', 'question_answers.question_id', '=', 'questions.id')
            ->whereNull('questions.deleted_at')
            ->whereNull('question_answers.deleted_at')
            ->where('events.account_id', $accountId)
            ->groupBy('questions.id', 'questions.title', 'questions.event_id', 'events.title', 'questions.contact_attribute_definition_id', 'questions.contact_link_ignored_at')
            ->select(
                'questions.id as question_id',
                'questions.title',
                'questions.event_id',
                'events.title as event_title',
                'questions.contact_attribute_definition_id',
                'questions.contact_link_ignored_at',
                DB::raw('COUNT(question_answers.id) as answer_count'),
            );

        if (! $includeProcessed) {
            $query->whereNull('questions.contact_attribute_definition_id');
            $query->whereNull('questions.contact_link_ignored_at');
        }

        if ($params->query !== null && $params->query !== '') {
            $query->where('questions.title', 'ilike', '%'.$params->query.'%');
        }

        foreach ($params->filter_fields ?? [] as $filter) {
            if ($filter->field === 'event_id' && $filter->value) {
                $query->where('questions.event_id', $filter->value);
            }
        }

        $sortableColumns = [
            'title' => 'questions.title',
            'event_title' => 'events.title',
        ];
        $sortColumn = $sortableColumns[$params->sort_by] ?? 'questions.id';
        $sortDirection = strtolower($params->sort_direction ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortColumn, $sortDirection);

        $paginator = $query->paginate($params->per_page ?: 25, ['*'], 'page', $params->page ?: 1);

        $questionIds = array_map(fn ($row) => (int) $row->question_id, $paginator->items());
        if (! empty($questionIds)) {
            $samples = $this->loadSampleAnswersByQuestion($questionIds);
            foreach ($paginator->items() as $item) {
                $item->sample_answers = $samples[(int) $item->question_id] ?? [];
            }
        }

        foreach ($paginator->items() as $item) {
            if ($item->contact_attribute_definition_id !== null) {
                $item->status = 'reused';
            } elseif ($item->contact_link_ignored_at !== null) {
                $item->status = 'ignored';
            } else {
                $item->status = null;
            }
        }

        return $paginator;
    }

    /**
     * Given a set of question ids, return the top 3 most common distinct answer values per question.
     * Used by the "New Questions" sub-tab as a Sample Answers column.
     *
     * @param  int[]  $questionIds
     * @return array<int, string[]> Map of question_id → top 3 display strings, ordered by frequency desc.
     */
    private function loadSampleAnswersByQuestion(array $questionIds): array
    {
        $rows = DB::table('question_answers')
            ->whereIn('question_id', $questionIds)
            ->whereNull('deleted_at')
            ->select('question_id', 'answer')
            ->get();

        $countsByQid = [];
        foreach ($rows as $row) {
            $qid = (int) $row->question_id;
            $decoded = self::decodeAnswer($row->answer);
            $display = is_array($decoded) ? implode(', ', array_map('strval', $decoded)) : (string) $decoded;
            $display = trim($display);
            if ($display === '') {
                continue;
            }
            $countsByQid[$qid][$display] = ($countsByQid[$qid][$display] ?? 0) + 1;
        }

        $out = [];
        foreach ($countsByQid as $qid => $counts) {
            arsort($counts);
            $out[$qid] = array_slice(array_keys($counts), 0, 3);
        }

        return $out;
    }

    public function getConflicts(
        int $accountId,
        QueryParamsDTO $params,
        bool $includeProcessed = false,
    ): LengthAwarePaginator {
        $emailToContactAttributes = $this->loadExistingContactAttributesByEmail($accountId);
        $processedByEmail = $this->loadProcessedQaIdsByContactEmail($accountId);
        $ignoredByEmail = $this->loadIgnoredQaIdsByContactEmail($accountId);
        $updateTimestampsByEmail = $this->loadUpdateTimestampsByContactEmail($accountId);
        $conflicts = [];

        $eventFilterId = null;
        foreach ($params->filter_fields ?? [] as $filter) {
            if ($filter->field === 'event_id' && $filter->value) {
                $eventFilterId = (int) $filter->value;
            }
        }

        $this->walkLinkedAnswers($accountId, function (array $row) use (
            &$conflicts,
            &$emailToContactAttributes,
            &$processedByEmail,
            &$ignoredByEmail,
            &$updateTimestampsByEmail,
            $includeProcessed,
            $eventFilterId,
        ) {
            $contactEmail = self::resolveContactEmail($row);
            if ($contactEmail === null) {
                return;
            }

            $emailKey = strtolower($contactEmail);
            $answerId = (int) $row['answer_id'];
            $isProcessed = isset($processedByEmail[$emailKey][$answerId]);
            $isIgnored = isset($ignoredByEmail[$emailKey][$answerId]);

            if ($isProcessed && ! $includeProcessed) {
                return;
            }

            if (! isset($emailToContactAttributes[$emailKey])) {
                return;
            }

            $attributeName = $row['definition_name'];
            $proposed = self::decodeAnswer($row['answer']);
            $current = $emailToContactAttributes[$emailKey][$attributeName] ?? null;

            if (! $isProcessed && $current === $proposed) {
                return;
            }

            if ($eventFilterId !== null && (int) ($row['event_id'] ?? 0) !== $eventFilterId) {
                return;
            }

            $decisionApplied = null;
            $appliedAt = null;
            if ($isProcessed) {
                $decisionApplied = $isIgnored ? 'ignored' : 'updated';
                if ($decisionApplied === 'updated') {
                    $appliedAt = $updateTimestampsByEmail[$emailKey][$answerId] ?? null;
                }
            }

            $conflicts[] = [
                'question_answer_id' => $answerId,
                'contact_email' => $contactEmail,
                'attribute_name' => $attributeName,
                'existing_value' => $current,
                'proposed_value' => $proposed,
                'source_order_id' => (int) $row['order_id'],
                'source_attendee_id' => $row['attendee_id'] !== null ? (int) $row['attendee_id'] : null,
                'answered_at' => $row['answer_created_at'] ?? null,
                'event_id' => (int) ($row['event_id'] ?? 0),
                'event_title' => $row['event_title'] ?? null,
                'processed' => $isProcessed,
                'decision_applied' => $decisionApplied,
                'applied_at' => $appliedAt,
            ];
        });

        if ($params->query !== null && $params->query !== '') {
            $needle = strtolower($params->query);
            $conflicts = array_values(array_filter($conflicts, fn ($c) => str_contains(strtolower($c['contact_email']), $needle)
                || str_contains(strtolower((string) $c['attribute_name']), $needle)
            ));
        }

        $sortBy = $params->sort_by ?? 'contact_email';
        $sortDir = strtolower($params->sort_direction ?? 'asc') === 'desc' ? 'desc' : 'asc';
        usort($conflicts, function ($a, $b) use ($sortBy, $sortDir) {
            $av = (string) ($a[$sortBy] ?? '');
            $bv = (string) ($b[$sortBy] ?? '');
            $cmp = strcasecmp($av, $bv);

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        $perPage = $params->per_page ?: 25;
        $page = $params->page ?: 1;
        $total = count($conflicts);
        $items = array_slice($conflicts, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => request()->url(), 'query' => request()->query()],
        );
    }
}
