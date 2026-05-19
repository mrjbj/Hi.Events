<?php

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListSiblingsPublicHandler;
use Illuminate\Http\JsonResponse;

class GetCheckInListSiblingsPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetCheckInListSiblingsPublicHandler $handler,
    ) {}

    public function __invoke(string $checkInListShortId): JsonResponse
    {
        $siblings = $this->handler->handle($checkInListShortId);

        $payload = $siblings->map(function (CheckInListDomainObject $list) {
            $timezone = $list->getEvent()?->getTimezone() ?? 'UTC';
            return [
                'short_id' => $list->getShortId(),
                'name' => $list->getName(),
                'is_active' => $list->isActivated($timezone),
                'is_expired' => $list->isExpired($timezone),
            ];
        })->values()->all();

        return $this->jsonResponse($payload, wrapInData: true);
    }
}
