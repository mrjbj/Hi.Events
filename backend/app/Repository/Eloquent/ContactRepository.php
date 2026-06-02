<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Contact;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactActivityDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactAttendedEventDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactOrderSummaryDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<ContactDomainObject>
 */
class ContactRepository extends BaseRepository implements ContactRepositoryInterface
{
    protected function getModel(): string
    {
        return Contact::class;
    }

    public function getDomainObject(): string
    {
        return ContactDomainObject::class;
    }

    public function findByAccountId(int $accountId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            ['contacts.'.ContactDomainObjectAbstract::ACCOUNT_ID, '=', $accountId],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->orWhere('contacts.'.ContactDomainObjectAbstract::EMAIL, 'ilike', '%'.$params->query.'%')
                    ->orWhereRaw(
                        "contacts.first_name || ' ' || contacts.last_name ilike ?",
                        ['%'.$params->query.'%']
                    )
                    ->orWhere('contacts.'.ContactDomainObjectAbstract::LAST_NAME, 'ilike', '%'.$params->query.'%')
                    ->orWhere('contacts.'.ContactDomainObjectAbstract::FIRST_NAME, 'ilike', '%'.$params->query.'%');
            };
        }

        $eventIdFilter = $this->extractEventIdFilter($params);
        $this->model = $this->model->select('contacts.*');

        // The badge must agree with what actually gets sent: SES-derived
        // suppressions (bounce/complaint, incl. permanent bounce) only take
        // effect when this flag is on — otherwise only do_not_contact counts.
        // Mirrors EmailSuppressionService::isEmailSuppressed.
        $sesEnabled = (bool) config('services.ses.suppression_enabled', false);
        $this->addSuppressionColumns($accountId, $sesEnabled);

        if ($eventIdFilter !== null) {
            $this->model = $this->model
                ->join('attendees', 'attendees.contact_id', '=', 'contacts.id')
                ->where('attendees.event_id', '=', $eventIdFilter)
                ->whereNull('attendees.deleted_at')
                ->distinct();
        }

        $statusFilter = $this->extractSuppressionStatusFilter($params);
        if ($statusFilter !== null) {
            $this->applySuppressionStatusFilter($statusFilter, $accountId, $sesEnabled);
        }

        $this->model = $this->model->orderBy(
            column: 'contacts.'.$this->validateSortColumn($params->sort_by, ContactDomainObject::class),
            direction: $this->validateSortDirection($params->sort_direction, ContactDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    /**
     * Bucket each contact's address (active / marketing_only / always) and a
     * short detail string for the tooltip, computed from email_suppressions
     * matched by lowercased email within the account (or platform-wide rows).
     */
    private function addSuppressionColumns(int $accountId, bool $sesEnabled): void
    {
        $always = $this->alwaysExistsSql($accountId, $sesEnabled);

        $case = "CASE WHEN {$always} THEN 'always' ";
        if ($sesEnabled) {
            $marketing = $this->marketingExistsSql($accountId);
            $case .= "WHEN {$marketing} THEN 'marketing_only' ";
        }
        $case .= "ELSE 'active' END";

        $detail = "(SELECT string_agg(DISTINCT CASE
                WHEN es.reason = 'bounce' THEN 'bounce:' || COALESCE(es.bounce_type, 'Undetermined')
                ELSE es.reason END, ', ')
            FROM email_suppressions es
            WHERE lower(es.email) = lower(contacts.email)
              AND es.deleted_at IS NULL
              AND (es.account_id = {$accountId} OR es.account_id IS NULL))";

        $this->model = $this->model
            ->selectRaw("({$case}) as suppression_status")
            ->selectRaw("{$detail} as suppression_detail");
    }

    private function applySuppressionStatusFilter(string $status, int $accountId, bool $sesEnabled): void
    {
        $always = $this->alwaysExistsSql($accountId, $sesEnabled);

        if ($status === 'always') {
            $this->model = $this->model->whereRaw($always);

            return;
        }

        if ($status === 'marketing_only') {
            if (! $sesEnabled) {
                $this->model = $this->model->whereRaw('1 = 0');

                return;
            }
            $marketing = $this->marketingExistsSql($accountId);
            $this->model = $this->model->whereRaw("({$marketing}) AND NOT ({$always})");

            return;
        }

        // active
        if ($sesEnabled) {
            $marketing = $this->marketingExistsSql($accountId);
            $this->model = $this->model->whereRaw("NOT ({$always}) AND NOT ({$marketing})");
        } else {
            $this->model = $this->model->whereRaw("NOT ({$always})");
        }
    }

    private function alwaysExistsSql(int $accountId, bool $sesEnabled): string
    {
        $reasonClause = $sesEnabled
            ? "(es.reason = 'do_not_contact' OR (es.reason = 'bounce' AND es.bounce_type = 'Permanent'))"
            : "es.reason = 'do_not_contact'";

        return "EXISTS (SELECT 1 FROM email_suppressions es
            WHERE lower(es.email) = lower(contacts.email)
              AND es.deleted_at IS NULL
              AND (es.account_id = {$accountId} OR es.account_id IS NULL)
              AND {$reasonClause})";
    }

    private function marketingExistsSql(int $accountId): string
    {
        return "EXISTS (SELECT 1 FROM email_suppressions es
            WHERE lower(es.email) = lower(contacts.email)
              AND es.deleted_at IS NULL
              AND (es.account_id = {$accountId} OR es.account_id IS NULL)
              AND (es.reason = 'complaint' OR (es.reason = 'bounce' AND (es.bounce_type IS NULL OR es.bounce_type <> 'Permanent'))))";
    }

    private function extractSuppressionStatusFilter(QueryParamsDTO $params): ?string
    {
        if (! $params->filter_fields || $params->filter_fields->isEmpty()) {
            return null;
        }
        $match = $params->filter_fields->first(fn ($field) => $field->field === 'suppression_status');
        if (! $match) {
            return null;
        }
        $value = is_string($match->value) ? $match->value : (string) $match->value;

        return in_array($value, ['active', 'marketing_only', 'always'], true) ? $value : null;
    }

    private function extractEventIdFilter(QueryParamsDTO $params): ?int
    {
        if (! $params->filter_fields || $params->filter_fields->isEmpty()) {
            return null;
        }
        $match = $params->filter_fields->first(fn ($field) => $field->field === 'event_id');
        if (! $match) {
            return null;
        }
        $value = is_string($match->value) ? $match->value : (string) $match->value;

        return ctype_digit($value) ? (int) $value : null;
    }

    public function getActivity(int $contactId, int $accountId): ContactActivityDTO
    {
        $eventRows = $this->db->table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('attendees.contact_id', $contactId)
            ->where('events.account_id', $accountId)
            ->whereNull('attendees.deleted_at')
            ->groupBy('events.id', 'events.title', 'events.start_date', 'events.status')
            ->select(
                'events.id',
                'events.title',
                'events.start_date',
                'events.status',
                $this->db->raw('count(attendees.id) as tickets_count'),
            )
            ->orderByRaw('events.start_date desc nulls last')
            ->get();

        $orderRows = $this->db->table('orders')
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->whereIn('orders.id', function ($query) use ($contactId) {
                $query->select('order_id')
                    ->from('attendees')
                    ->where('contact_id', $contactId)
                    ->whereNull('deleted_at');
            })
            ->where('events.account_id', $accountId)
            ->whereNull('orders.deleted_at')
            ->select(
                'orders.id',
                'orders.short_id',
                'orders.public_id',
                'orders.status',
                'orders.payment_status',
                'orders.refund_status',
                'orders.total_gross',
                'orders.currency',
                'orders.created_at',
                'orders.event_id',
                'events.title as event_title',
            )
            ->orderByDesc('orders.created_at')
            ->get();

        return new ContactActivityDTO(
            events: $eventRows->map(fn ($row) => new ContactAttendedEventDTO(
                id: (int) $row->id,
                title: (string) $row->title,
                start_date: $row->start_date,
                status: $row->status,
                tickets_count: (int) $row->tickets_count,
            ))->all(),
            orders: $orderRows->map(fn ($row) => new ContactOrderSummaryDTO(
                id: (int) $row->id,
                short_id: $row->short_id,
                public_id: $row->public_id,
                status: $row->status,
                payment_status: $row->payment_status,
                refund_status: $row->refund_status,
                total_gross: (float) $row->total_gross,
                currency: $row->currency,
                created_at: $row->created_at,
                event_id: (int) $row->event_id,
                event_title: $row->event_title,
            ))->all(),
        );
    }

    public function findByEmailAndAccountId(string $email, int $accountId): ?ContactDomainObject
    {
        return $this->findFirstWhere([
            [ContactDomainObjectAbstract::ACCOUNT_ID, '=', $accountId],
            fn (Builder $builder) => $builder->whereRaw('lower(email) = ?', [strtolower($email)]),
        ]);
    }

    public function updateEmail(int $contactId, string $email, string $reason = 'manual_resolve', ?int $changedByUserId = null): void
    {
        $contact = $this->findById($contactId);
        if ($contact === null) {
            return;
        }

        $oldEmail = $contact->getEmail();
        $newEmail = strtolower($email);
        if ($oldEmail === $newEmail) {
            return;
        }

        // Read defensively: a few legacy rows have a double-encoded JSON string
        // here (from the pre-fix version of THIS method, see below), so a single
        // json_decode yields the inner string. Recurse one extra level to
        // recover, otherwise we lose history when self-healing the row.
        $history = $contact->getAttributesHistory();
        if (is_string($history)) {
            $decoded = json_decode($history, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $history = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($history)) {
            $history = [];
        }
        $history[] = [
            'field' => 'email',
            'old_value' => $oldEmail,
            'new_value' => $newEmail,
            'changed_at' => now()->toIso8601String(),
            'changed_by' => $changedByUserId,
            'reason' => $reason,
        ];

        // CRITICAL: pass the array, not json_encode($history). The Contact model
        // casts attributes_history to 'array', so Eloquent encodes whatever it
        // receives. Passing an already-encoded string here produces a JSON
        // string of a JSON string — every subsequent read decodes to a string
        // instead of an array, breaking the Sync → Different Answers tab and
        // the EditContactModal history panel. Fixed 2026-05-26 after contact
        // 6 (account 39) surfaced the corruption.
        $this->updateFromArray($contactId, [
            ContactDomainObjectAbstract::EMAIL => $newEmail,
            ContactDomainObjectAbstract::ATTRIBUTES_HISTORY => $history,
        ]);
    }
}
