<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactAttributeDefinitionDomainObject;
use HiEvents\Repository\Interfaces\ContactAttributeDefinitionRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

class ContactAttributeOptionsService
{
    public const MIGRATION_ACTION_RENAME = 'rename';

    public const MIGRATION_ACTION_DELETE = 'delete';

    public function __construct(
        private readonly ContactAttributeDefinitionRepositoryInterface $definitionRepository,
        private readonly DatabaseManager $db,
    ) {
    }

    /**
     * @return array<string, int> Map of option string → usage count across this account's data.
     *                            Counts include question_answers on every linked event question plus
     *                            stored values in each contact's attributes jsonb.
     */
    public function countOptionUsage(int $accountId, int $definitionId): array
    {
        $definition = $this->loadDefinition($accountId, $definitionId);
        $counts = [];

        $answerRows = DB::table('question_answers as qa')
            ->join('questions as q', 'q.id', '=', 'qa.question_id')
            ->where('q.contact_attribute_definition_id', $definitionId)
            ->whereNull('qa.deleted_at')
            ->select('qa.answer')
            ->get();

        foreach ($answerRows as $row) {
            foreach (self::extractStringValues($row->answer) as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        $contactRows = DB::table('contacts')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->whereRaw('attributes::jsonb ?? ?', [$definition->getName()])
            ->select('attributes')
            ->get();

        foreach ($contactRows as $row) {
            $attrs = json_decode($row->attributes ?? 'null', true);
            if (! is_array($attrs)) {
                continue;
            }
            $value = $attrs[$definition->getName()] ?? null;
            foreach (self::valueToList($value) as $v) {
                $counts[$v] = ($counts[$v] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<int, array{from: string, action: string, to?: string|null}> $migrations
     */
    public function applyMigrations(int $accountId, int $definitionId, array $migrations): void
    {
        if ($migrations === []) {
            return;
        }

        $definition = $this->loadDefinition($accountId, $definitionId);

        $this->db->transaction(function () use ($accountId, $definition, $migrations): void {
            $this->migrateQuestionAnswers($definition->getId(), $migrations);
            $this->migrateContactAttributes($accountId, $definition->getName(), $migrations);
        });
    }

    /**
     * Cascade option list updates to every event question linked to this definition.
     * Keeps checkout in sync with the canonical option list.
     */
    public function cascadeOptionsToLinkedQuestions(int $definitionId, array $options): void
    {
        DB::table('questions')
            ->where('contact_attribute_definition_id', $definitionId)
            ->whereNull('deleted_at')
            ->update([
                'options' => json_encode(array_values($options), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<int, array{from: string, action: string, to?: string|null}> $migrations
     */
    private function migrateQuestionAnswers(int $definitionId, array $migrations): void
    {
        $rows = DB::table('question_answers as qa')
            ->join('questions as q', 'q.id', '=', 'qa.question_id')
            ->where('q.contact_attribute_definition_id', $definitionId)
            ->whereNull('qa.deleted_at')
            ->select('qa.id', 'qa.answer')
            ->get();

        foreach ($rows as $row) {
            $decoded = json_decode($row->answer ?? 'null', true);
            [$newValue, $changed] = self::applyMigrationsToValue($decoded, $migrations);
            if (! $changed) {
                continue;
            }
            DB::table('question_answers')
                ->where('id', $row->id)
                ->update([
                    'answer' => $newValue === null ? null : json_encode($newValue, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param array<int, array{from: string, action: string, to?: string|null}> $migrations
     */
    private function migrateContactAttributes(int $accountId, string $attributeName, array $migrations): void
    {
        $contacts = DB::table('contacts')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->whereRaw('attributes::jsonb ?? ?', [$attributeName])
            ->select('id', 'attributes')
            ->get();

        foreach ($contacts as $contact) {
            $attrs = json_decode($contact->attributes ?? 'null', true);
            if (! is_array($attrs) || ! array_key_exists($attributeName, $attrs)) {
                continue;
            }
            [$newValue, $changed] = self::applyMigrationsToValue($attrs[$attributeName], $migrations);
            if (! $changed) {
                continue;
            }
            if ($newValue === null || $newValue === [] || $newValue === '') {
                unset($attrs[$attributeName]);
            } else {
                $attrs[$attributeName] = $newValue;
            }
            DB::table('contacts')
                ->where('id', $contact->id)
                ->update([
                    'attributes' => json_encode($attrs, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param mixed $value The decoded JSON value (string, array, null)
     * @param array<int, array{from: string, action: string, to?: string|null}> $migrations
     * @return array{0: mixed, 1: bool} [newValue, changed]
     */
    public static function applyMigrationsToValue(mixed $value, array $migrations): array
    {
        $changed = false;

        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $match = self::findMigration($item, $migrations);
                if ($match === null) {
                    $out[] = $item;
                    continue;
                }
                $changed = true;
                if ($match['action'] === self::MIGRATION_ACTION_RENAME && isset($match['to'])) {
                    $out[] = $match['to'];
                }
                // 'delete' just skips the item.
            }
            $out = array_values(array_unique($out, SORT_REGULAR));
            return [$out, $changed];
        }

        if (is_string($value)) {
            $match = self::findMigration($value, $migrations);
            if ($match === null) {
                return [$value, false];
            }
            if ($match['action'] === self::MIGRATION_ACTION_RENAME && isset($match['to'])) {
                return [$match['to'], true];
            }
            return [null, true];
        }

        return [$value, false];
    }

    /**
     * @param array<int, array{from: string, action: string, to?: string|null}> $migrations
     * @return array{from: string, action: string, to?: string|null}|null
     */
    private static function findMigration(mixed $value, array $migrations): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        foreach ($migrations as $m) {
            if (($m['from'] ?? null) === $value) {
                return $m;
            }
        }
        return null;
    }

    private function loadDefinition(int $accountId, int $definitionId): ContactAttributeDefinitionDomainObject
    {
        $definition = $this->definitionRepository->findById($definitionId);
        if ($definition === null || $definition->getAccountId() !== $accountId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                'Contact attribute definition not found for this account.'
            );
        }
        return $definition;
    }

    /**
     * Extract string values from a stored answer column (JSON-encoded scalar or array).
     */
    private static function extractStringValues(?string $answerJson): array
    {
        if ($answerJson === null) {
            return [];
        }
        $decoded = json_decode($answerJson, true);
        return self::valueToList($decoded);
    }

    /**
     * Normalize a decoded value to a flat list of string values.
     */
    private static function valueToList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                fn ($v) => is_string($v) && $v !== '',
            ));
        }
        if (is_string($value)) {
            return [$value];
        }
        return [];
    }
}
