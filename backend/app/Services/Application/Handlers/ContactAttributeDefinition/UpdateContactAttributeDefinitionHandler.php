<?php

namespace HiEvents\Services\Application\Handlers\ContactAttributeDefinition;

use HiEvents\DomainObjects\ContactAttributeDefinitionDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\ContactAttributeDefinitionRepositoryInterface;
use HiEvents\Services\Application\Handlers\ContactAttributeDefinition\DTO\UpsertContactAttributeDefinitionDTO;
use HiEvents\Services\Domain\Contact\ContactAttributeOptionsService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

readonly class UpdateContactAttributeDefinitionHandler
{
    public function __construct(
        private ContactAttributeDefinitionRepositoryInterface $repository,
        private ContactAttributeOptionsService                $optionsService,
        private DatabaseManager                               $db,
    ) {
    }

    /**
     * @throws ResourceConflictException
     * @throws ValidationException
     */
    public function handle(int $definitionId, UpsertContactAttributeDefinitionDTO $dto): ContactAttributeDefinitionDomainObject
    {
        $existing = $this->repository->findFirstWhere([
            ContactAttributeDefinitionDomainObject::ID => $definitionId,
            ContactAttributeDefinitionDomainObject::ACCOUNT_ID => $dto->account_id,
        ]);

        $duplicate = $this->repository->findFirstWhere([
            ContactAttributeDefinitionDomainObject::ACCOUNT_ID => $dto->account_id,
            ContactAttributeDefinitionDomainObject::NAME => $dto->name,
        ]);

        if ($duplicate !== null && $duplicate->getId() !== $definitionId) {
            throw new ResourceConflictException(
                __('An attribute definition with this name already exists.')
            );
        }

        $migrations = $dto->wasProvided('option_migrations') && is_array($dto->option_migrations)
            ? $dto->option_migrations
            : [];

        if ($dto->wasProvided('options') && $existing !== null) {
            $this->guardRemovedOptionsHaveMigrationPlan(
                $dto->account_id,
                $definitionId,
                $existing->getOptions() ?? [],
                $dto->options ?? [],
                $migrations,
            );
        }

        return $this->db->transaction(function () use ($definitionId, $dto, $migrations) {
            if ($migrations !== []) {
                $this->optionsService->applyMigrations($dto->account_id, $definitionId, $migrations);
            }

            $updates = [
                ContactAttributeDefinitionDomainObject::NAME => $dto->name,
                ContactAttributeDefinitionDomainObject::LABEL => $dto->label,
                ContactAttributeDefinitionDomainObject::TYPE => $dto->type,
            ];

            if ($dto->wasProvided('options')) {
                $updates[ContactAttributeDefinitionDomainObject::OPTIONS] = $dto->options;
            }
            if ($dto->wasProvided('sort_order')) {
                $updates[ContactAttributeDefinitionDomainObject::SORT_ORDER] = $dto->sort_order;
            }
            if ($dto->wasProvided('is_active')) {
                $updates[ContactAttributeDefinitionDomainObject::IS_ACTIVE] = $dto->is_active;
            }
            if ($dto->wasProvided('is_globally_recommended')) {
                $updates[ContactAttributeDefinitionDomainObject::IS_GLOBALLY_RECOMMENDED] = $dto->is_globally_recommended;
            }

            $this->repository->updateFromArray($definitionId, $updates);

            if ($dto->wasProvided('options')) {
                $this->optionsService->cascadeOptionsToLinkedQuestions($definitionId, $dto->options ?? []);
            }

            return $this->repository->findById($definitionId);
        });
    }

    /**
     * If options the admin is removing are referenced by existing data, require a migration plan
     * for each. Otherwise reject the update so we don't silently orphan values.
     *
     * @throws ValidationException
     */
    private function guardRemovedOptionsHaveMigrationPlan(
        int   $accountId,
        int   $definitionId,
        array $previousOptions,
        array $newOptions,
        array $migrations,
    ): void {
        $removed = array_values(array_diff($previousOptions, $newOptions));
        if ($removed === []) {
            return;
        }

        $usage = $this->optionsService->countOptionUsage($accountId, $definitionId);
        $migrationFroms = array_column($migrations, 'from');

        $unplanned = [];
        foreach ($removed as $value) {
            $count = (int) ($usage[$value] ?? 0);
            if ($count > 0 && ! in_array($value, $migrationFroms, true)) {
                $unplanned[$value] = $count;
            }
        }

        if ($unplanned === []) {
            return;
        }

        throw ValidationException::withMessages([
            'options' => __('Some removed options still have answers and need a migration plan: :options', [
                'options' => collect($unplanned)
                    ->map(fn ($count, $value) => "$value ($count)")
                    ->values()
                    ->implode(', '),
            ]),
        ]);
    }
}
