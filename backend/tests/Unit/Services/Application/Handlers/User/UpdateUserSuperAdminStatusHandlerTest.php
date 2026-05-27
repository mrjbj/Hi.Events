<?php

namespace Tests\Unit\Services\Application\Handlers\User;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\User\DTO\UpdateUserSuperAdminStatusDTO;
use HiEvents\Services\Application\Handlers\User\UpdateUserSuperAdminStatusHandler;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class UpdateUserSuperAdminStatusHandlerTest extends TestCase
{
    private UserRepositoryInterface|MockInterface $userRepository;

    private AccountUserRepositoryInterface|MockInterface $accountUserRepository;

    private LoggerInterface|MockInterface $logger;

    private UpdateUserSuperAdminStatusHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('transaction')->andReturnUsing(
            fn ($callback) => $callback(Mockery::mock(Connection::class))
        );

        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->accountUserRepository = Mockery::mock(AccountUserRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('critical')->byDefault();

        $this->handler = new UpdateUserSuperAdminStatusHandler(
            userRepository: $this->userRepository,
            accountUserRepository: $this->accountUserRepository,
            logger: $this->logger,
            databaseManager: app('db'),
        );
    }

    public function test_blocks_self_revoke(): void
    {
        $this->expectException(CannotUpdateResourceException::class);

        $this->handler->handle(UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => 42,
            'is_super_admin' => false,
            'acting_user_id' => 42,
        ]));
    }

    public function test_grant_updates_all_account_users_to_superadmin(): void
    {
        $accountUserA = $this->makeAccountUser(id: 1, accountId: 100, role: Role::ADMIN->name);
        $accountUserB = $this->makeAccountUser(id: 2, accountId: 200, role: Role::ORGANIZER->name);

        $this->userRepository->shouldReceive('findById')
            ->with(50)
            ->twice()
            ->andReturn(new UserDomainObject);

        $this->accountUserRepository->shouldReceive('findWhere')
            ->with(['user_id' => 50])
            ->andReturn(new Collection([$accountUserA, $accountUserB]));

        $this->accountUserRepository->shouldReceive('updateWhere')
            ->with(
                ['role' => Role::SUPERADMIN->name],
                ['id' => 1],
            )->once();

        $this->accountUserRepository->shouldReceive('updateWhere')
            ->with(
                ['role' => Role::SUPERADMIN->name],
                ['id' => 2],
            )->once();

        $this->handler->handle(UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => 50,
            'is_super_admin' => true,
            'acting_user_id' => 99,
        ]));
    }

    public function test_grant_skips_rows_already_superadmin(): void
    {
        $alreadySuperAdmin = $this->makeAccountUser(id: 7, accountId: 300, role: Role::SUPERADMIN->name);

        $this->userRepository->shouldReceive('findById')
            ->andReturn(new UserDomainObject);

        $this->accountUserRepository->shouldReceive('findWhere')
            ->andReturn(new Collection([$alreadySuperAdmin]));

        $this->accountUserRepository->shouldNotReceive('updateWhere');

        $this->handler->handle(UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => 51,
            'is_super_admin' => true,
            'acting_user_id' => 99,
        ]));
    }

    public function test_revoke_demotes_superadmins_to_admin(): void
    {
        $superAdminRow = $this->makeAccountUser(id: 9, accountId: 400, role: Role::SUPERADMIN->name);

        $this->userRepository->shouldReceive('findById')->andReturn(new UserDomainObject);

        $this->accountUserRepository->shouldReceive('findWhere')
            ->andReturn(new Collection([$superAdminRow]));

        $this->accountUserRepository->shouldReceive('updateWhere')
            ->with(
                ['role' => Role::ADMIN->name],
                ['id' => 9],
            )->once();

        $this->handler->handle(UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => 52,
            'is_super_admin' => false,
            'acting_user_id' => 99,
        ]));
    }

    public function test_errors_when_user_has_no_accounts(): void
    {
        $this->expectException(CannotUpdateResourceException::class);

        $this->userRepository->shouldReceive('findById')->andReturn(new UserDomainObject);

        $this->accountUserRepository->shouldReceive('findWhere')
            ->andReturn(new Collection([]));

        $this->handler->handle(UpdateUserSuperAdminStatusDTO::from([
            'target_user_id' => 53,
            'is_super_admin' => true,
            'acting_user_id' => 99,
        ]));
    }

    private function makeAccountUser(int $id, int $accountId, string $role): AccountUserDomainObject
    {
        return (new AccountUserDomainObject)
            ->setId($id)
            ->setAccountId($accountId)
            ->setRole($role);
    }
}
