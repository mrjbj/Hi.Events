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
 * When a product (ticket-type) is added to an event, attaches every contact
 * attribute definition marked is_globally_recommended as a PRODUCT-level
 * question on that event, linked to the new product via the product_questions
 * pivot. Per-attendee scope is appropriate for attributes like role, county,
 * or precinct, where each attendee has their own value.
 *
 * The existing prefill/hide/autofill pipeline picks these up automatically:
 *   - Returning contacts have their stored value filled on submit via
 *     ContactAutofillService.
 *   - The frontend hides those questions from the form via
 *     answered_question_ids on /contact-lookup.
 *   - New contacts see the question and their answer gets synced back to
 *     the contact attribute via ContactBackfillService::applyOrderAnswers.
 *
 * Dedup: if a question already exists on the event for the same attribute
 * definition (from a prior product or a manually-added question), we attach
 * the new product to the existing question rather than creating a duplicate.
 */
class GloballyRecommendedAttributesService
{
    public function __construct(
        private readonly QuestionRepositoryInterface $questionRepository,
    ) {}

    /**
     * Called when a product is created. Creates PRODUCT-level questions for
     * every globally-recommended attribute on the account that isn't already
     * represented on this event. Then links each question to this product.
     *
     * @return int Number of new questions created + existing questions linked.
     */
    public function attachToProduct(int $productId, int $eventId, int $accountId): int
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

        // Existing questions on this event already linked to any of these
        // attribute definitions. We'll reuse them (by attaching the new
        // product) rather than creating duplicates.
        $definitionIds = $definitions->pluck('id')->all();
        $existing = DB::table('questions')
            ->where('event_id', $eventId)
            ->whereIn('contact_attribute_definition_id', $definitionIds)
            ->whereNull('deleted_at')
            ->pluck('id', 'contact_attribute_definition_id');

        $touched = 0;
        foreach ($definitions as $definition) {
            try {
                $existingQuestionId = $existing[$definition->id] ?? null;
                if ($existingQuestionId !== null) {
                    $this->linkProductToExistingQuestion(
                        questionId: (int) $existingQuestionId,
                        productId: $productId,
                    );
                } else {
                    $this->questionRepository->create([
                        QuestionDomainObjectAbstract::EVENT_ID => $eventId,
                        QuestionDomainObjectAbstract::CONTACT_ATTRIBUTE_DEFINITION_ID => (int) $definition->id,
                        QuestionDomainObjectAbstract::TITLE => $definition->label ?: $definition->name,
                        QuestionDomainObjectAbstract::TYPE => self::mapDefinitionTypeToQuestionType($definition->type),
                        QuestionDomainObjectAbstract::OPTIONS => $definition->options !== null
                            ? json_decode($definition->options, true)
                            : null,
                        QuestionDomainObjectAbstract::BELONGS_TO => QuestionBelongsTo::PRODUCT->name,
                        QuestionDomainObjectAbstract::REQUIRED => false,
                    ], [$productId]);
                }
                $touched++;
            } catch (\Throwable $e) {
                Log::warning('GloballyRecommendedAttributesService: failed to attach question to product', [
                    'product_id' => $productId,
                    'event_id' => $eventId,
                    'definition_id' => $definition->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $touched;
    }

    private function linkProductToExistingQuestion(int $questionId, int $productId): void
    {
        $alreadyLinked = DB::table('product_questions')
            ->where('question_id', $questionId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->exists();

        if ($alreadyLinked) {
            return;
        }

        DB::table('product_questions')->insert([
            'question_id' => $questionId,
            'product_id' => $productId,
        ]);
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
