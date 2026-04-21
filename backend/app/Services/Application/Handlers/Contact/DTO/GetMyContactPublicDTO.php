<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

final readonly class GetMyContactPublicDTO
{
    public function __construct(
        public string $token,
        public ?int $eventId = null,
    ) {}
}
