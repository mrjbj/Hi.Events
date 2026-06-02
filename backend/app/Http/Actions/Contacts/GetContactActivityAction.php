<?php

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\GetContactActivityHandler;
use Illuminate\Http\JsonResponse;

class GetContactActivityAction extends BaseAction
{
    public function __construct(
        private readonly GetContactActivityHandler $handler,
    ) {}

    public function __invoke(int $accountId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class);

        return $this->jsonResponse([
            'data' => $this->handler->handle($contactId, $this->getAuthenticatedAccountId())->toArray(),
        ]);
    }
}
