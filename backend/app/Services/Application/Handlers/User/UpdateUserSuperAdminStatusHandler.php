<?php

namespace HiEvents\Services\Application\Handlers\User;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\User\DTO\UpdateUserSuperAdminStatusDTO;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class UpdateUserSuperAdminStatusHandler
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly AccountUserRepositoryInterface $accountUserRepository,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @throws CannotUpdateResourceException|Throwable
     */
    public function handle(UpdateUserSuperAdminStatusDTO $dto): UserDomainObject
    {
        return $this->databaseManager->transaction(function () use ($dto) {
            if ($dto->target_user_id === $dto->acting_user_id) {
                throw new CannotUpdateResourceException(__(
                    'You cannot change your own Super Admin status.'
                ));
            }

            $this->userRepository->findById($dto->target_user_id);

            $accountUsers = $this->accountUserRepository->findWhere([
                'user_id' => $dto->target_user_id,
            ]);

            if ($accountUsers->isEmpty()) {
                throw new CannotUpdateResourceException(__(
                    'This user is not associated with any account.'
                ));
            }

            $newRole = $dto->is_super_admin ? Role::SUPERADMIN : Role::ADMIN;

            foreach ($accountUsers as $accountUser) {
                if ($accountUser->getRole() === $newRole->name) {
                    continue;
                }

                $this->accountUserRepository->updateWhere(
                    attributes: [
                        'role' => $newRole->name,
                    ],
                    where: [
                        'id' => $accountUser->getId(),
                    ]
                );

                $this->logger->critical('Super Admin role change applied', [
                    'target_user_id' => $dto->target_user_id,
                    'acting_user_id' => $dto->acting_user_id,
                    'account_id' => $accountUser->getAccountId(),
                    'previous_role' => $accountUser->getRole(),
                    'new_role' => $newRole->name,
                ]);
            }

            return $this->userRepository->findById($dto->target_user_id);
        });
    }
}
