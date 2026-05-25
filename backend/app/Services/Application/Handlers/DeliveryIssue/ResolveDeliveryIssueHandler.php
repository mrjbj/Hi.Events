<?php

namespace HiEvents\Services\Application\Handlers\DeliveryIssue;

use HiEvents\DomainObjects\Generated\OutgoingMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OutgoingTransactionMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailSuppressionReasonEnum;
use HiEvents\DomainObjects\Status\EmailSuppressionSourceEnum;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingTransactionMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\ResendOutgoingMessageHandler;
use HiEvents\Services\Application\Handlers\TransactionMessage\ResendTransactionMessageHandler;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResolveDeliveryIssueHandler
{
    public const SOURCE_TRANSACTION = 'transaction';
    public const SOURCE_ANNOUNCEMENT = 'announcement';

    public function __construct(
        private readonly OutgoingMessageRepositoryInterface             $outgoingMessageRepository,
        private readonly OutgoingTransactionMessageRepositoryInterface  $transactionMessageRepository,
        private readonly EventRepositoryInterface                       $eventRepository,
        private readonly ContactRepositoryInterface                     $contactRepository,
        private readonly AttendeeRepositoryInterface                    $attendeeRepository,
        private readonly EmailSuppressionService                        $suppressionService,
        private readonly ResendOutgoingMessageHandler                   $resendOutgoingHandler,
        private readonly ResendTransactionMessageHandler                $resendTransactionHandler,
        private readonly DatabaseManager                                $db,
    )
    {
    }

    /**
     * Orchestrates the resolve-delivery-issue cascade:
     *   1. (if email changed) update Contact + linked Attendees + suppress old address
     *   2. (if $resend) delegate to the appropriate resend handler
     *
     * The cascade and the resend run in the same DB transaction so a mailer
     * failure rolls back the email mutation.
     *
     * @throws ContactEmailConflictException
     * @throws ValidationException
     * @throws Throwable
     */
    public function handle(int $eventId, int $messageId, string $sourceType, ?string $newEmail, bool $resend): mixed
    {
        $event = $this->eventRepository->findById($eventId);
        if ($event === null) {
            throw ValidationException::withMessages(['message' => [__('Event not found')]]);
        }
        $accountId = $event->getAccountId();

        $oldEmail = $sourceType === self::SOURCE_TRANSACTION
            ? $this->lookupTransactionRecipient($eventId, $messageId)
            : $this->lookupOutgoingRecipient($eventId, $messageId);

        $emailChanging = $newEmail !== null && strtolower($newEmail) !== strtolower($oldEmail);

        return $this->db->transaction(function () use (
            $eventId, $messageId, $sourceType, $newEmail, $resend, $oldEmail, $accountId, $emailChanging,
        ) {
            if ($emailChanging) {
                $this->cascadeEmailChange($oldEmail, $newEmail, $accountId);
            }

            if (!$resend) {
                return null;
            }

            return $sourceType === self::SOURCE_TRANSACTION
                ? $this->resendTransactionHandler->handle($eventId, $messageId, $newEmail)
                : $this->resendOutgoingHandler->handle($eventId, $messageId, $newEmail);
        });
    }

    /**
     * @throws ContactEmailConflictException
     */
    private function cascadeEmailChange(string $oldEmail, string $newEmail, int $accountId): void
    {
        $contactByNew = $this->contactRepository->findByEmailAndAccountId($newEmail, $accountId);
        $contactByOld = $this->contactRepository->findByEmailAndAccountId($oldEmail, $accountId);

        if ($contactByNew !== null && (!$contactByOld || $contactByNew->getId() !== $contactByOld->getId())) {
            throw new ContactEmailConflictException();
        }

        if ($contactByOld !== null) {
            try {
                $this->contactRepository->updateEmail($contactByOld->getId(), $newEmail);
            } catch (Throwable $e) {
                if ((string)$e->getCode() === '23505') {
                    throw new ContactEmailConflictException();
                }
                throw $e;
            }
            $this->attendeeRepository->updateEmailByContactId($contactByOld->getId(), $newEmail, $accountId);
        }

        $this->suppressionService->suppressEmail(
            $oldEmail,
            EmailSuppressionReasonEnum::BOUNCE->value,
            EmailSuppressionSourceEnum::MANUAL_RESOLVE->value,
            $accountId,
        );
    }

    /**
     * @throws ValidationException
     */
    private function lookupOutgoingRecipient(int $eventId, int $messageId): string
    {
        $message = $this->outgoingMessageRepository->findFirstWhere([
            OutgoingMessageDomainObjectAbstract::ID => $messageId,
            OutgoingMessageDomainObjectAbstract::EVENT_ID => $eventId,
        ]);
        if ($message === null) {
            throw ValidationException::withMessages(['message' => [__('Outgoing message not found')]]);
        }
        return $message->getRecipient();
    }

    /**
     * @throws ValidationException
     */
    private function lookupTransactionRecipient(int $eventId, int $messageId): string
    {
        $message = $this->transactionMessageRepository->findFirstWhere([
            OutgoingTransactionMessageDomainObjectAbstract::ID => $messageId,
            OutgoingTransactionMessageDomainObjectAbstract::EVENT_ID => $eventId,
        ]);
        if ($message === null) {
            throw ValidationException::withMessages(['message' => [__('Transaction message not found')]]);
        }
        return $message->getRecipient();
    }
}
