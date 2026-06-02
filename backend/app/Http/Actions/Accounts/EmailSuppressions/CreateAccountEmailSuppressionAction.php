<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Accounts\EmailSuppressions;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\EmailSuppressionReasonEnum;
use HiEvents\DomainObjects\Status\EmailSuppressionSourceEnum;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Resources\Admin\EmailSuppressionResource;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreateAccountEmailSuppressionAction extends BaseAction
{
    public function __construct(
        private readonly EmailSuppressionService $emailSuppressionService,
    ) {}

    public function __invoke(Request $request, int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        // Account admins may only add a manual "do not contact" suppression for
        // their own account. Bounce/complaint rows are SES-derived and managed by
        // the platform (superadmin), not fabricated here.
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $suppression = $this->emailSuppressionService->suppressEmail(
            email: $validated['email'],
            reason: EmailSuppressionReasonEnum::DO_NOT_CONTACT->value,
            source: EmailSuppressionSourceEnum::MANUAL->value,
            accountId: $this->getAuthenticatedAccountId(),
        );

        return $this->resourceResponse(
            resource: EmailSuppressionResource::class,
            data: $suppression,
            statusCode: 201,
        );
    }
}
