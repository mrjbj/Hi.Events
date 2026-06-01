<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;

/**
 * @extends BaseRepository<OrderDomainObject>
 */
class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [OrderDomainObjectAbstract::EVENT_ID, '=', $eventId],
            [OrderDomainObjectAbstract::STATUS, '!=', OrderStatus::RESERVED->name],
            [OrderDomainObjectAbstract::STATUS, '!=', OrderStatus::ABANDONED->name],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                OrderDomainObjectAbstract::FIRST_NAME,
                                OrderDomainObjectAbstract::LAST_NAME
                            )
                        ), 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $params->query . '%');
            };
        }

        // product_id lives on order_items, not orders, so the generic
        // applyFilterFields path can't reach it. Translate it into a
        // whereHas('order_items', ...) clause.
        $this->applyProductIdFilter($params);

        // payment_type spans the order_payments ledger (method/transaction_type)
        // plus the separate stripe_payments table, so it can't be a generic
        // allowed-field either. Route each selected value to the right column.
        $this->applyPaymentTypeFilter($params);

        if (!empty($params->filter_fields)) {
            $this->applyFilterFields($params, OrderDomainObject::getAllowedFilterFields());
        }

        $this->model = $this->model->orderBy(
            column: $this->validateSortColumn($params->sort_by, OrderDomainObject::class),
            direction: $this->validateSortDirection($params->sort_direction, OrderDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    private function applyProductIdFilter(QueryParamsDTO $params): void
    {
        $filterFields = $params->filter_fields;
        if (!$filterFields || $filterFields->isEmpty()) {
            return;
        }

        $productIdFilter = $filterFields->first(static fn ($f) => $f->field === 'product_id');
        if (!$productIdFilter) {
            return;
        }

        $productIds = is_array($productIdFilter->value)
            ? $productIdFilter->value
            : explode(',', (string) $productIdFilter->value);
        $productIds = array_values(array_filter(
            array_map('intval', $productIds),
            static fn ($v) => $v > 0,
        ));

        if (empty($productIds)) {
            return;
        }

        // product_id is not in OrderDomainObject::getAllowedFilterFields(), so
        // applyFilterFields will skip it harmlessly — we don't need to strip it.
        $this->model = $this->model->whereHas('order_items', static function (Builder $q) use ($productIds) {
            $q->whereIn('product_id', $productIds);
        });
    }

    /**
     * Filter orders by how they were paid. The single combined "payment_type"
     * filter carries a mix of offline methods (CASH/CHECK/CREDIT_CARD/...),
     * ledger transaction types (COMP/DONATION) and STRIPE — each routed to its
     * own column or relation. Selecting several is a union: an order matches if
     * it has a live (non-reversed) ledger row of any chosen method/type, or a
     * confirmed Stripe payment when STRIPE is chosen.
     */
    private function applyPaymentTypeFilter(QueryParamsDTO $params): void
    {
        $filterFields = $params->filter_fields;
        if (!$filterFields || $filterFields->isEmpty()) {
            return;
        }

        $filter = $filterFields->first(static fn ($f) => $f->field === 'payment_type');
        if (!$filter) {
            return;
        }

        $values = is_array($filter->value)
            ? $filter->value
            : explode(',', (string) $filter->value);
        $values = array_values(array_filter(array_map(
            static fn ($v) => strtoupper(trim((string) $v)),
            $values,
        )));

        if (empty($values)) {
            return;
        }

        $methodValues = array_map(static fn (OfflinePaymentMethod $m) => $m->value, OfflinePaymentMethod::cases());
        $methods = array_values(array_intersect($values, $methodValues));
        $transactionTypes = array_values(array_intersect(
            $values,
            [PaymentTransactionType::COMP->value, PaymentTransactionType::DONATION->value],
        ));
        $includeStripe = in_array('STRIPE', $values, true);

        if (empty($methods) && empty($transactionTypes) && ! $includeStripe) {
            return;
        }

        $this->model = $this->model->where(function (Builder $query) use ($methods, $transactionTypes, $includeStripe) {
            if (!empty($methods)) {
                $query->orWhereHas('order_payments', fn (Builder $q) => $this->scopeLivePayments($q)->whereIn('payment_method', $methods));
            }
            if (!empty($transactionTypes)) {
                $query->orWhereHas('order_payments', fn (Builder $q) => $this->scopeLivePayments($q)->whereIn('transaction_type', $transactionTypes));
            }
            if ($includeStripe) {
                $query->orWhereHas('stripe_payment', static fn (Builder $q) => $q->where('amount_received', '>', 0));
            }
        });
    }

    /**
     * Restrict to ledger rows that still count: not a reversal entry, and not
     * itself reversed by a later entry.
     */
    private function scopeLivePayments(Builder $query): Builder
    {
        return $query
            ->whereNull('reverses_payment_id')
            ->whereNotExists(static function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('order_payments as reversals')
                    ->whereColumn('reversals.reverses_payment_id', 'order_payments.id')
                    ->whereNull('reversals.deleted_at');
            });
    }

    public function findByOrganizerId(int $organizerId, int $accountId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            ['orders.status', '!=', OrderStatus::RESERVED->name],
            ['orders.status', '!=', OrderStatus::ABANDONED->name],
        ];

        if ($params->query) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(
                        DB::raw(
                            sprintf(
                                "(%s||' '||%s)",
                                OrderDomainObjectAbstract::FIRST_NAME,
                                OrderDomainObjectAbstract::LAST_NAME
                            )
                        ), 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $params->query . '%')
                    ->orWhere(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $params->query . '%');
            };
        }

        if (!empty($params->filter_fields)) {
            $this->applyFilterFields($params, OrderDomainObject::getAllowedFilterFields());
        }

        $this->model = $this->model
            ->select('orders.*')
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->where('events.organizer_id', $organizerId)
            ->where('events.account_id', $accountId);

        $sortBy = $this->validateSortColumn($params->sort_by, OrderDomainObject::class);
        $this->model = $this->model->orderBy(
            column: 'orders.' . $sortBy,
            direction: $this->validateSortDirection($params->sort_direction, OrderDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    public function getOrderItems(int $orderId)
    {
        return $this->handleResults(
            $this->model->find($orderId)->orderItems,
            OrderItemDomainObject::class
        );
    }

    public function getAttendees(int $orderId)
    {
        return $this->handleResults(
            $this->model->find($orderId)->attendees,
            AttendeeDomainObject::class
        );
    }

    public function addOrderItem(array $data): OrderItemDomainObject
    {
        $orderItem = $this->initModel(OrderItem::class)->create($data);

        return $this->handleSingleResult($orderItem, OrderItemDomainObject::class);
    }

    /**
     * @param string $orderShortId
     * @return OrderDomainObject|null
     */
    public function findByShortId(string $orderShortId): ?OrderDomainObject
    {
        return $this->findFirstByField('short_id', $orderShortId);
    }

    public function getDomainObject(): string
    {
        return OrderDomainObject::class;
    }

    protected function getModel(): string
    {
        return Order::class;
    }

    public function findOrdersAssociatedWithProducts(int $eventId, array $productIds, array $orderStatuses): Collection
    {
        return $this->handleResults(
            $this->model
                ->whereHas('order_items', static function (Builder $query) use ($productIds) {
                    $query->whereIn('product_id', $productIds);
                })
                ->whereIn('status', $orderStatuses)
                ->where('event_id', $eventId)
                ->get()
        );
    }

    public function countOrdersAssociatedWithProducts(int $eventId, array $productIds, array $orderStatuses): int
    {
        $count = $this->model
            ->whereHas('order_items', static function (Builder $query) use ($productIds) {
                $query->whereIn('product_id', $productIds);
            })
            ->whereIn('status', $orderStatuses)
            ->where('event_id', $eventId)
            ->count();

        $this->resetModel();

        return $count;
    }

    public function getAllOrdersForAdmin(
        ?string $search = null,
        int $perPage = 20,
        ?string $sortBy = 'created_at',
        ?string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $this->model = $this->model
            ->select('orders.*')
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->join('accounts', 'events.account_id', '=', 'accounts.id');

        if ($search) {
            $this->model = $this->model->where(function ($q) use ($search) {
                $q->where(OrderDomainObjectAbstract::EMAIL, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::FIRST_NAME, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::LAST_NAME, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::PUBLIC_ID, 'ilike', '%' . $search . '%')
                    ->orWhere(OrderDomainObjectAbstract::SHORT_ID, 'ilike', '%' . $search . '%');
            });
        }

        $this->model = $this->model->where('orders.status', '!=', OrderStatus::RESERVED->name)
            ->where('orders.status', '!=', OrderStatus::ABANDONED->name);

        $allowedSortColumns = ['created_at', 'total_gross', 'email', 'first_name', 'last_name'];
        $sortColumn = in_array($sortBy, $allowedSortColumns, true) ? $sortBy : 'created_at';
        $sortDir = in_array(strtolower($sortDirection), ['asc', 'desc']) ? $sortDirection : 'desc';

        $this->model = $this->model->orderBy('orders.' . $sortColumn, $sortDir);

        $this->loadRelation(new Relationship(EventDomainObject::class, nested: [
            new Relationship(AccountDomainObject::class, name: 'account')
        ], name: 'event'));

        return $this->paginate($perPage);
    }

    public function hasCompletedPaidOrderForAccount(int $accountId): bool
    {
        $exists = $this->model
            ->join('events', 'orders.event_id', '=', 'events.id')
            ->join('stripe_payments', 'orders.id', '=', 'stripe_payments.order_id')
            ->where('events.account_id', $accountId)
            ->where('orders.payment_status', OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->whereNotNull('stripe_payments.payment_intent_id')
            ->exists();

        $this->resetModel();

        return $exists;
    }
}
