<?php

namespace Tests\Unit\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\LookupContactByEmailPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\LookupContactByEmailPublicHandler;
use HiEvents\Services\Domain\Contact\ContactPrefillService;
use Mockery as m;
use Tests\TestCase;

class LookupContactByEmailPublicHandlerTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;
    private ContactRepositoryInterface $contactRepository;
    private ContactPrefillService $prefillService;
    private LookupContactByEmailPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->contactRepository = m::mock(ContactRepositoryInterface::class);
        $this->prefillService = m::mock(ContactPrefillService::class);
        $this->prefillService->shouldReceive('resolveForContact')
            ->andReturn(['answers' => [], 'answered_ids' => []])->byDefault();
        $this->handler = new LookupContactByEmailPublicHandler(
            $this->eventRepository,
            $this->contactRepository,
            $this->prefillService,
        );
    }

    public function testReturnsFoundFalseWhenEventDoesNotExist(): void
    {
        $this->eventRepository
            ->shouldReceive('findFirst')
            ->once()
            ->with(99)
            ->andReturn(null);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 99,
            email: 'alice@example.com',
        ));

        $this->assertFalse($result->found);
        $this->assertNull($result->first_name);
        $this->assertNull($result->last_name);
    }

    public function testReturnsFoundFalseWhenContactDoesNotExistForAccount(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);

        $this->eventRepository
            ->shouldReceive('findFirst')
            ->once()
            ->with(10)
            ->andReturn($event);

        $this->contactRepository
            ->shouldReceive('findByEmailAndAccountId')
            ->once()
            ->with('nobody@example.com', 42)
            ->andReturn(null);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 10,
            email: 'nobody@example.com',
        ));

        $this->assertFalse($result->found);
    }

    public function testReturnsNameOnlyWhenContactIsFound(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getFirstName')->andReturn('Alice');
        $contact->shouldReceive('getLastName')->andReturn('Smith');

        $this->eventRepository
            ->shouldReceive('findFirst')
            ->once()
            ->with(10)
            ->andReturn($event);

        $this->contactRepository
            ->shouldReceive('findByEmailAndAccountId')
            ->once()
            ->with('alice@example.com', 42)
            ->andReturn($contact);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 10,
            email: 'alice@example.com',
        ));

        $this->assertTrue($result->found);
        $this->assertSame('Alice', $result->first_name);
        $this->assertSame('Smith', $result->last_name);
    }

    public function testResponseArrayDoesNotContainQuestionAnswers(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getFirstName')->andReturn('Alice');
        $contact->shouldReceive('getLastName')->andReturn('Smith');

        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn($contact);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 10,
            email: 'alice@example.com',
        ));

        $array = $result->toArray();
        $this->assertArrayNotHasKey('question_answers', $array);
        $this->assertArrayHasKey('found', $array);
        $this->assertArrayHasKey('first_name', $array);
        $this->assertArrayHasKey('last_name', $array);
        $this->assertArrayHasKey('answered_question_ids', $array);
    }

    public function testReturnsAnsweredQuestionIdsWhenContactHasLinkedAttributes(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getFirstName')->andReturn('Alice');
        $contact->shouldReceive('getLastName')->andReturn('Smith');

        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn($contact);

        $this->prefillService
            ->shouldReceive('resolveForContact')
            ->once()
            ->with($contact, 10)
            ->andReturn(['answers' => ['3' => 'Clayton', '7' => '5'], 'answered_ids' => [3, 7]]);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 10,
            email: 'alice@example.com',
        ));

        $this->assertSame([3, 7], $result->answered_question_ids);
        // answer VALUES must never leak on the public lookup response
        $array = $result->toArray();
        $this->assertArrayNotHasKey('question_answers', $array);
    }

    public function testContactLookupIsScopedToEventAccount(): void
    {
        // Same email, two different accounts — the handler must query only the
        // event's account, not leak across.
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(7); // event belongs to account 7

        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);

        $this->contactRepository
            ->shouldReceive('findByEmailAndAccountId')
            ->once()
            ->with('alice@example.com', 7)  // must be queried under account 7, not any other
            ->andReturn(null);

        $result = $this->handler->handle(new LookupContactByEmailPublicDTO(
            eventId: 10,
            email: 'alice@example.com',
        ));

        $this->assertFalse($result->found);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
