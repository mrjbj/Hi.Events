<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Contact\DTO\GetMyContactPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\GetMyContactPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetMyContactPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetMyContactPublicHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $eventIdRaw = $request->query('event_id', $request->input('event_id'));
        $eventId = is_numeric($eventIdRaw) ? (int) $eventIdRaw : null;

        $result = $this->handler->handle(new GetMyContactPublicDTO(
            token: (string) $request->query('c', (string) $request->input('token', '')),
            eventId: $eventId,
        ));

        if (!$result->found) {
            return $this->errorResponse(__('Invalid or expired link.'), 404);
        }

        return $this->jsonResponse($result->toArray());
    }
}
