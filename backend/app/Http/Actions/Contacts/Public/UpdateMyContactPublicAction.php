<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Contacts\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Contact\UpdateMyContactRequest;
use HiEvents\Services\Application\Handlers\Contact\DTO\UpdateMyContactPublicDTO;
use HiEvents\Services\Application\Handlers\Contact\UpdateMyContactPublicHandler;
use Illuminate\Http\JsonResponse;

class UpdateMyContactPublicAction extends BaseAction
{
    public function __construct(
        private readonly UpdateMyContactPublicHandler $handler,
    ) {}

    public function __invoke(UpdateMyContactRequest $request): JsonResponse
    {
        $result = $this->handler->handle(new UpdateMyContactPublicDTO(
            token: (string) $request->input('token'),
            firstName: $request->input('first_name'),
            lastName: $request->input('last_name'),
            attributes: $request->input('attributes', []),
        ));

        if (!$result->found) {
            return $this->errorResponse(__('Invalid or expired link.'), 404);
        }

        return $this->jsonResponse($result->toArray());
    }
}
