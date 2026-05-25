<?php

namespace HiEvents\Services\Application\Handlers\Message;

use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Mail\SendEventEmailMessagesService;
use Illuminate\Validation\ValidationException;

class PreflightSendMessageHandler
{
    public function __construct(
        private readonly SendEventEmailMessagesService      $sendService,
        private readonly OutgoingMessageRepositoryInterface $outgoingRepo,
        private readonly EventRepositoryInterface           $eventRepo,
    )
    {
    }

    /**
     * @throws ValidationException
     * @return array{unresolved_recipient_count:int, sample:string[], total_recipient_count:int}
     */
    public function handle(SendMessageDTO $messageData): array
    {
        $event = $this->eventRepo->findById($messageData->event_id);
        if ($event === null) {
            throw ValidationException::withMessages(['message' => [__('Event not found')]]);
        }

        $emails = $this->sendService->resolveAudienceEmails($messageData);
        if (empty($emails)) {
            return [
                'unresolved_recipient_count' => 0,
                'sample' => [],
                'total_recipient_count' => 0,
            ];
        }

        $stats = $this->outgoingRepo->countUnresolvedFailuresForEmails($emails, $event->getAccountId());

        return [
            'unresolved_recipient_count' => $stats['count'],
            'sample' => $stats['sample'],
            'total_recipient_count' => count($emails),
        ];
    }
}
