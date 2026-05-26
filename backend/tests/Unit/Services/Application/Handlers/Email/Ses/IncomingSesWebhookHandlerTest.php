<?php

namespace Tests\Unit\Services\Application\Handlers\Email\Ses;

use HiEvents\Exceptions\SnsSignatureVerificationException;
use HiEvents\Services\Application\Handlers\Email\Ses\DTO\SesWebhookDTO;
use HiEvents\Services\Application\Handlers\Email\Ses\IncomingSesWebhookHandler;
use HiEvents\Services\Domain\Email\OutgoingMessageEventLogger;
use HiEvents\Services\Domain\Email\Ses\EventHandlers\BounceHandler;
use HiEvents\Services\Domain\Email\Ses\EventHandlers\ComplaintHandler;
use HiEvents\Services\Domain\Email\Ses\EventHandlers\DeliveryHandler;
use HiEvents\Services\Infrastructure\Aws\SnsSignatureVerificationService;
use Illuminate\Cache\Repository;
use Illuminate\Log\Logger;
use JsonException;
use Mockery as m;
use Tests\TestCase;

class IncomingSesWebhookHandlerTest extends TestCase
{
    private BounceHandler $bounceHandler;
    private ComplaintHandler $complaintHandler;
    private DeliveryHandler $deliveryHandler;
    private OutgoingMessageEventLogger $eventLogger;
    private SnsSignatureVerificationService $signatureService;
    private Logger $logger;
    private Repository $cache;
    private IncomingSesWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bounceHandler = m::mock(BounceHandler::class);
        $this->complaintHandler = m::mock(ComplaintHandler::class);
        $this->deliveryHandler = m::mock(DeliveryHandler::class);
        $this->eventLogger = m::mock(OutgoingMessageEventLogger::class)->shouldIgnoreMissing();
        $this->signatureService = m::mock(SnsSignatureVerificationService::class);
        $this->logger = m::mock(Logger::class)->shouldIgnoreMissing();
        $this->cache = m::mock(Repository::class);

