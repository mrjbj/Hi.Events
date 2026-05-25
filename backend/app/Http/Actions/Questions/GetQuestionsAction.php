<?php

namespace HiEvents\Http\Actions\Questions;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Resources\Question\QuestionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GetQuestionsAction extends BaseAction
{
    private QuestionRepositoryInterface $questionRepository;

    public function __construct(QuestionRepositoryInterface $questionRepository)
    {
        $this->questionRepository = $questionRepository;
    }

    public function __invoke(Request $request, int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $questions = $this->questionRepository
            ->loadRelation(
                new Relationship(ProductDomainObject::class, [
                    new Relationship(ProductPriceDomainObject::class)
                ])
            )
            ->findByEventId($eventId);

        $questionIds = $questions->map(fn (QuestionDomainObject $q) => $q->getId())->all();
        $counts = $questionIds === []
            ? []
            : DB::table('question_answers')
                ->select('question_id', DB::raw('COUNT(*) as answers_count'))
                ->whereIn('question_id', $questionIds)
                ->whereNull('deleted_at')
                ->groupBy('question_id')
                ->pluck('answers_count', 'question_id')
                ->all();

        $questions->each(function (QuestionDomainObject $q) use ($counts) {
            $q->setAnswersCount((int) ($counts[$q->getId()] ?? 0));
        });

        return $this->resourceResponse(QuestionResource::class, $questions);
    }
}
