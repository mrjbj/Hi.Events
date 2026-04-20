<?php

namespace Tests\Unit\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\PrefillFromTokenPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\PrefillFromTokenPublicHandler;
use HiEvents\Services\Domain\Contact\ContactPrefillService;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\DTO\ContactTokenPayload;
use Mockery as m;
use Tests\TestCase;

class PrefillFromTokenPublicHandlerTest extends TestCase
{
    private EventRepositoryInterface $eventRepository;
    private ContactRepositoryInterface $contactRepository;
    private ContactSignedTokenService $tokenService;
    private ContactPrefillService $prefillService;
    private PrefillFromTokenPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->contactRepository = m::mock(ContactRepositoryInterface::class);
        $this->tokenService = m::mock(ContactSignedTokenService::class);
        $this->prefillService = m::mock(ContactPrefillService::class);
        $this->handler = new PrefillFromTokenPublicHandler(
            $this->eventRepository,
            $this->contactRepository,
            $this->tokenService,
            $this->prefillService,
        );
    }

    public function testReturnsFoundFalseWhenTokenIsInvalid(): void
    {
        $this->tokenService->shouldReceive('verify')->with('bad-token')->andReturn(null);

        $result = $this->handler->handle(new PrefillFromTokenPublicDTO(eventId: 10, token: 'bad-token'));

        $this->assertFalse($result->found);
    }

    public function testThrowsWhenTokenAccountDoesNotMatchEventAccount(): void
    {
        $payload = new ContactTokenPayload(contactId: 42, accountId: 7, expiresAt: time() + 3600, nonce: 'abc');
        $this->tokenService->shouldReceive('verify')->andReturn($payload);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(99); // different account!
        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle(new PrefillFromTokenPublicDTO(eventId: 10, token: 'ok'));
    }

    public function testReturnsFullPayloadIncludingQuestionAnswersWhenTokenValid(): void
    {
        $payload = new ContactTokenPayload(contactId: 42, accountId: 7, expiresAt: time() + 3600, nonce: 'abc');
        $this->tokenService->shouldReceive('verify')->andReturn($payload);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(7);
        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getAccountId')->andReturn(7);
        $contact->shouldReceive('getFirstName')->andReturn('Alice');
        $contact->shouldReceive('getLastName')->andReturn('Smith');
        $this->contactRepository->shouldReceive('findFirst')->with(42)->andReturn($contact);

        $this->prefillService
            ->shouldReceive('resolveForContact')
            ->with($contact, 10)
            ->andReturn(['answers' => ['3' => 'Clayton'], 'answered_ids' => [3]]);

        $result = $this->handler->handle(new PrefillFromTokenPublicDTO(eventId: 10, token: 'ok'));

        $this->assertTrue($result->found);
        $this->assertSame('Alice', $result->first_name);
        $this->assertSame('Smith', $result->last_name);
        $this->assertSame(['3' => 'Clayton'], $result->question_answers);
        $this->assertSame([3], $result->answered_question_ids);
    }

    public function testReturnsFoundFalseWhenContactMissing(): void
    {
        $payload = new ContactTokenPayload(contactId: 42, accountId: 7, expiresAt: time() + 3600, nonce: 'abc');
        $this->tokenService->shouldReceive('verify')->andReturn($payload);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(7);
        $this->eventRepository->shouldReceive('findFirst')->andReturn($event);

        $this->contactRepository->shouldReceive('findFirst')->with(42)->andReturn(null);

        $result = $this->handler->handle(new PrefillFromTokenPublicDTO(eventId: 10, token: 'ok'));

        $this->assertFalse($result->found);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
