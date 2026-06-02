<?php

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\ContactMergeException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\MergeContactsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MergeContactsAction extends BaseAction
{
    public function __construct(
        private readonly MergeContactsHandler $handler,
    ) {}

    public function __invoke(int $accountId, int $contactId, Request $request): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        $validated = $request->validate([
            'source_contact_id' => ['required', 'integer'],
        ]);

        try {
            $contact = $this->handler->handle(
                survivorId: $contactId,
                sourceId: (int) $validated['source_contact_id'],
                accountId: $this->getAuthenticatedAccountId(),
            );
        } catch (ContactMergeException $e) {
            throw ValidationException::withMessages([
                'source_contact_id' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(ContactResource::class, $contact);
    }
}
