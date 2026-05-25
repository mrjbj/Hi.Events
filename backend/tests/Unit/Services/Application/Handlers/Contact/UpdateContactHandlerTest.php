<?php

namespace Tests\Unit\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpsertContactDTO;
use HiEvents\Services\Application\Handlers\Contact\UpdateContactHandler;
use HiEvents\Services\Domain\Contact\ContactUpsertService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class UpdateContactHandlerTest extends TestCase
{
    private ContactRepositoryInterface $contactRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private ContactUpsertService $upsertService;
    private UpdateContactHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contactRepository = m::mock(ContactRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->upsertService = m::mock(ContactUpsertService::class);
        $this->handler = new UpdateContactHandler(
            $this->contactRepository,
            $this->attendeeRepository,
            $this->upsertService,
        );

        // Make DB::transaction() execute its closure inline.
        $manager = m::mock(DatabaseManager::class);
        $manager->shouldReceive('transaction')->andReturnUsing(fn ($cb) => $cb());
        $this->app->instance('db', $manager);
    }

    public function testEmailChangeCascadesToContactAndAttendees(): void
    {
        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getEmail')->andReturn('old@example.com');

        $updatedContact = m::mock(ContactDomainObject::class);

        $this->contactRepository->shouldReceive('findFirstWhere')->once()->andReturn($contact);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')
            ->once()->with('new@example.com', 42)->andReturn(null);
        $this->contactRepository->shouldReceive('updateEmail')
            ->once()->with(7, 'new@example.com');
        $this->attendeeRepository->shouldReceive('updateEmailByContactId')
            ->once()->with(7, 'new@example.com', 42)->andReturn(2);
        $this->contactRepository->shouldReceive('findById')->once()->with(7)->andReturn($updatedContact);

        $dto = UpsertContactDTO::from([
            'account_id' => 42,
            'email' => 'new@example.com',
        ]);

        $result = $this->handler->handle(7, 42, 1, $dto);
        $this->assertSame($updatedContact, $result);
    }

    public function testEmailConflictThrows(): void
    {
        $this->expectException(ContactEmailConflictException::class);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getEmail')->andReturn('old@example.com');

        $other = m::mock(ContactDomainObject::class);
        $other->shouldReceive('getId')->andReturn(99);

        $this->contactRepository->shouldReceive('findFirstWhere')->once()->andReturn($contact);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')
            ->once()->with('taken@example.com', 42)->andReturn($other);

        $dto = UpsertContactDTO::from([
            'account_id' => 42,
            'email' => 'taken@example.com',
        ]);

        $this->handler->handle(7, 42, 1, $dto);
    }

    public function testNoEmailChangeWhenSameAddress(): void
    {
        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getEmail')->andReturn('same@example.com');

        $this->contactRepository->shouldReceive('findFirstWhere')->once()->andReturn($contact);
        // No conflict check, no updateEmail, no attendee update.

        $dto = UpsertContactDTO::from([
            'account_id' => 42,
            'email' => 'SAME@example.com',
        ]);

        $result = $this->handler->handle(7, 42, 1, $dto);
        $this->assertSame($contact, $result);
    }
}
