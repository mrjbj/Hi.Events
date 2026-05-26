<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Services\Domain\Contact\ContactBackfillService;

readonly class ApplyStaleValueRemapsHandler
{
    public function __construct(
        private ContactBackfillService $service,
    ) {}

    /**
     * @param  array<array{contact_id:int,attribute_name:string,new_value?:mixed,add_values_to_options?:string[]}>  $remaps
     * @return array{attributes_written:int,options_added:int}
     */
    public function handle(int $accountId, array $remaps, int $userId): array
    {
        return $this->service->applyStaleValueRemaps($accountId, $remaps, $userId);
    }
}
