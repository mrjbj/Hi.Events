<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OutgoingTransactionMessageDomainObject;
use HiEvents\DomainObjects\Status\OutgoingTransactionMessageStatus;
use HiEvents\Models\OutgoingTransactionMessage;
use HiEvents\Repository\Interfaces\OutgoingTransactionMessageRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * @extends BaseRepository<OutgoingTransactionMessageDomainObject>
 */
class OutgoingTransactionMessageRepository extends BaseRepository implements OutgoingTransactionMessageRepositoryInterface
{
    protected function getModel(): string
    {
        return OutgoingTransactionMessage::class;
    }

    public function getDomainObject(): string
    {
        return OutgoingTransactionMessageDomainObject::class;
    }

    public function findBySesMessageId(string $sesMessageId): ?OutgoingTransactionMessageDomainObject
    {
        return $this->findFirstWhere([
            'ses_message_id' => $sesMessageId,
            'status' => OutgoingTransactionMessageStatus::SENT->value,
        ]);
    }

    public function markAsBounced(int $id): void
    {
        $this->updateWhere(
            attributes: ['status' => OutgoingTransactionMessageStatus::BOUNCED->value],
            where: ['id' => $id],
        );
    }

    public function getFailuresForEvent(int $eventId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->paginateWhere(
            where: [
                'event_id' => $eventId,
                [
                    'status',
                    'in',
                    [
                        OutgoingTransactionMessageStatus::BOUNCED->value,
                        OutgoingTransactionMessageStatus::FAILED->value,
                        OutgoingTransactionMessageStatus::SUPPRESSED->value,
                    ],
                ],
            ],
            limit: $perPage,
        );
    }

    public function findAccountIdByRecipientEmail(string $email): ?int
    {
        $result = DB::table('outgoing_transaction_messages')
            ->join('events', 'outgoing_transaction_messages.event_id', '=', 'events.id')
            ->where('outgoing_transaction_messages.recipient', strtolower($email))
            ->orderByDesc('outgoing_transaction_messages.created_at')
            ->select('events.account_id')
            ->first();

        return $result?->account_id;
    }
}
