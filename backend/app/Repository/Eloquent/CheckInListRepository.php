<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\CapacityAssignmentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\CheckInList;
use HiEvents\Repository\DTO\CheckedInAttendeesCountDTO;
use HiEvents\Repository\DTO\UncoveredProductDTO;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<CheckInListDomainObject>
 */
class CheckInListRepository extends BaseRepository implements CheckInListRepositoryInterface
{
    protected function getModel(): string
    {
        return CheckInList::class;
    }

    public function getDomainObject(): string
    {
        return CheckInListDomainObject::class;
    }

    public function getCheckedInAttendeeCountById(int $checkInListId): CheckedInAttendeesCountDTO
    {
        $sql = <<<'SQL'
            WITH valid_check_ins AS (
                SELECT attendee_id, check_in_list_id
                FROM attendee_check_ins
                WHERE deleted_at IS NULL
                AND check_in_list_id = :check_in_list_id
                GROUP BY attendee_id, check_in_list_id
            ),
                 valid_attendees AS (
                     SELECT a.id, pcil.check_in_list_id
                     FROM attendees a
                                 JOIN product_check_in_lists pcil ON a.product_id = pcil.product_id
                                 JOIN orders o ON a.order_id = o.id
                                 JOIN check_in_lists cil ON pcil.check_in_list_id = cil.id
                                 JOIN event_settings es ON cil.event_id = es.event_id
                     WHERE a.deleted_at IS NULL
                        AND pcil.deleted_at IS NULL
                        AND pcil.check_in_list_id = :check_in_list_id
                        AND (
                            (es.allow_orders_awaiting_offline_payment_to_check_in = true AND a.status in ('ACTIVE', 'AWAITING_PAYMENT') AND o.status IN ('COMPLETED', 'AWAITING_OFFLINE_PAYMENT'))
                            OR
                            (es.allow_orders_awaiting_offline_payment_to_check_in = false AND a.status = 'ACTIVE' AND o.status = 'COMPLETED')
                        )
                 )
            SELECT
                cil.id AS check_in_list_id,
                COUNT(va.id) AS total_attendees,
                COUNT(DISTINCT vci.attendee_id) AS checked_in_attendees
            FROM check_in_lists cil
                     LEFT JOIN valid_attendees va ON va.check_in_list_id = cil.id
                     LEFT JOIN valid_check_ins vci ON vci.attendee_id = va.id AND vci.check_in_list_id = va.check_in_list_id
            WHERE cil.id = :check_in_list_id
              AND cil.deleted_at IS NULL
            GROUP BY cil.id;
        SQL;

        $query = $this->db->selectOne($sql, ['check_in_list_id' => $checkInListId]);

        return new CheckedInAttendeesCountDTO(
            checkInListId: $checkInListId,
            checkedInCount: $query->checked_in_attendees ?? 0,
            totalAttendeesCount: $query->total_attendees ?? 0,
        );
    }

    public function getCheckedInAttendeeCountByIds(array $checkInListIds): Collection
    {
        $placeholders = implode(',', array_fill(0, count($checkInListIds), '?'));

        $sql = <<<SQL
            WITH valid_check_ins AS (
                SELECT attendee_id, check_in_list_id
                FROM attendee_check_ins
                WHERE deleted_at IS NULL
                AND check_in_list_id IN ($placeholders)
                GROUP BY attendee_id, check_in_list_id
            ),
                 valid_attendees AS (
                     SELECT a.id, pcil.check_in_list_id
                     FROM attendees a
                              JOIN product_check_in_lists pcil ON a.product_id = pcil.product_id
                              JOIN orders o ON a.order_id = o.id
                              JOIN check_in_lists cil ON pcil.check_in_list_id = cil.id
                              JOIN event_settings es ON cil.event_id = es.event_id
                     WHERE a.deleted_at IS NULL
                       AND pcil.deleted_at IS NULL
                       AND pcil.check_in_list_id IN ($placeholders)
                       AND (
                           (es.allow_orders_awaiting_offline_payment_to_check_in = true AND a.status IN ('ACTIVE', 'AWAITING_PAYMENT') AND o.status IN ('COMPLETED', 'AWAITING_OFFLINE_PAYMENT'))
                           OR
                           (es.allow_orders_awaiting_offline_payment_to_check_in = false AND a.status = 'ACTIVE' AND o.status = 'COMPLETED')
                       )
                 )
            SELECT
                cil.id AS check_in_list_id,
                COUNT(va.id) AS total_attendees,
                COUNT(DISTINCT vci.attendee_id) AS checked_in_attendees
            FROM check_in_lists cil
                     LEFT JOIN valid_attendees va ON va.check_in_list_id = cil.id
                     LEFT JOIN valid_check_ins vci ON vci.attendee_id = va.id AND vci.check_in_list_id = va.check_in_list_id
            WHERE cil.id IN ($placeholders)
              AND cil.deleted_at IS NULL
            GROUP BY cil.id;
    SQL;

        $query = $this->db->select($sql, array_merge($checkInListIds, $checkInListIds, $checkInListIds));

        return collect($query)->map(
            static fn ($item) => new CheckedInAttendeesCountDTO(
                checkInListId: $item->check_in_list_id,
                checkedInCount: $item->checked_in_attendees,
                totalAttendeesCount: $item->total_attendees,
            )
        );
    }

    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [CheckInListDomainObjectAbstract::EVENT_ID, '=', $eventId],
        ];

        if (! empty($params->query)) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(CapacityAssignmentDomainObjectAbstract::NAME, 'ilike', '%'.$params->query.'%');
            };
        }

        $this->model = $this->model->orderBy(
            $this->validateSortColumn($params->sort_by, CheckInListDomainObject::class),
            $this->validateSortDirection($params->sort_direction, CheckInListDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }

    public function getProductsWithoutCheckInListCoverage(int $eventId): Collection
    {
        $sql = <<<'SQL'
            SELECT p.id AS product_id,
                   p.title AS title,
                   COUNT(DISTINCT a.id) AS attendee_count
            FROM products p
            INNER JOIN attendees a
                ON a.product_id = p.id
                AND a.deleted_at IS NULL
                AND a.status IN ('ACTIVE', 'AWAITING_PAYMENT')
            WHERE p.event_id = :event_id
              AND p.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM product_check_in_lists pcil
                  INNER JOIN check_in_lists cil ON pcil.check_in_list_id = cil.id
                  WHERE pcil.product_id = p.id
                    AND pcil.deleted_at IS NULL
                    AND cil.deleted_at IS NULL
                    AND cil.event_id = :event_id_filter
              )
            GROUP BY p.id, p.title
            ORDER BY p.title ASC
        SQL;

        $rows = DB::select($sql, [
            'event_id' => $eventId,
            'event_id_filter' => $eventId,
        ]);

        return collect($rows)->map(static fn ($row) => new UncoveredProductDTO(
            product_id: (int) $row->product_id,
            title: (string) $row->title,
            attendee_count: (int) $row->attendee_count,
        ));
    }
}
