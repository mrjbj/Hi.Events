<?php

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListFilterOptionsPublicHandler;
use Illuminate\Http\JsonResponse;

class GetCheckInListFilterOptionsPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetCheckInListFilterOptionsPublicHandler $handler,
    ) {}

    public function __invoke(string $checkInListShortId): JsonResponse
    {
        $options = $this->handler->handle($checkInListShortId);

        return $this->jsonResponse($options, wrapInData: true);
    }
}
