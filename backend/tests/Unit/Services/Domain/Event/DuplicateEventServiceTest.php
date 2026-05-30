<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\CapacityAssignment\CreateCapacityAssignmentService;
use HiEvents\Services\Domain\CheckInList\CreateCheckInListService;
use HiEvents\Services\Domain\CreateWebhookService;
use HiEvents\Services\Domain\Event\CreateEventService;
use HiEvents\Services\Domain\Event\DuplicateEventService;
use HiEvents\Services\Domain\Product\CreateProductService;
use HiEvents\Services\Domain\ProductCategory\CreateProductCategoryService;
use HiEvents\Services\Domain\PromoCode\CreatePromoCodeService;
use HiEvents\Services\Domain\Question\CreateQuestionService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class DuplicateEventServiceTest extends TestCase
{
    public function test_duplicating_questions_preserves_contact_attribute_link(): void
    {
        $linkedQuestion = (new QuestionDomainObject)
            ->setId(1)
            ->setTitle('Country')
            ->setBelongsTo(QuestionBelongsTo::ORDER->name)
            ->setType('DROPDOWN')
            ->setRequired(false)
            ->setOptions(['US', 'Singapore'])
            ->setIsHidden(false)
            ->setContactAttributeDefinitionId(42);

        $sourceEvent = (new EventDomainObject)
            ->setId(10)
            ->setAccountId(1)
            ->setUserId(5)
            ->setOrganizerId(3)
            ->setTitle('Original')
            ->setStartDate('2025-01-01 00:00:00')
            ->setTimezone('UTC')
            ->setCurrency('USD')
            ->setStatus('DRAFT')
            ->setQuestions(collect([$linkedQuestion]));

        $newEvent = (new EventDomainObject)->setId(20)->setAccountId(1);

        $eventRepository = m::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        // First call returns the source event, second (post-commit) the new event.
        $eventRepository->shouldReceive('findFirstWhere')->andReturn($sourceEvent, $newEvent);

        $createEventService = m::mock(CreateEventService::class);
        $createEventService->shouldReceive('createEvent')->andReturn($newEvent);

        $createQuestionService = m::mock(CreateQuestionService::class);
        $captured = null;
        $createQuestionService->shouldReceive('createQuestion')
            ->once()
            ->withArgs(function (QuestionDomainObject $question, array $productIds) use (&$captured) {
                $captured = $question;

                return true;
            })
            ->andReturn($linkedQuestion);

        $createProductCategoryService = m::mock(CreateProductCategoryService::class);
        $createProductCategoryService->shouldReceive('createDefaultProductCategory');

        $databaseManager = m::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('beginTransaction');
        $databaseManager->shouldReceive('commit');
        $databaseManager->shouldReceive('rollBack');

        $purifier = m::mock(HtmlPurifierService::class);
        $purifier->shouldReceive('purify')->andReturnUsing(fn ($v) => $v);

        $service = new DuplicateEventService(
            $eventRepository,
            $createEventService,
            m::mock(CreateProductService::class),
            $createQuestionService,
            m::mock(CreatePromoCodeService::class),
            m::mock(CreateCapacityAssignmentService::class),
            m::mock(CreateCheckInListService::class),
            m::mock(ImageRepositoryInterface::class),
            $databaseManager,
            $purifier,
            $createProductCategoryService,
            m::mock(CreateWebhookService::class),
            m::mock(AffiliateRepositoryInterface::class),
        );

        $service->duplicateEvent(
            eventId: '10',
            accountId: '1',
            title: 'Copy',
            startDate: '2026-01-01 00:00:00',
            duplicateProducts: false,
            duplicateQuestions: true,
            duplicateSettings: false,
            duplicatePromoCodes: false,
            duplicateCapacityAssignments: false,
            duplicateCheckInLists: false,
            duplicateEventCoverImage: false,
            duplicateTicketLogo: false,
            duplicateWebhooks: false,
            duplicateAffiliates: false,
        );

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured->getContactAttributeDefinitionId(), 'Duplicated question must keep its contact-attribute link.');
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
