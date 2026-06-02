<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use Illuminate\Http\JsonResponse;

class GetBackfillSummaryAction extends BaseAction
{
    public function __construct(
        private readonly ContactBackfillService $service,
    ) {}

    public function __invoke(int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        return $this->jsonResponse([
            'data' => $this->service->getSummaryCounts($this->getAuthenticatedAccountId()),
        ]);
    }
}
