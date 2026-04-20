<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact\DTO;

final readonly class ContactTokenPayload
{
    public function __construct(
        public int $contactId,
        public int $accountId,
        public int $expiresAt,
        public string $nonce,
    ) {}
}
