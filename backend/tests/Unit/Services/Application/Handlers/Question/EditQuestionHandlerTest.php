<?php

namespace Tests\Unit\Services\Application\Handlers\Question;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;
use HiEvents\DomainObjects\QuestionAnswerDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Application\Handlers\Question\DTO\UpsertQuestionDTO;
use HiEvents\Services\Application\Handlers\Question\EditQuestionHandler;
use HiEvents\Services\Domain\Question\EditQuestionService;
use HiEvents\Services\Domain\Question\QuestionContactAttributeLinker;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mockery as m;
use Tests\TestCase;

class EditQuestionHandlerTest extends TestCase
{
    private EditQuestionService $editQuestionService;

    private QuestionContactAttributeLinker $linker;

    private QuestionRepositoryInterface $questionRepository;

    private QuestionAnswerRepositoryInterface $questionAnswerRepository;

    private EditQuestionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editQuestionService = m::mock(EditQuestionService::class);
        $this->linker = m::mock(QuestionContactAttributeLinker::class);
        $this->questionRepository = m::mock(QuestionRepositoryInterface::class);
        $this->questionAnswerRepository = m::mock(QuestionAnswerRepositoryInterface::class);

        $this->handler = new EditQuestionHandler(
            $this->editQuestionService,
            $this->linker,
            $this->questionRepository,
            $this->questionAnswerRepository,
        );
    }

    public function test_same_type_skips_guard_and_passes_through(): void
    {
        $dto = $this->buildDto(QuestionTypeEnum::SINGLE_LINE_TEXT);

        $this->questionRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 7, 'event_id' => 10])
            ->andReturn(
                (new QuestionDomainObject())->setId(7)->setType(QuestionTypeEnum::SINGLE_LINE_TEXT->name)
            );

        $this->questionAnswerRepository->shouldNotReceive('findWhere');

        $this->linker
            ->shouldReceive('validate')
            ->once();

        $this->editQuestionService
            ->shouldReceive('editQuestion')
            ->once()
            ->andReturn(new QuestionDomainObject());

        $this->handler->handle(7, $dto);

        $this->assertTrue(true);
    }

    public function test_type_change_with_no_answers_passes_through(): void
    {
        $dto = $this->buildDto(QuestionTypeEnum::DROPDOWN);

        $this->questionRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(
                (new QuestionDomainObject())->setId(7)->setType(QuestionTypeEnum::RADIO->name)
            );

        $this->questionAnswerRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with(['question_id' => 7])
            ->andReturn(new Collection());

        $this->linker->shouldReceive('validate')->once();
        $this->editQuestionService->shouldReceive('editQuestion')->once()->andReturn(new QuestionDomainObject());

        $this->handler->handle(7, $dto);

        $this->assertTrue(true);
    }

    public function test_type_change_with_existing_answers_throws_without_force(): void
    {
        $dto = $this->buildDto(QuestionTypeEnum::CHECKBOX);

        $this->questionRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(
                (new QuestionDomainObject())->setId(7)->setType(QuestionTypeEnum::SINGLE_LINE_TEXT->name)
            );

        $this->questionAnswerRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn(new Collection([new QuestionAnswerDomainObject()]));

        $this->linker->shouldNotReceive('validate');
        $this->editQuestionService->shouldNotReceive('editQuestion');

        $this->expectException(ValidationException::class);

        $this->handler->handle(7, $dto);
    }

    public function test_force_type_change_bypasses_guard(): void
    {
        $dto = $this->buildDto(QuestionTypeEnum::CHECKBOX, forceTypeChange: true);

        $this->questionRepository->shouldNotReceive('findFirstWhere');
        $this->questionAnswerRepository->shouldNotReceive('findWhere');

        $this->linker->shouldReceive('validate')->once();
        $this->editQuestionService->shouldReceive('editQuestion')->once()->andReturn(new QuestionDomainObject());

        $this->handler->handle(7, $dto);

        $this->assertTrue(true);
    }

    public function test_missing_existing_question_skips_guard(): void
    {
        $dto = $this->buildDto(QuestionTypeEnum::CHECKBOX);

        $this->questionRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn(null);

        $this->questionAnswerRepository->shouldNotReceive('findWhere');

        $this->linker->shouldReceive('validate')->once();
        $this->editQuestionService->shouldReceive('editQuestion')->once()->andReturn(new QuestionDomainObject());

        $this->handler->handle(7, $dto);

        $this->assertTrue(true);
    }

    private function buildDto(QuestionTypeEnum $type, bool $forceTypeChange = false): UpsertQuestionDTO
    {
        return new UpsertQuestionDTO(
            title: 'Sample question',
            type: $type,
            required: false,
            options: null,
            event_id: 10,
            product_ids: [],
            is_hidden: false,
            belongs_to: QuestionBelongsTo::ORDER,
            description: null,
            contact_attribute_definition_id: null,
            force_type_change: $forceTypeChange,
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
