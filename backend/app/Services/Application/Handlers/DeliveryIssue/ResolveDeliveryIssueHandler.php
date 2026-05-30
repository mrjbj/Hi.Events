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
        private readonly OutgoingMessageRepositoryInterface $outgoingMessageRepository,
        private readonly OutgoingTransactionMessageRepositoryInterface $transactionMessageRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EmailSuppressionService $suppressionService,
        private readonly ResendOutgoingMessageHandler $resendOutgoingHandler,
        private readonly ResendTransactionMessageHandler $resendTransactionHandler,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Orchestrates resolving a delivery issue:
     *   1. (if email changed) move the Contact to the corrected address + suppress
     *      the old one. Linked attendee rows are NOT rewritten — each is the
     *      historical fact of its event; same-event sends reach the person via the
     *      suppressed→contact fallback in SendEventEmailMessagesService.
     *   2. (if $resend) delegate to the appropriate resend handler.
     *
     * The contact update and the resend run in the same DB transaction so a mailer
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

            if (! $resend) {
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

        if ($contactByNew !== null && (! $contactByOld || $contactByNew->getId() !== $contactByOld->getId())) {
            throw new ContactEmailConflictException;
        }

        if ($contactByOld !== null) {
            try {
                // Move the contact (the canonical current address) to the corrected
                // email. We deliberately do NOT rewrite the linked attendee rows:
                // each attendee.email is the historical fact of what was used at
                // that event. The old address is suppressed below, and same-event
                // sends fall back to the contact's current email when an attendee's
                // own address is suppressed (see SendEventEmailMessagesService).
                $this->contactRepository->updateEmail($contactByOld->getId(), $newEmail);
            } catch (Throwable $e) {
                if ((string) $e->getCode() === '23505') {
                    throw new ContactEmailConflictException;
                }
                throw $e;
            }
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
