<?php

namespace HiEvents\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Domain\Question\EditQuestionService;
use HiEvents\Services\Domain\Question\QuestionContactAttributeLinker;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditQuestionHandler
{
    public function __construct(
        private readonly EditQuestionService              $editQuestionService,
        private readonly QuestionContactAttributeLinker   $contactAttributeLinker,
        private readonly QuestionRepositoryInterface      $questionRepository,
        private readonly QuestionAnswerRepositoryInterface $questionAnswerRepository,
    )
    {
    }

    /**
     * @throws Throwable
     */
    public function handle(int $questionId, UpsertQuestionDTO $createQuestionDTO): QuestionDomainObject
    {
        $this->guardTypeChange($questionId, $createQuestionDTO);

        $this->contactAttributeLinker->validate(
            eventId: $createQuestionDTO->event_id,
            definitionId: $createQuestionDTO->contact_attribute_definition_id,
            questionType: $createQuestionDTO->type,
        );

        $question = (new QuestionDomainObject())
            ->setId($questionId)
            ->setTitle($createQuestionDTO->title)
            ->setEventId($createQuestionDTO->event_id)
            ->setBelongsTo($createQuestionDTO->belongs_to->name)
            ->setType($createQuestionDTO->type->name)
            ->setRequired($createQuestionDTO->required)
            ->setOptions($createQuestionDTO->options)
            ->setIsHidden($createQuestionDTO->is_hidden)
            ->setDescription($createQuestionDTO->description)
            ->setContactAttributeDefinitionId($createQuestionDTO->contact_attribute_definition_id);

        return $this->editQuestionService->editQuestion(
            question: $question,
            productIds: $createQuestionDTO->product_ids,
        );
    }

    /**
     * @throws ValidationException
     */
    private function guardTypeChange(int $questionId, UpsertQuestionDTO $dto): void
    {
        if ($dto->force_type_change) {
            return;
        }

        $existing = $this->questionRepository->findFirstWhere([
            'id' => $questionId,
            'event_id' => $dto->event_id,
        ]);

        if ($existing === null || $existing->getType() === $dto->type->name) {
            return;
        }

        $hasAnswers = $this->questionAnswerRepository
            ->findWhere(['question_id' => $questionId])
            ->isNotEmpty();

        if (! $hasAnswers) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => __('Changing the question type with existing answers may corrupt how those answers display. Confirm the change to proceed.'),
        ]);
    }
}
