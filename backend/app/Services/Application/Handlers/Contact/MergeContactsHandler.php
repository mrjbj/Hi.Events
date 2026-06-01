<?php

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Exceptions\ContactMergeException;
use HiEvents\Services\Domain\Contact\MergeContactsService;
use Throwable;

readonly class MergeContactsHandler
{
    public function __construct(
        private MergeContactsService $mergeContactsService,
    ) {}

    /**
     * @throws ContactMergeException|Throwable
     */
    public function handle(int $survivorId, int $sourceId, int $accountId): ContactDomainObject
    {
        return $this->mergeContactsService->merge($survivorId, $sourceId, $accountId);
    }
}
