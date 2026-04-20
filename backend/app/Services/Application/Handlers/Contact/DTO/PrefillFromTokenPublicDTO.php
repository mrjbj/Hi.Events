<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

final readonly class PrefillFromTokenPublicDTO
{
    public function __construct(
        public int $eventId,
        public string $token,
    ) {}
}
