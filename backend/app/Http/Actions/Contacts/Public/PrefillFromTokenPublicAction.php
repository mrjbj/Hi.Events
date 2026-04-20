<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts\Public;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\PrefillFromTokenRequest;
use HiEvents\Services\Application\Handlers\Contact\DTO\PrefillFromTokenPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\PrefillFromTokenPublicHandler;
use Illuminate\Http\JsonResponse;

class PrefillFromTokenPublicAction extends BaseAction
{
    public function __construct(
        private readonly PrefillFromTokenPublicHandler $handler,
    ) {}

    public function __invoke(PrefillFromTokenRequest $request, int $eventId): JsonResponse
    {
        try {
            $result = $this->handler->handle(new PrefillFromTokenPublicDTO(
                eventId: $eventId,
                token: (string) $request->input('token'),
            ));
        } catch (ResourceConflictException $e) {
            return $this->errorResponse(message: $e->getMessage(), statusCode: 403);
        }

        return $this->jsonResponse($result->toArray());
    }
}
