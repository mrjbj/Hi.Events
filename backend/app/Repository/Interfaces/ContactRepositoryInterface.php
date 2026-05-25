<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<ContactDomainObject>
 */
interface ContactRepositoryInterface extends RepositoryInterface
{
    public function findByAccountId(int $accountId, QueryParamsDTO $params): LengthAwarePaginator;

    public function findByEmailAndAccountId(string $email, int $accountId): ?ContactDomainObject;

    /**
     * Lowercases the email and writes it. Appends an entry to attributes_history
     * (old_email, changed_at, reason) so the prior address is preserved for audit.
     */
    public function updateEmail(int $contactId, string $email, string $reason = 'manual_resolve'): void;
}
