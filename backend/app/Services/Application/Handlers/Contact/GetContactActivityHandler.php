<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Application\Handlers\Contact\DTO\ContactActivityDTO;

readonly class GetContactActivityHandler
{
    public function __construct(
        private ContactRepositoryInterface $contactRepository,
    ) {}

    public function handle(int $contactId, int $accountId): ContactActivityDTO
    {
        return $this->contactRepository->getActivity($contactId, $accountId);
    }
}
