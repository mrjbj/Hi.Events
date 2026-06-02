<?php

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\ContactEmailConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\UpdateContactRequest;
use HiEvents\Resources\Contact\ContactResource;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpsertContactDTO;
use HiEvents\Services\Application\Handlers\Contact\UpdateContactHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateContactAction extends BaseAction
{
    public function __construct(
        private readonly UpdateContactHandler $handler,
    ) {}

    public function __invoke(UpdateContactRequest $request, int $accountId, int $contactId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        try {
            $contact = $this->handler->handle(
                contactId: $contactId,
                accountId: $this->getAuthenticatedAccountId(),
                userId: $this->getAuthenticatedUser()->getId(),
                dto: UpsertContactDTO::from(array_merge(
                    $request->validated(),
                    ['account_id' => $this->getAuthenticatedAccountId()],
                )),
            );
        } catch (ContactEmailConflictException $e) {
            throw ValidationException::withMessages([
                'email' => [__('Another contact in this account already uses this email address.')],
            ]);
        }

        return $this->resourceResponse(ContactResource::class, $contact);
    }
}
