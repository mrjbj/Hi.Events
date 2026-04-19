<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact\DTO;

final readonly class UpdateMyContactPublicDTO
{
    public function __construct(
        public string $token,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public array $attributes = [],
    ) {}
}
