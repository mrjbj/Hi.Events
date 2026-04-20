<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\Generated\QuestionDomainObjectAbstract;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a new event is created, attaches every contact attribute definition
 * marked is_globally_recommended as an ORDER-level question on the event.
 * Each created question is linked back to its definition via
 * contact_attribute_definition_id, so the existing prefill/hide/autofill
 * pipeline picks it up automatically:
 *
 *  - Returning contacts have their stored value filled on submit via
 *    ContactAutofillService.
 *  - The frontend hides those questions from the form via
 *    answered_question_ids on /contact-lookup.
 *  - New contacts see the question and their answer gets synced back to
 *    the contact attribute via ContactBackfillService::applyOrderAnswers.
 */
class GloballyRecommendedAttributesService
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questionRepository,
    ) {}

    /**
     * @return int Number of questions auto-attached to the event.
     */
    public function attachToEvent(int $eventId, int $accountId): int
    {
        $definitions = DB::table('contact_attribute_definitions')
            ->where('account_id', $accountId)
            ->where('is_globally_recommended', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'label', 'type', 'options']);

        if ($definitions->isEmpty()) {
            return 0;
        }

        $created = 0;
        foreach ($definitions as $definition) {
            try {
                $this->questionRepository->create([
                    QuestionDomainObjectAbstract::EVENT_ID => $eventId,
                    QuestionDomainObjectAbstract::CONTACT_ATTRIBUTE_DEFINITION_ID => (int) $definition->id,
                    QuestionDomainObjectAbstract::TITLE => $definition->label ?: $definition->name,
                    QuestionDomainObjectAbstract::TYPE => self::mapDefinitionTypeToQuestionType($definition->type),
                    QuestionDomainObjectAbstract::OPTIONS => $definition->options !== null
                        ? json_decode($definition->options, true)
                        : null,
                    QuestionDomainObjectAbstract::BELONGS_TO => QuestionBelongsTo::ORDER->name,
                    QuestionDomainObjectAbstract::REQUIRED => false,
                ]);
                $created++;
            } catch (\Throwable $e) {
                Log::warning('GloballyRecommendedAttributesService: failed to attach question', [
                    'event_id' => $eventId,
                    'definition_id' => $definition->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    private static function mapDefinitionTypeToQuestionType(?string $defType): string
    {
        return match ($defType) {
            'select' => QuestionTypeEnum::DROPDOWN->name,
            'multi_select' => QuestionTypeEnum::MULTI_SELECT_DROPDOWN->name,
            default => QuestionTypeEnum::SINGLE_LINE_TEXT->name,
        };
    }
}
