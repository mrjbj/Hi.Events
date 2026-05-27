<?php

namespace HiEvents\Services\Application\Handlers\CheckInList;

use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Support\Collection;

class GetUncoveredProductsHandler
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    public function handle(int $eventId): Collection
    {
        return $this->checkInListRepository->getProductsWithoutCheckInListCoverage($eventId);
    }
}
