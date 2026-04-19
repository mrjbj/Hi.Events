<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\MyContactResultDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateMyContactPublicDTO;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use HiEvents\Services\Domain\Contact\ContactUpsertService;
use Illuminate\Support\Facades\DB;

readonly class UpdateMyContactPublicHandler
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
        private ContactSignedTokenService $tokenService,
        private ContactUpsertService $contactUpsertService,
    ) {}

    public function handle(UpdateMyContactPublicDTO $dto): MyContactResultDTO
    {
        $payload = $this->tokenService->verify($dto->token);
        if ($payload === null) {
            return new MyContactResultDTO(found: false);
        }

        $contact = $this->contactRepository->findFirst($payload->contactId);
        if ($contact === null || $contact->getAccountId() !== $payload->accountId) {
            return new MyContactResultDTO(found: false);
        }

        $nameUpdates = [];
        if ($dto->firstName !== null && $dto->firstName !== '') {
            $nameUpdates[ContactDomainObject::FIRST_NAME] = $dto->firstName;
        }
        if ($dto->lastName !== null && $dto->lastName !== '') {
            $nameUpdates[ContactDomainObject::LAST_NAME] = $dto->lastName;
        }

        if (!empty($nameUpdates)) {
            $this->contactRepository->updateFromArray($contact->getId(), $nameUpdates);
        }

        if (!empty($dto->attributes)) {
            $validAttributeNames = DB::table('contact_attribute_definitions')
                ->where('account_id', $payload->accountId)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->flip()
                ->toArray();

            $whitelistedAttributes = [];
            foreach ($dto->attributes as $name => $value) {
                if (!array_key_exists($name, $validAttributeNames)) {
                    continue;
                }
                $whitelistedAttributes[$name] = $value;
            }

            if (!empty($whitelistedAttributes)) {
                // changedByUserId=0 marks this as a self-service edit (no staff user).
                $this->contactUpsertService->updateContactAttributes(
                    contact: $contact,
                    newAttributes: $whitelistedAttributes,
                    changedByUserId: 0,
                );
            }
        }

        $updatedContact = $this->contactRepository->findFirst($payload->contactId);

        $definitions = DB::table('contact_attribute_definitions')
            ->where('account_id', $payload->accountId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'options'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'type' => $row->type,
                'options' => $row->options !== null ? (json_decode($row->options, true) ?: []) : [],
            ])
            ->toArray();

        return new MyContactResultDTO(
            found: true,
            first_name: $updatedContact?->getFirstName(),
            last_name: $updatedContact?->getLastName(),
            attributes: $updatedContact !== null
                ? ContactBackfillService::normalizeAttributes($updatedContact->getAttributes())
                : [],
            attribute_definitions: $definitions,
        );
    }
}
