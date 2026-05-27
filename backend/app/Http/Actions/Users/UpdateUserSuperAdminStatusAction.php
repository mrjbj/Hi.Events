<?php

namespace HiEvents\Http\Actions\Users;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\User\UpdateUserSuperAdminStatusRequest;
use HiEvents\Resources\User\UserResource;
use HiEvents\Services\Application\Handlers\User\DTO\UpdateUserSuperAdminStatusDTO;
use HiEvents\Services\Application\Handlers\User\UpdateUserSuperAdminStatusHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateUserSuperAdminStatusAction extends BaseAction
{
    public function __construct(
        private readonly UpdateUserSuperAdminStatusHandler $handler,
    ) {}

    /**
     * @throws ValidationException|Throwable
     */
    public function __invoke(UpdateUserSuperAdminStatusRequest $request, int $userId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        $dto = UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => $userId,
            'is_super_admin' => (bool) $request->validated('is_super_admin'),
            'acting_user_id' => $this->getAuthenticatedUser()->getId(),
        ]);

        try {
            $user = $this->handler->handle($dto);
        } catch (CannotUpdateResourceException $e) {
            throw ValidationException::withMessages([
                'is_super_admin' => $e->getMessage(),
            ]);
        }

        return $this->resourceResponse(UserResource::class, $user);
    }
}