        $this->handler = new IncomingSesWebhookHandler(
            $this->bounceHandler,
            $this->complaintHandler,
            $this->deliveryHandler,
            $this->eventLogger,
            $this->signatureService,
            $this->logger,
            $this->cache,
        );
    }

    public function testHandlesBounceNotificationSuccessfully(): void
    {
        $innerMessage = json_encode([
            'notificationType' => 'Bounce',
            'bounce' => ['bouncedRecipients' => [['emailAddress' => 'test@example.com']]],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-123',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-123')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->bounceHandler->shouldReceive('handle')
            ->once()
            ->withArgs(function ($message, $snsPayload) {
                return $message['notificationType'] === 'Bounce';
            });

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testHandlesComplaintNotificationSuccessfully(): void
    {
        $innerMessage = json_encode([
            'notificationType' => 'Complaint',
            'complaint' => ['complainedRecipients' => [['emailAddress' => 'test@example.com']]],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-456',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-456')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->complaintHandler->shouldReceive('handle')
            ->once()
            ->withArgs(function ($message) {
                return $message['notificationType'] === 'Complaint';
            });

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testHandlesDeliveryNotificationSuccessfully(): void
    {
        $innerMessage = json_encode([
            'notificationType' => 'Delivery',
            'delivery' => ['recipients' => ['test@example.com']],
            'mail' => ['messageId' => 'ses-msg-001'],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-delivery-1',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-delivery-1')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->deliveryHandler->shouldReceive('handle')
            ->once()
            ->withArgs(function ($message) {
                return $message['notificationType'] === 'Delivery';
            });

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testSkipsDuplicateSnsMessage(): void
    {
        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-duplicate',
            'Message' => '{}',
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-duplicate')->andReturn(true);

        $this->bounceHandler->shouldNotReceive('handle');
        $this->complaintHandler->shouldNotReceive('handle');

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testRejectsMismatchedTopicArn(): void
    {
        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-789',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:wrong-topic',
            'Message' => '{}',
        ]);

        $this->signatureService->shouldReceive('verify')->once();

        config(['services.ses.sns_topic_arn' => 'arn:aws:sns:us-east-1:123:correct-topic']);

        $this->bounceHandler->shouldNotReceive('handle');
        $this->complaintHandler->shouldNotReceive('handle');

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);

        $this->handler->handle(new SesWebhookDTO(payload: 'not valid json'));
    }

    public function testRejectsInvalidSnsSignature(): void
    {
        $payload = json_encode(['Type' => 'Notification', 'MessageId' => 'msg-bad']);

        $this->signatureService->shouldReceive('verify')
            ->once()
            ->andThrow(new SnsSignatureVerificationException('Signature invalid'));

        $this->bounceHandler->shouldNotReceive('handle');

        $this->expectException(SnsSignatureVerificationException::class);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testSkipsNonNotificationType(): void
    {
        $payload = json_encode([
            'Type' => 'UnsubscribeConfirmation',
            'MessageId' => 'msg-unsub',
        ]);

        $this->signatureService->shouldReceive('verify')->once();

        $this->bounceHandler->shouldNotReceive('handle');
        $this->complaintHandler->shouldNotReceive('handle');

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testHandlesSubscriptionConfirmationWithValidUrl(): void
    {
        $payload = json_encode([
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/confirm?token=abc',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:test',
        ]);

        $this->signatureService->shouldReceive('verify')->once();

        \Illuminate\Support\Facades\Http::fake([
            'sns.us-east-1.amazonaws.com/*' => \Illuminate\Support\Facades\Http::response('OK', 200),
        ]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));

        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function testRejectsSubscriptionConfirmationWithInvalidUrl(): void
    {
        $payload = json_encode([
            'Type' => 'SubscriptionConfirmation',
            'SubscribeURL' => 'http://evil.com/steal-data',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:test',
        ]);

        $this->signatureService->shouldReceive('verify')->once();

        \Illuminate\Support\Facades\Http::fake();

        $this->handler->handle(new SesWebhookDTO(payload: $payload));

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function testLogsDeliveryDelayWithoutInvokingSubHandlers(): void
    {
        $innerMessage = json_encode([
            'eventType' => 'DeliveryDelay',
            'mail' => ['messageId' => 'ses-delay-1', 'timestamp' => '2026-05-26T10:00:00.000Z'],
            'deliveryDelay' => ['delayType' => 'Throttling', 'timestamp' => '2026-05-26T10:00:01.000Z'],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-delay-1',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-delay-1')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->eventLogger->shouldReceive('log')
            ->once()
            ->withArgs(function (...$args) {
                return $args[0] === 'DeliveryDelay' && $args[1] === 'Throttling' && $args[2] === 'ses-delay-1';
            });

        $this->bounceHandler->shouldNotReceive('handle');
        $this->complaintHandler->shouldNotReceive('handle');
        $this->deliveryHandler->shouldNotReceive('handle');

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testLogsBeforeDispatchingBounce(): void
    {
        $innerMessage = json_encode([
            'notificationType' => 'Bounce',
            'mail' => ['messageId' => 'ses-bounce-1', 'timestamp' => '2026-05-26T10:00:00.000Z'],
            'bounce' => [
                'bounceType' => 'Permanent',
                'timestamp' => '2026-05-26T10:00:02.000Z',
                'bouncedRecipients' => [['emailAddress' => 'test@example.com']],
            ],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-bounce-1',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-bounce-1')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->eventLogger->shouldReceive('log')
            ->once()
            ->withArgs(function (...$args) {
                return $args[0] === 'Bounce' && $args[1] === 'Permanent';
            });

        $this->bounceHandler->shouldReceive('handle')->once();

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }

    public function testLogsUnknownEventTypeWithoutDispatch(): void
    {
        $innerMessage = json_encode([
            'eventType' => 'Open',
            'mail' => ['messageId' => 'ses-open-1', 'timestamp' => '2026-05-26T10:00:00.000Z'],
        ]);

        $payload = json_encode([
            'Type' => 'Notification',
            'MessageId' => 'msg-open-1',
            'Message' => $innerMessage,
        ]);

        $this->signatureService->shouldReceive('verify')->once();
        $this->cache->shouldReceive('has')->with('ses_sns_message_msg-open-1')->andReturn(false);
        $this->cache->shouldReceive('put')->once();

        $this->eventLogger->shouldReceive('log')
            ->once()
            ->withArgs(function (...$args) {
                return $args[0] === 'Open';
            });

        $this->bounceHandler->shouldNotReceive('handle');
        $this->complaintHandler->shouldNotReceive('handle');
        $this->deliveryHandler->shouldNotReceive('handle');

        config(['services.ses.sns_topic_arn' => null]);

        $this->handler->handle(new SesWebhookDTO(payload: $payload));
    }
}
