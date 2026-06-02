<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Accounts\EmailSuppressions;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DeleteAccountEmailSuppressionAction extends BaseAction
{
    public function __construct(
        private readonly EmailSuppressionService $emailSuppressionService,
    ) {}

    public function __invoke(int $accountId, int $suppressionId): Response|JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        // Ownership-guarded: only this account's own rows can be lifted. A
        // platform-wide (null account_id) row or another account's row returns
        // 404 — lifting those is reserved for superadmin.
        $removed = $this->emailSuppressionService->removeAccountSuppressionById(
            $suppressionId,
            $this->getAuthenticatedAccountId(),
        );

        if (! $removed) {
            return $this->errorResponse(
                message: __('Suppression not found.'),
                statusCode: ResponseCodes::HTTP_NOT_FOUND,
            );
        }

        return $this->deletedResponse();
    }
}
