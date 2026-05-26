<?php

declare(strict_types=1);

namespace Tests\Feature\Repository\Eloquent;

use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the "Resolve bounced email" footgun: the Contact model casts
 * attributes_history to 'array', so Eloquent JSON-encodes whatever it receives
 * on write. The pre-fix updateEmail() passed json_encode($history) (already a
 * string), producing a JSON string of a JSON string. Every subsequent read
 * decoded to a string rather than an array, breaking the Sync → Different
 * Answers tab (foreach on a string) and the EditContactModal history panel
 * (React error boundary).
 */
class ContactRepositoryUpdateEmailTest extends TestCase
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

    public function testUpdateEmailWritesArrayShapedHistoryNotDoubleEncodedString(): void
    {
        $contactId = $this->insertContact('old@example.com');

        $this->repository->updateEmail($contactId, 'new@example.com');

        $row = DB::table('contacts')->where('id', $contactId)->first();

        $this->assertSame('new@example.com', $row->email);

        // The raw column value must be a JSON array, not a JSON string of a JSON
        // array. Both decode without error, but only the array shape supports
        // foreach in downstream code paths.
        $decoded = json_decode($row->attributes_history, true);
        $this->assertIsArray($decoded, 'attributes_history must decode to an array, not a string (double-encoding regression).');
        $this->assertCount(1, $decoded);
        $this->assertSame('email', $decoded[0]['field']);
        $this->assertSame('old@example.com', $decoded[0]['old_value']);
        $this->assertSame('new@example.com', $decoded[0]['new_value']);
        $this->assertSame('manual_resolve', $decoded[0]['reason']);
    }

    public function testUpdateEmailAppendsToExistingHistoryWithoutLoss(): void
    {
        $contactId = $this->insertContact('a@example.com', [
            ['field' => 'email', 'old_value' => 'first@example.com', 'new_value' => 'a@example.com', 'changed_at' => '2026-01-01T00:00:00+00:00', 'reason' => 'manual_resolve'],
        ]);

        $this->repository->updateEmail($contactId, 'b@example.com');

        $history = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes_history'), true);
        $this->assertCount(2, $history);
        $this->assertSame('first@example.com', $history[0]['old_value']);
        $this->assertSame('a@example.com', $history[1]['old_value']);
        $this->assertSame('b@example.com', $history[1]['new_value']);
    }

    public function testUpdateEmailHealsExistingDoubleEncodedHistoryInsteadOfDiscardingIt(): void
    {
        // Simulate the pre-fix corruption: write a double-encoded value
        // directly into the column so we don't go through Eloquent's cast.
        $contactId = $this->insertContact('victim@example.com');
        $legacyEntry = [
            'field' => 'email',
            'old_value' => 'older@example.com',
            'new_value' => 'victim@example.com',
            'changed_at' => '2026-04-01T00:00:00+00:00',
            'reason' => 'manual_resolve',
        ];
        DB::table('contacts')->where('id', $contactId)->update([
            'attributes_history' => json_encode(json_encode([$legacyEntry])),
        ]);

        $this->repository->updateEmail($contactId, 'recovered@example.com');

        $history = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes_history'), true);
        $this->assertIsArray($history);
        $this->assertCount(2, $history, 'Double-encoded legacy entry must be recovered and appended to, not discarded.');
        $this->assertSame('older@example.com', $history[0]['old_value']);
        $this->assertSame('recovered@example.com', $history[1]['new_value']);
    }

    public function testUpdateEmailIsNoopWhenEmailUnchanged(): void
    {
        $contactId = $this->insertContact('same@example.com');

        $this->repository->updateEmail($contactId, 'same@example.com');

        $history = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes_history'), true);
        $this->assertSame([], $history, 'No-op email update must not append a history entry.');
    }

    private function insertContact(string $email, array $history = []): int
    {
        return (int) DB::table('contacts')->insertGetId([
            'account_id' => $this->accountId,
            'email' => $email,
            'first_name' => 'F',
            'last_name' => 'L',
            'attributes' => json_encode([]),
            'attributes_history' => json_encode($history),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
