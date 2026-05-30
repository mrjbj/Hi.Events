<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Attendee;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<AttendeeDomainObject>
 */
class AttendeeRepository extends BaseRepository implements AttendeeRepositoryInterface
{
    protected function getModel(): string
    {
        return Attendee::class;
    }

    public function getDomainObject(): string
    {
        return AttendeeDomainObject::class;
    }

    public function findByEventIdForExport(int $eventId): Collection
    {
        $this->applyConditions([
            'attendees.event_id' => $eventId,
        ]);

        $this->model->select('attendees.*');
        $this->model->join('orders', 'orders.id', '=', 'attendees.order_id');
        $this->model->whereIn('orders.status', [
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            OrderStatus::COMPLETED->name,
            OrderStatus::CANCELLED->name,
        ]);

        $model = $this->model->limit(10000)->get();
        $this->resetModel();

        return $this->handleResults($model);
    }

    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            ['attendees.event_id', '=', $eventId],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                'attendees.'.AttendeeDomainObjectAbstract::FIRST_NAME,
                                'attendees.'.AttendeeDomainObjectAbstract::LAST_NAME,
                            )
                        ), 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::LAST_NAME, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::FIRST_NAME, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::PUBLIC_ID, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::EMAIL, 'ilike', '%'.$params->query.'%');
            };
        }

        $this->model = $this->model->select('attendees.*')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::CANCELLED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name]);

        if ($params->filter_fields && $params->filter_fields->isNotEmpty()) {
            $this->applyFilterFields($params, AttendeeDomainObject::getAllowedFilterFields(), prefix: 'attendees');
        }

        $sortBy = $this->validateSortColumn($params->sort_by, AttendeeDomainObject::class);
        $sortDirection = $this->validateSortDirection($params->sort_direction, AttendeeDomainObject::class);

        if ($sortBy === AttendeeDomainObject::TICKET_NAME_SORT_KEY) {
            $this->model = $this->model
                ->leftJoin('products', 'products.id', '=', 'attendees.product_id')
                ->orderBy('products.title', $sortDirection);
        } else {
            $this->model = $this->model->orderBy('attendees.'.$sortBy, $sortDirection);
        }

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    public function updateEmailByContactId(int $contactId, string $newEmail, int $accountId): int
    {
        $scopedIds = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('events.account_id', $accountId)
            ->where('attendees.contact_id', $contactId)
            ->whereNull('attendees.deleted_at')
            ->pluck('attendees.id')
            ->all();

        if (empty($scopedIds)) {
            return 0;
        }

        return DB::table('attendees')
            ->whereIn('id', $scopedIds)
            ->update([
                AttendeeDomainObjectAbstract::EMAIL => strtolower($newEmail),
                AttendeeDomainObjectAbstract::UPDATED_AT => now(),
            ]);
    }

    public function bulkUpdateContactLinkIgnoredAt(int $accountId, array $attendeeIds, ?string $timestamp): int
    {
        if (empty($attendeeIds)) {
            return 0;
        }

        $scopedIds = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('events.account_id', $accountId)
            ->whereIn('attendees.id', $attendeeIds)
            ->whereNull('attendees.deleted_at')
            ->pluck('attendees.id')
            ->all();

        if (empty($scopedIds)) {
            return 0;
        }

        return DB::table('attendees')
            ->whereIn('id', $scopedIds)
            ->update([
                AttendeeDomainObjectAbstract::CONTACT_LINK_IGNORED_AT => $timestamp,
                AttendeeDomainObjectAbstract::UPDATED_AT => now(),
            ]);
    }

    public function bulkUpdateContactEmailDivergenceIgnoredAt(int $accountId, array $attendeeIds, ?string $timestamp): int
    {
        if (empty($attendeeIds)) {
            return 0;
        }

        $scopedIds = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('events.account_id', $accountId)
            ->whereIn('attendees.id', $attendeeIds)
            ->whereNull('attendees.deleted_at')
            ->pluck('attendees.id')
            ->all();

        if (empty($scopedIds)) {
            return 0;
        }

        return DB::table('attendees')
            ->whereIn('id', $scopedIds)
            ->update([
                AttendeeDomainObjectAbstract::CONTACT_EMAIL_DIVERGENCE_IGNORED_AT => $timestamp,
                AttendeeDomainObjectAbstract::UPDATED_AT => now(),
            ]);
    }

    public function bulkUpdateContactEmailDivergenceFlaggedAt(int $accountId, array $attendeeIds, ?string $timestamp): int
    {
        if (empty($attendeeIds)) {
            return 0;
        }

        $scopedIds = DB::table('attendees')
            ->join('events', 'events.id', '=', 'attendees.event_id')
            ->where('events.account_id', $accountId)
            ->whereIn('attendees.id', $attendeeIds)
            ->whereNull('attendees.deleted_at')
            ->pluck('attendees.id')
            ->all();

        if (empty($scopedIds)) {
            return 0;
        }

        return DB::table('attendees')
            ->whereIn('id', $scopedIds)
            ->update([
                AttendeeDomainObjectAbstract::CONTACT_EMAIL_DIVERGENCE_FLAGGED_AT => $timestamp,
                AttendeeDomainObjectAbstract::UPDATED_AT => now(),
            ]);
    }

    public function countActiveByContactId(int $contactId): int
    {
        return DB::table('attendees')
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->count();
    }

    public function getAttendeesByCheckInShortId(string $shortId, QueryParamsDTO $params): Paginator
    {
        $where = [];
        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                'attendees.'.AttendeeDomainObjectAbstract::FIRST_NAME,
                                'attendees.'.AttendeeDomainObjectAbstract::LAST_NAME,
                            )
                        ), 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::LAST_NAME, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::FIRST_NAME, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::PUBLIC_ID, 'ilike', '%'.$params->query.'%')
                    ->orWhere('attendees.'.AttendeeDomainObjectAbstract::EMAIL, 'ilike', '%'.$params->query.'%');
            };
        }

        $this->model = $this->model->select('attendees.*')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->join('product_check_in_lists', 'product_check_in_lists.product_id', '=', 'attendees.product_id')
            ->join('check_in_lists', 'check_in_lists.id', '=', 'product_check_in_lists.check_in_list_id')
            ->where('check_in_lists.short_id', $shortId)
            ->whereIn('attendees.status', [AttendeeStatus::ACTIVE->name, AttendeeStatus::CANCELLED->name, AttendeeStatus::AWAITING_PAYMENT->name])
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name]);

        if ($params->filter_fields && $params->filter_fields->isNotEmpty()) {
            $this->applyFilterFields($params, AttendeeDomainObject::getAllowedFilterFields(), prefix: 'attendees');
        }

        $this->loadRelation(new Relationship(AttendeeCheckInDomainObject::class, name: 'check_ins'));
        // Load the buyer's order so the check-in resource can surface
        // buyer name/email on the "Group purchase" badge popover. The
        // attendee → order relation is singular ('order'), so we must
        // name it explicitly — the bare class form would pluralize.
        $this->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'));

        return $this->simplePaginateWhere(
            where: $where,
            limit: min($params->per_page, 250),
        );
    }

    public function findCheckedInAttendees(int $eventId, ?int $checkInListId = null, array $columns = ['*']): Collection
    {
        $query = $this->model
            ->where('event_id', $eventId)
            ->where('status', AttendeeStatus::ACTIVE->name)
            ->whereIn('product_id', function ($sub) use ($checkInListId) {
                $sub->select('product_id')->from('product_check_in_lists')->whereNull('deleted_at');
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            })
            ->whereHas('check_ins', function ($sub) use ($checkInListId) {
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            });

        $results = $query->get($columns);
        $this->resetModel();

        return $this->handleResults($results);
    }

    public function findNotCheckedInAttendees(int $eventId, ?int $checkInListId = null, array $columns = ['*']): Collection
    {
        $query = $this->model
            ->where('event_id', $eventId)
            ->where('status', AttendeeStatus::ACTIVE->name)
            ->whereIn('product_id', function ($sub) use ($checkInListId) {
                $sub->select('product_id')->from('product_check_in_lists')->whereNull('deleted_at');
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            })
            ->whereDoesntHave('check_ins', function ($sub) use ($checkInListId) {
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            });

        $results = $query->get($columns);
        $this->resetModel();

        return $this->handleResults($results);
    }

    public function countCheckedInAttendees(int $eventId, ?int $checkInListId = null): int
    {
        $query = $this->model
            ->where('event_id', $eventId)
            ->where('status', AttendeeStatus::ACTIVE->name)
            ->whereIn('product_id', function ($sub) use ($checkInListId) {
                $sub->select('product_id')->from('product_check_in_lists')->whereNull('deleted_at');
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            })
            ->whereHas('check_ins', function ($sub) use ($checkInListId) {
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            });

        $count = $query->count();
        $this->resetModel();

        return $count;
    }

    public function countNotCheckedInAttendees(int $eventId, ?int $checkInListId = null): int
    {
        $query = $this->model
            ->where('event_id', $eventId)
            ->where('status', AttendeeStatus::ACTIVE->name)
            ->whereIn('product_id', function ($sub) use ($checkInListId) {
                $sub->select('product_id')->from('product_check_in_lists')->whereNull('deleted_at');
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            })
            ->whereDoesntHave('check_ins', function ($sub) use ($checkInListId) {
                if ($checkInListId) {
                    $sub->where('check_in_list_id', $checkInListId);
                }
            });

        $count = $query->count();

        $this->resetModel();

        return $count;
    }

    public function findAttendeeOnCheckInList(string $checkInListShortId, string $attendeePublicId): ?AttendeeDomainObject
    {
        $row = DB::table('attendees')
            ->select('attendees.id')
            ->join('product_check_in_lists', 'product_check_in_lists.product_id', '=', 'attendees.product_id')
            ->join('check_in_lists', 'check_in_lists.id', '=', 'product_check_in_lists.check_in_list_id')
            ->where('check_in_lists.short_id', $checkInListShortId)
            ->where('attendees.public_id', $attendeePublicId)
            ->whereNull('attendees.deleted_at')
            ->whereNull('product_check_in_lists.deleted_at')
            ->first();

        if (! $row) {
            return null;
        }

        return $this->findFirstWhere(['id' => $row->id]);
    }

    public function getCheckInListFilterOptions(string $shortId): array
    {
        $tables = DB::table('attendees')
            ->join('product_check_in_lists', 'product_check_in_lists.product_id', '=', 'attendees.product_id')
            ->join('check_in_lists', 'check_in_lists.id', '=', 'product_check_in_lists.check_in_list_id')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->where('check_in_lists.short_id', $shortId)
            ->whereNotNull('attendees.seat_info')
            ->where('attendees.seat_info', '!=', '')
            ->whereIn('attendees.status', [AttendeeStatus::ACTIVE->name, AttendeeStatus::CANCELLED->name, AttendeeStatus::AWAITING_PAYMENT->name])
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
            ->whereNull('attendees.deleted_at')
            ->distinct()
            ->orderBy('attendees.seat_info')
            ->pluck('attendees.seat_info')
            ->all();

        // Aggregate per order: ticket count is the sum of quantities for that
        // order (multi-ticket order items only). We then join back to orders to
        // pick up buyer name/email and short_id for disambiguating labels.
        $groups = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_check_in_lists', 'product_check_in_lists.product_id', '=', 'order_items.product_id')
            ->join('check_in_lists', 'check_in_lists.id', '=', 'product_check_in_lists.check_in_list_id')
            ->where('check_in_lists.short_id', $shortId)
            ->where('order_items.quantity', '>', 1)
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
            ->groupBy('orders.id', 'orders.short_id', 'orders.first_name', 'orders.last_name', 'orders.email')
            ->select(
                'orders.id as order_id',
                'orders.short_id as order_short_id',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                DB::raw('SUM(order_items.quantity) as ticket_count'),
            )
            ->orderBy('orders.last_name')
            ->orderBy('orders.first_name')
            ->get();

        $groupOptions = $groups
            ->map(function ($row) {
                $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
                $name = $name !== '' ? $name : ($row->email ?? '');
                $ticketCount = (int) $row->ticket_count;
                $shortId = (string) ($row->order_short_id ?? '');
                $shortSuffix = $shortId !== '' ? mb_substr($shortId, -4) : '';
                $label = $name;
                if ($shortSuffix !== '') {
                    $label .= ' · #'.$shortSuffix;
                }
                $label .= ' ('.$ticketCount.')';

                return [
                    'order_id' => (int) $row->order_id,
                    'label' => $label,
                ];
            })
            ->unique('order_id')
            ->values()
            ->all();

        return [
            'tables' => $tables,
            'groups' => $groupOptions,
        ];
    }

    public function getEventAttendeeFilterOptions(int $eventId): array
    {
        $tables = DB::table('attendees')
            ->join('orders', 'orders.id', '=', 'attendees.order_id')
            ->where('attendees.event_id', $eventId)
            ->whereNotNull('attendees.seat_info')
            ->where('attendees.seat_info', '!=', '')
            ->whereIn('attendees.status', [AttendeeStatus::ACTIVE->name, AttendeeStatus::CANCELLED->name, AttendeeStatus::AWAITING_PAYMENT->name])
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
            ->whereNull('attendees.deleted_at')
            ->distinct()
            ->orderBy('attendees.seat_info')
            ->pluck('attendees.seat_info')
            ->all();

        $groups = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $eventId)
            ->where('order_items.quantity', '>', 1)
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
            ->groupBy('orders.id', 'orders.short_id', 'orders.first_name', 'orders.last_name', 'orders.email')
            ->select(
                'orders.id as order_id',
                'orders.short_id as order_short_id',
                'orders.first_name',
                'orders.last_name',
                'orders.email',
                DB::raw('SUM(order_items.quantity) as ticket_count'),
            )
            ->orderBy('orders.last_name')
            ->orderBy('orders.first_name')
            ->get();

        $groupOptions = $groups
            ->map(function ($row) {
                $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
                $name = $name !== '' ? $name : ($row->email ?? '');
                $ticketCount = (int) $row->ticket_count;
                $shortId = (string) ($row->order_short_id ?? '');
                $shortSuffix = $shortId !== '' ? mb_substr($shortId, -4) : '';
                $label = $name;
                if ($shortSuffix !== '') {
                    $label .= ' · #'.$shortSuffix;
                }
                $label .= ' ('.$ticketCount.')';

                return [
                    'order_id' => (int) $row->order_id,
                    'label' => $label,
                ];
            })
            ->unique('order_id')
            ->values()
            ->all();

        return [
            'tables' => $tables,
            'groups' => $groupOptions,
        ];
    }

    public function getGroupPurchaseKeysByCheckInShortId(string $shortId): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_check_in_lists', 'product_check_in_lists.product_id', '=', 'order_items.product_id')
            ->join('check_in_lists', 'check_in_lists.id', '=', 'product_check_in_lists.check_in_list_id')
            ->where('check_in_lists.short_id', $shortId)
            ->where('order_items.quantity', '>', 1)
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name])
            ->select('order_items.order_id', 'order_items.product_price_id')
            ->distinct()
            ->get();

        return $rows->map(fn ($r) => $r->order_id.':'.$r->product_price_id)->all();
    }
}
