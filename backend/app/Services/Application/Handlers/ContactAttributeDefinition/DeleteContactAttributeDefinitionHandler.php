<?php

namespace HiEvents\Services\Application\Handlers\ContactAttributeDefinition;

use HiEvents\DomainObjects\ContactAttributeDefinitionDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\ContactAttributeDefinitionRepositoryInterface;
use Illuminate\Support\Facades\DB;

readonly class DeleteContactAttributeDefinitionHandler
{
    public function __construct(
        private ContactAttributeDefinitionRepositoryInterface $repository,
    ) {
    }

    /**
     * @throws ResourceConflictException
     */
    public function handle(int $definitionId, int $accountId): void
    {
        $this->repository->findFirstWhere([
            ContactAttributeDefinitionDomainObject::ID => $definitionId,
            ContactAttributeDefinitionDomainObject::ACCOUNT_ID => $accountId,
        ]);

        $events = DB::table('questions as q')
            ->join('events as e', 'e.id', '=', 'q.event_id')
            ->where('q.contact_attribute_definition_id', $definitionId)
            ->whereNull('q.deleted_at')
            ->select('e.title')
            ->selectRaw('count(q.id) as question_count')
            ->groupBy('e.id', 'e.title')
            ->orderBy('e.title')
            ->get();

        if ($events->isNotEmpty()) {
            $summary = $events->map(function ($row) {
                $count = (int) $row->question_count;
                $label = $count > 1 ? " ({$count} questions)" : '';
                return $row->title . $label;
            })->implode(', ');

            throw new ResourceConflictException(__(
                'In use on :events. Unlink or delete those questions first.',
                ['events' => $summary],
            ));
        }

        $this->repository->deleteById($definitionId);
    }
}
