<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\DTO\CheckedInAttendeesCountDTO;
use HiEvents\Repository\DTO\UncoveredProductDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CheckInListDomainObject>
 */
interface CheckInListRepositoryInterface extends RepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator;

    public function getCheckedInAttendeeCountById(int $checkInListId): CheckedInAttendeesCountDTO;

    /**
     * @param  array<int>  $checkInListIds
     * @return Collection<CheckedInAttendeesCountDTO>
     */
    public function getCheckedInAttendeeCountByIds(array $checkInListIds): Collection;

    /**
     * Products that have at least one non-cancelled attendee but are not
     * referenced by any check-in list for this event.
     *
     * @return Collection<UncoveredProductDTO>
     */
    public function getProductsWithoutCheckInListCoverage(int $eventId): Collection;
}
