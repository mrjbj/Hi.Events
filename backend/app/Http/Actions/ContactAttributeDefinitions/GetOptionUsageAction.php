<?php

namespace HiEvents\Http\Actions\ContactAttributeDefinitions;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Contact\ContactAttributeOptionsService;
use Illuminate\Http\JsonResponse;

class GetOptionUsageAction extends BaseAction
{
    public function __construct(
        private readonly ContactAttributeOptionsService $service,
    ) {
    }

    public function __invoke(int $accountId, int $definitionId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class);

        $counts = $this->service->countOptionUsage($accountId, $definitionId);

        return $this->jsonResponse(['data' => $counts]);
    }
}
