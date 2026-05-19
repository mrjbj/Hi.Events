<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Support\Collection;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Returns sibling check-in lists for the event that owns the given short_id.
 *
 * Trust note: a holder of any short_id for an event can already check people in
 * for the products on that list. This endpoint allows them to discover the
 * names + short_ids of the event's other check-in lists. That is a modest scope
 * expansion within a single event, intentional so door staff can hop between
 * lists from the title bar.
 */
class GetCheckInListSiblingsPublicHandler
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    /**
     * @return Collection<int, CheckInListDomainObject>
     */
    public function handle(string $shortId): Collection
    {
        $current = $this->checkInListRepository->findFirstWhere([
            CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
        ]);

        if (!$current) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        return $this->checkInListRepository
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findWhere([
                CheckInListDomainObjectAbstract::EVENT_ID => $current->getEventId(),
            ]);
    }
}
