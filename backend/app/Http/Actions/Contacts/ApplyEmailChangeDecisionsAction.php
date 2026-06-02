<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\ApplyEmailChangeDecisionsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApplyEmailChangeDecisionsAction extends BaseAction
{
    public function __construct(
        private readonly ApplyEmailChangeDecisionsHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        $validated = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.attendee_id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', Rule::in(['update', 'split', 'ignore'])],
        ]);

        $count = $this->handler->handle(
            accountId: $this->getAuthenticatedAccountId(),
            decisions: $validated['decisions'],
            userId: $this->getAuthenticatedUser()->getId(),
        );

        return $this->jsonResponse(['data' => ['count' => $count]]);
    }
}
