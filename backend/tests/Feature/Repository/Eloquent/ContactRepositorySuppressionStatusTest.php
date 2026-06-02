<?php

declare(strict_types=1);

namespace Tests\Feature\Repository\Eloquent;

use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the contacts-list suppression bucket (active / marketing_only / always)
 * and the suppression_status filter. The bucket must agree with how
 * EmailSuppressionService actually decides sends, including the SES-suppression
 * config gate: with it off, only do_not_contact counts.
 */
class ContactRepositorySuppressionStatusTest extends TestCase
{
    use DatabaseTransactions;

    private ContactRepositoryInterface $repository;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);
        $user = User::factory()->withAccount()->create();
        $this->accountId = (int) $user->accounts()->first()->id;
        $this->repository = app(ContactRepositoryInterface::class);
    }

    public function test_buckets_each_address_when_ses_suppression_enabled(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->insertContact('active@example.com');
        $this->insertContact('dnc@example.com');
        $this->insertContact('permanent@example.com');
        $this->insertContact('transient@example.com');
        $this->insertContact('complaint@example.com');
        // Stored lowercased; contact address is mixed-case to prove case-insensitive match.
        $this->insertContact('Mixed@Example.com');

        $this->insertSuppression('dnc@example.com', 'do_not_contact');
        $this->insertSuppression('permanent@example.com', 'bounce', 'Permanent');
        $this->insertSuppression('transient@example.com', 'bounce', 'Transient');
        $this->insertSuppression('complaint@example.com', 'complaint');
        $this->insertSuppression('mixed@example.com', 'do_not_contact');

        $byEmail = $this->fetchStatusesByEmail();

        $this->assertSame('active', $byEmail['active@example.com']);
        $this->assertSame('always', $byEmail['dnc@example.com']);
        $this->assertSame('always', $byEmail['permanent@example.com']);
        $this->assertSame('marketing_only', $byEmail['transient@example.com']);
        $this->assertSame('marketing_only', $byEmail['complaint@example.com']);
        $this->assertSame('always', $byEmail['Mixed@Example.com']);
    }

    public function test_detail_string_carries_reason_and_bounce_type(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->insertContact('transient@example.com');
        $this->insertSuppression('transient@example.com', 'bounce', 'Transient');

        $contact = $this->findContacts()->firstWhere(fn ($c) => $c->getEmail() === 'transient@example.com');

        $this->assertSame('bounce:Transient', $contact->getSuppressionDetail());
    }

    public function test_only_do_not_contact_counts_when_ses_suppression_disabled(): void
    {
        config(['services.ses.suppression_enabled' => false]);

        $this->insertContact('dnc@example.com');
        $this->insertContact('permanent@example.com');
        $this->insertContact('complaint@example.com');

        $this->insertSuppression('dnc@example.com', 'do_not_contact');
        $this->insertSuppression('permanent@example.com', 'bounce', 'Permanent');
        $this->insertSuppression('complaint@example.com', 'complaint');

        $byEmail = $this->fetchStatusesByEmail();

        $this->assertSame('always', $byEmail['dnc@example.com']);
        // SES off: bounce/complaint are ignored, so these still send.
        $this->assertSame('active', $byEmail['permanent@example.com']);
        $this->assertSame('active', $byEmail['complaint@example.com']);
    }

    public function test_filter_always_returns_only_blocked_addresses(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->insertContact('active@example.com');
        $this->insertContact('dnc@example.com');
        $this->insertContact('permanent@example.com');
        $this->insertContact('transient@example.com');

        $this->insertSuppression('dnc@example.com', 'do_not_contact');
        $this->insertSuppression('permanent@example.com', 'bounce', 'Permanent');
        $this->insertSuppression('transient@example.com', 'bounce', 'Transient');

        $emails = $this->findContacts('always')->map(fn ($c) => $c->getEmail())->sort()->values()->all();

        $this->assertSame(['dnc@example.com', 'permanent@example.com'], $emails);
    }

    public function test_filter_marketing_only_excludes_always_blocked(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->insertContact('transient@example.com');
        $this->insertContact('complaint@example.com');
        $this->insertContact('permanent@example.com');

        $this->insertSuppression('transient@example.com', 'bounce', 'Transient');
        $this->insertSuppression('complaint@example.com', 'complaint');
        $this->insertSuppression('permanent@example.com', 'bounce', 'Permanent');

        $emails = $this->findContacts('marketing_only')->map(fn ($c) => $c->getEmail())->sort()->values()->all();

        $this->assertSame(['complaint@example.com', 'transient@example.com'], $emails);
    }

    public function test_filter_active_returns_only_unsuppressed(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->insertContact('active@example.com');
        $this->insertContact('dnc@example.com');
        $this->insertContact('complaint@example.com');

        $this->insertSuppression('dnc@example.com', 'do_not_contact');
        $this->insertSuppression('complaint@example.com', 'complaint');

        $emails = $this->findContacts('active')->map(fn ($c) => $c->getEmail())->sort()->values()->all();

        $this->assertSame(['active@example.com'], $emails);
    }

    private function fetchStatusesByEmail(): array
    {
        $statuses = [];
        foreach ($this->findContacts() as $contact) {
            $statuses[$contact->getEmail()] = $contact->getSuppressionStatus();
        }

        return $statuses;
    }

    private function findContacts(?string $suppressionStatus = null)
    {
        $data = ['per_page' => 100];
        if ($suppressionStatus !== null) {
            $data['filter_fields'] = ['suppression_status' => ['eq' => $suppressionStatus]];
        }

        return $this->repository
            ->findByAccountId($this->accountId, QueryParamsDTO::fromArray($data))
            ->getCollection();
    }

    private function insertContact(string $email): int
    {
        return (int) DB::table('contacts')->insertGetId([
            'account_id' => $this->accountId,
            'email' => $email,
            'first_name' => 'F',
            'last_name' => 'L',
            'attributes' => json_encode([]),
            'attributes_history' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSuppression(string $email, string $reason, ?string $bounceType = null): void
    {
        DB::table('email_suppressions')->insert([
            'account_id' => $this->accountId,
            'email' => $email,
            'reason' => $reason,
            'bounce_type' => $bounceType,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
