<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\OutgoingTransactionMessageDomainObject;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<OutgoingTransactionMessageDomainObject>
 */
interface OutgoingTransactionMessageRepositoryInterface extends RepositoryInterface
{
    public function findBySesMessageId(string $sesMessageId): ?OutgoingTransactionMessageDomainObject;

    public function markAsBounced(int $id): void;

    public function getFailuresForEvent(int $eventId, int $perPage = 20): LengthAwarePaginator;

    public function findAccountIdByRecipientEmail(string $email): ?int;
}
