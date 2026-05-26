<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email;

use HiEvents\DomainObjects\Generated\OutgoingMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OutgoingMessageEventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OutgoingTransactionMessageDomainObjectAbstract;
use HiEvents\Repository\Interfaces\OutgoingMessageEventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingTransactionMessageRepositoryInterface;
use Illuminate\Log\Logger;
use Throwable;

class OutgoingMessageEventLogger
{
    public function __construct(
        private readonly OutgoingMessageEventRepositoryInterface       $eventRepository,
        private readonly OutgoingMessageRepositoryInterface            $outgoingMessageRepository,
        private readonly OutgoingTransactionMessageRepositoryInterface $outgoingTransactionMessageRepository,
        private readonly Logger                                        $logger,
    )
    {
    }

    public function log(
        string  $eventType,
        ?string $eventSubtype,
        ?string $providerMessageId,
        ?string $snsMessageId,
        array   $rawPayload,
        ?string $occurredAt,
        string  $provider = 'ses',
    ): void
    {
        try {
            [$outgoingMessageId, $outgoingTransactionMessageId] = $this->resolveMessageIds($providerMessageId);

            $this->eventRepository->create([
                OutgoingMessageEventDomainObjectAbstract::OUTGOING_MESSAGE_ID => $outgoingMessageId,
                OutgoingMessageEventDomainObjectAbstract::OUTGOING_TRANSACTION_MESSAGE_ID => $outgoingTransactionMessageId,
                OutgoingMessageEventDomainObjectAbstract::PROVIDER => $provider,
                OutgoingMessageEventDomainObjectAbstract::EVENT_TYPE => $eventType,
                OutgoingMessageEventDomainObjectAbstract::EVENT_SUBTYPE => $eventSubtype,
                OutgoingMessageEventDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
                OutgoingMessageEventDomainObjectAbstract::SNS_MESSAGE_ID => $snsMessageId,
                OutgoingMessageEventDomainObjectAbstract::RAW_PAYLOAD => $rawPayload,
                OutgoingMessageEventDomainObjectAbstract::OCCURRED_AT => $occurredAt,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to log outgoing message event', [
                'event_type' => $eventType,
                'provider_message_id' => $providerMessageId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveMessageIds(?string $providerMessageId): array
    {
        if (!$providerMessageId) {
            return [null, null];
        }

        $outgoingMessage = $this->outgoingMessageRepository->findFirstWhere([
            OutgoingMessageDomainObjectAbstract::SES_MESSAGE_ID => $providerMessageId,
        ]);

        $transactionMessage = $this->outgoingTransactionMessageRepository->findFirstWhere([
            OutgoingTransactionMessageDomainObjectAbstract::SES_MESSAGE_ID => $providerMessageId,
        ]);

        return [
            $outgoingMessage?->getId(),
            $transactionMessage?->getId(),
        ];
    }
}
