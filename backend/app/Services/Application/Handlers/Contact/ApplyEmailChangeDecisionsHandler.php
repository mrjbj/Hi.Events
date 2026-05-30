<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Contact;

use HiEvents\Services\Domain\Contact\ContactBackfillService;

readonly class ApplyEmailChangeDecisionsHandler
{
    public function __construct(
        private ContactBackfillService $service,
    ) {}

    /**
     * @param  array<array{attendee_id:int,decision:string}>  $decisions
     */
    public function handle(int $accountId, array $decisions, int $userId): int
    {
        return $this->service->applyEmailChangeDecisions($accountId, $decisions, $userId);
    }
}
