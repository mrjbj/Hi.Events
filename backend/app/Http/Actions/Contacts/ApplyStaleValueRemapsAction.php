<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\ApplyStaleValueRemapsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplyStaleValueRemapsAction extends BaseAction
{
    public function __construct(
        private readonly ApplyStaleValueRemapsHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, int $accountId): JsonResponse
    {
        $this->isActionAuthorized($accountId, AccountDomainObject::class, Role::ADMIN);

        $validated = $request->validate([
            'remaps' => ['required', 'array', 'min:1'],
            'remaps.*.contact_id' => ['required', 'integer'],
            'remaps.*.attribute_name' => ['required', 'string', 'max:100'],
            // new_value is optional — null/empty clears the attribute. When present
            // it must be a string (for select / text) or an array of strings
            // (multi_select). Per-value option validation is done in the service
            // against the current definition's option list.
            'remaps.*.new_value' => ['nullable'],
            // add_values_to_options is optional — when present, those values are
            // appended to the attribute definition's option list before the write
            // pass runs. Lets the frontend offer "+ Add 'X' to options" inline.
            'remaps.*.add_values_to_options' => ['nullable', 'array'],
            'remaps.*.add_values_to_options.*' => ['string'],
        ]);

        $result = $this->handler->handle(
            accountId: $this->getAuthenticatedAccountId(),
            remaps: $validated['remaps'],
            userId: $this->getAuthenticatedUser()->getId(),
        );

        return $this->jsonResponse([
            'data' => [
                // `count` kept for backward compatibility with the original
                // frontend that only knew about attribute writes.
                'count' => $result['attributes_written'],
                'attributes_written' => $result['attributes_written'],
                'options_added' => $result['options_added'],
            ],
        ]);
    }
}
