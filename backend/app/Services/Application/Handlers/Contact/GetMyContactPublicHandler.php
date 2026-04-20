<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\GetMyContactPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\DTO\MyContactResultDTO;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Illuminate\Support\Facades\DB;

readonly class GetMyContactPublicHandler
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
        private ContactSignedTokenService $tokenService,
    ) {}

    public function handle(GetMyContactPublicDTO $dto): MyContactResultDTO
    {
        $payload = $this->tokenService->verify($dto->token);
        if ($payload === null) {
            return new MyContactResultDTO(found: false);
        }

        $contact = $this->contactRepository->findFirst($payload->contactId);
        if ($contact === null || $contact->getAccountId() !== $payload->accountId) {
            return new MyContactResultDTO(found: false);
        }

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
            first_name: $contact->getFirstName(),
            last_name: $contact->getLastName(),
            attributes: ContactBackfillService::normalizeAttributes($contact->getAttributes()),
            attribute_definitions: $definitions,
        );
    }
}
