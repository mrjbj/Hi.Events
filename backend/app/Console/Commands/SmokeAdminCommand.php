<?php

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Models\Account;
use HiEvents\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Stable, idempotent SUPERADMIN login for smoke runs (Tier 1 and Tier 2).
 *
 * Gives every smoke session one known account with fixed credentials and the
 * SUPERADMIN role, so driving the app never rabbit-holes on password resets or
 * permission errors. Re-running is safe: it reuses the existing user/account by
 * the marker email and re-asserts password, verification, and role.
 *
 * Local-only by design — this seeds a fixed, publicly-known password, so the
 * command refuses to run outside the `local` environment (jbj/local deploys to
 * prod). Prints one ADMIN_JSON= line a replay/script can parse.
 */
class SmokeAdminCommand extends Command
{
    protected $signature = 'smoke:admin {--down : Remove the smoke admin user and exit}';

    protected $description = 'Create (or tear down) a stable SUPERADMIN login for smoke runs — local only';

    private const EMAIL = 'smoke-admin@hi.events.test';

    private const PASSWORD = 'SmokeAdmin123!';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('smoke:admin only runs in the local environment (refusing on '.app()->environment().').');

            return self::FAILURE;
        }

        if ($this->option('down')) {
            $this->teardown();
            $this->info('Smoke admin removed.');

            return self::SUCCESS;
        }

        try {
            $admin = DB::transaction(fn () => $this->build());
        } catch (Throwable $e) {
            $this->error('Smoke admin build failed: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->info('Smoke admin ready (SUPERADMIN).');
        $this->line('  email:    '.$admin['email']);
        $this->line('  password: '.$admin['password']);
        $this->line('ADMIN_JSON='.json_encode($admin));

        return self::SUCCESS;
    }

    private function build(): array
    {
        $user = User::firstOrNew(['email' => self::EMAIL]);
        $user->fill([
            'first_name' => 'Smoke',
            'last_name' => 'Admin',
            'timezone' => 'America/New_York',
            'locale' => 'en',
        ]);
        $user->password = Hash::make(self::PASSWORD);
        $user->email_verified_at = now();
        $user->save();

        $accountId = $user->accounts()->value('accounts.id');

        if (! $accountId) {
            $account = Account::where('email', self::EMAIL)->first()
                ?? Account::factory()->verified()->create([
                    'email' => self::EMAIL,
                    'name' => 'Smoke Admin',
                    'timezone' => 'America/New_York',
                ]);

            if (! $account->account_verified_at) {
                $account->account_verified_at = now();
                $account->save();
            }

            $accountId = $account->id;
        }

        $pivot = [
            'role' => Role::SUPERADMIN,
            'status' => UserStatus::ACTIVE,
            'is_account_owner' => true,
        ];

        if ($user->accounts()->where('accounts.id', $accountId)->exists()) {
            $user->accounts()->updateExistingPivot($accountId, $pivot);
        } else {
            $user->accounts()->attach($accountId, $pivot);
        }

        return [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'accountId' => $accountId,
        ];
    }

    private function teardown(): void
    {
        $userId = DB::table('users')->where('email', self::EMAIL)->value('id');
        if (! $userId) {
            return;
        }

        DB::table('account_users')->where('user_id', $userId)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }
}
