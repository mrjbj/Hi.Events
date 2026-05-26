<?php

namespace Tests\Unit\Services\Domain\Email;

use HiEvents\DomainObjects\OutgoingMessageDomainObject;
use HiEvents\DomainObjects\OutgoingTransactionMessageDomainObject;
use HiEvents\Repository\Interfaces\OutgoingMessageEventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingTransactionMessageRepositoryInterface;
use HiEvents\Services\Domain\Email\OutgoingMessageEventLogger;
use Illuminate\Log\Logger;
use Mockery as m;
use RuntimeException;
use Tests\TestCase;

class OutgoingMessageEventLoggerTest extends TestCase
{
    private OutgoingMessageEventRepositoryInterface $eventRepository;
    private OutgoingMessageRepositoryInterface $outgoingMessageRepository;
    private OutgoingTransactionMessageRepositoryInterface $outgoingTransactionMessageRepository;
    private Logger $logger;
    private OutgoingMessageEventLogger $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventRepository = m::mock(OutgoingMessageEventRepositoryInterface::class);
        $this->outgoingMessageRepository = m::mock(OutgoingMessageRepositoryInterface::class);
        $this->outgoingTransactionMessageRepository = m::mock(OutgoingTransactionMessageRepositoryInterface::class);
        $this->logger = m::mock(Logger::class)->shouldIgnoreMissing();

        $this->service = new OutgoingMessageEventLogger(
            $this->eventRepository,
            $this->outgoingMessageRepository,
            $this->outgoingTransactionMessageRepository,
            $this->logger,
        );
    }

    public function testLogsWithResolvedOutgoingMessageId(): void
    {
        $message = (new OutgoingMessageDomainObject())->setId(42)->setEventId(1)->setMessageId(1)
            ->setSubject('s')->setRecipient('r')->setStatus('SENT');

        $this->outgoingMessageRepository->shouldReceive('findFirstWhere')->andReturn($message);
        $this->outgoingTransactionMessageRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->eventRepository->shouldReceive('create')
            ->once()
            ->withArgs(function ($attrs) {
                return $attrs['outgoing_message_id'] === 42
                    && $attrs['outgoing_transaction_message_id'] === null
                    && $attrs['event_type'] === 'Delivery'
                    && $attrs['provider_message_id'] === 'ses-123';
            });

        $this->service->log(
            eventType: 'Delivery',
            eventSubtype: null,
            providerMessageId: 'ses-123',
            snsMessageId: 'sns-1',
            rawPayload: ['Type' => 'Notification'],
            occurredAt: '2026-05-26T10:00:00Z',
        );
    }

    public function testLogsAsOrphanWhenNoProviderMessageId(): void
    {
        $this->outgoingMessageRepository->shouldNotReceive('findFirstWhere');
        $this->outgoingTransactionMessageRepository->shouldNotReceive('findFirstWhere');

        $this->eventRepository->shouldReceive('create')
            ->once()
            ->withArgs(function ($attrs) {
                return $attrs['outgoing_message_id'] === null
                    && $attrs['outgoing_transaction_message_id'] === null
                    && $attrs['event_type'] === 'Send';
            });

        $this->service->log(
            eventType: 'Send',
            eventSubtype: null,
            providerMessageId: null,
            snsMessageId: 'sns-2',
            rawPayload: [],
            occurredAt: null,
        );
    }

    public function testSwallowsRepositoryErrors(): void
    {
        $this->outgoingMessageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->outgoingTransactionMessageRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->eventRepository->shouldReceive('create')
            ->andThrow(new RuntimeException('db is down'));

        // Should not throw
        $this->service->log(
            eventType: 'DeliveryDelay',
            eventSubtype: 'Throttling',
            providerMessageId: 'ses-456',
            snsMessageId: 'sns-3',
            rawPayload: [],
            occurredAt: null,
        );

        $this->assertTrue(true);
    }

    public function testResolvesTransactionMessageId(): void
    {
        $txnMessage = (new OutgoingTransactionMessageDomainObject())->setId(99);

        $this->outgoingMessageRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->outgoingTransactionMessageRepository->shouldReceive('findFirstWhere')->andReturn($txnMessage);

        $this->eventRepository->shouldReceive('create')
            ->once()
            ->withArgs(function ($attrs) {
                return $attrs['outgoing_message_id'] === null
                    && $attrs['outgoing_transaction_message_id'] === 99;
            });

        $this->service->log(
            eventType: 'Bounce',
            eventSubtype: 'Permanent',
            providerMessageId: 'ses-789',
            snsMessageId: 'sns-4',
            rawPayload: [],
            occurredAt: null,
        );
    }
}
