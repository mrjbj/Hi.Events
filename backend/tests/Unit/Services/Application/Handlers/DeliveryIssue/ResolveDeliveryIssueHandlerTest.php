<?php

namespace Tests\Unit\Services\Application\Handlers\DeliveryIssue;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OutgoingMessageDomainObject;
use HiEvents\DomainObjects\Status\EmailSuppressionReasonEnum;
use HiEvents\DomainObjects\Status\EmailSuppressionSourceEnum;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingTransactionMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\DeliveryIssue\ResolveDeliveryIssueHandler;
use HiEvents\Services\Application\Handlers\Message\ResendOutgoingMessageHandler;
use HiEvents\Services\Application\Handlers\TransactionMessage\ResendTransactionMessageHandler;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class ResolveDeliveryIssueHandlerTest extends TestCase
{
    private OutgoingMessageRepositoryInterface $outgoingRepo;

    private OutgoingTransactionMessageRepositoryInterface $transactionRepo;

    private EventRepositoryInterface $eventRepo;

    private ContactRepositoryInterface $contactRepo;

    private AttendeeRepositoryInterface $attendeeRepo;

    private EmailSuppressionService $suppressionService;

    private ResendOutgoingMessageHandler $resendOutgoing;

    private ResendTransactionMessageHandler $resendTransaction;

    private DatabaseManager $db;

    private ResolveDeliveryIssueHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outgoingRepo = m::mock(OutgoingMessageRepositoryInterface::class);
        $this->transactionRepo = m::mock(OutgoingTransactionMessageRepositoryInterface::class);
        $this->eventRepo = m::mock(EventRepositoryInterface::class);
        $this->contactRepo = m::mock(ContactRepositoryInterface::class);
        $this->attendeeRepo = m::mock(AttendeeRepositoryInterface::class);
        $this->suppressionService = m::mock(EmailSuppressionService::class);
        $this->resendOutgoing = m::mock(ResendOutgoingMessageHandler::class);
        $this->resendTransaction = m::mock(ResendTransactionMessageHandler::class);

        $this->db = m::mock(DatabaseManager::class);
        $this->db->shouldReceive('transaction')->andReturnUsing(fn ($cb) => $cb());

        $this->handler = new ResolveDeliveryIssueHandler(
            $this->outgoingRepo,
            $this->transactionRepo,
            $this->eventRepo,
            $this->contactRepo,
            $this->attendeeRepo,
            $this->suppressionService,
            $this->resendOutgoing,
            $this->resendTransaction,
            $this->db,
        );
    }

    public function test_announcement_email_change_updates_contact_and_resends_without_cascade(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);
        $this->eventRepo->shouldReceive('findById')->with(7)->andReturn($event);

        $outgoing = m::mock(OutgoingMessageDomainObject::class);
        $outgoing->shouldReceive('getRecipient')->andReturn('old@example.com');
        $this->outgoingRepo->shouldReceive('findFirstWhere')->once()->andReturn($outgoing);

        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getId')->andReturn(99);

        $this->contactRepo->shouldReceive('findByEmailAndAccountId')
            ->with('new@example.com', 42)->andReturn(null);
        $this->contactRepo->shouldReceive('findByEmailAndAccountId')
            ->with('old@example.com', 42)->andReturn($contact);
        $this->contactRepo->shouldReceive('updateEmail')->once()->with(99, 'new@example.com');
        // Resolving a bounce corrects the contact (canonical) address and suppresses
        // the dead one; it must NOT rewrite linked attendee rows. Same-event sends
        // reach the person via the suppressed→contact fallback in the send service.
        $this->attendeeRepo->shouldNotReceive('updateEmailByContactId');

        $this->suppressionService->shouldReceive('suppressEmail')
            ->once()
            ->with('old@example.com', EmailSuppressionReasonEnum::BOUNCE->value, EmailSuppressionSourceEnum::MANUAL_RESOLVE->value, 42);

        $resent = m::mock(OutgoingMessageDomainObject::class);
        $this->resendOutgoing->shouldReceive('handle')
            ->once()->with(7, 123, 'new@example.com')->andReturn($resent);

        $result = $this->handler->handle(7, 123, ResolveDeliveryIssueHandler::SOURCE_ANNOUNCEMENT, 'new@example.com', true);

        $this->assertSame($resent, $result);
    }

    public function test_email_unchanged_skips_cascade_but_still_resends(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);
        $this->eventRepo->shouldReceive('findById')->andReturn($event);

        $outgoing = m::mock(OutgoingMessageDomainObject::class);
        $outgoing->shouldReceive('getRecipient')->andReturn('same@example.com');
        $this->outgoingRepo->shouldReceive('findFirstWhere')->once()->andReturn($outgoing);

        // No cascade: contactRepo / attendeeRepo / suppressionService never called.
        $resent = m::mock(OutgoingMessageDomainObject::class);
        $this->resendOutgoing->shouldReceive('handle')
            ->once()->with(7, 123, 'same@example.com')->andReturn($resent);

        $result = $this->handler->handle(7, 123, ResolveDeliveryIssueHandler::SOURCE_ANNOUNCEMENT, 'same@example.com', true);

        $this->assertSame($resent, $result);
    }

    public function test_email_conflict_throws_and_does_not_resend(): void
    {
        $this->expectException(ContactEmailConflictException::class);

        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);
        $this->eventRepo->shouldReceive('findById')->andReturn($event);

        $outgoing = m::mock(OutgoingMessageDomainObject::class);
        $outgoing->shouldReceive('getRecipient')->andReturn('old@example.com');
        $this->outgoingRepo->shouldReceive('findFirstWhere')->once()->andReturn($outgoing);

        $existingNew = m::mock(ContactDomainObject::class);
        $existingNew->shouldReceive('getId')->andReturn(999);
        $existingOld = m::mock(ContactDomainObject::class);
        $existingOld->shouldReceive('getId')->andReturn(99);

        $this->contactRepo->shouldReceive('findByEmailAndAccountId')
            ->with('taken@example.com', 42)->andReturn($existingNew);
        $this->contactRepo->shouldReceive('findByEmailAndAccountId')
            ->with('old@example.com', 42)->andReturn($existingOld);

        // Resend should NOT be called.
        $this->resendOutgoing->shouldNotReceive('handle');

        $this->handler->handle(7, 123, ResolveDeliveryIssueHandler::SOURCE_ANNOUNCEMENT, 'taken@example.com', true);
    }

    public function test_resend_false_skips_resend(): void
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getAccountId')->andReturn(42);
        $this->eventRepo->shouldReceive('findById')->andReturn($event);

        $outgoing = m::mock(OutgoingMessageDomainObject::class);
        $outgoing->shouldReceive('getRecipient')->andReturn('old@example.com');
        $this->outgoingRepo->shouldReceive('findFirstWhere')->once()->andReturn($outgoing);

        $this->contactRepo->shouldReceive('findByEmailAndAccountId')->andReturn(null);
        $this->suppressionService->shouldReceive('suppressEmail')->once();

        $this->resendOutgoing->shouldNotReceive('handle');

        $result = $this->handler->handle(7, 123, ResolveDeliveryIssueHandler::SOURCE_ANNOUNCEMENT, 'new@example.com', false);
        $this->assertNull($result);
    }
}
