<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Contact;

use HiEvents\Helper\IdHelper;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Contact\ContactBackfillService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stale-values sub-tab: discovery + bulk remap. The user-visible bug being fixed
 * is "the contact has a stored attribute value that isn't in the dropdown's
 * current option list, and there's no admin surface to see/fix it" — which led
 * to silent checkout failures (the original Cheerokee / cherokee / Fulton /
 * 30188 prod data, district11, 2026-05-26).
 */
class ContactBackfillStaleValuesTest extends TestCase
{
    use DatabaseTransactions;

    private ContactBackfillService $service;

    private int $accountId;
    private int $userId;
    private int $countyDefId;
    private int $roleDefId;
    private int $tagsDefId;

    private const COUNTY_OPTIONS = ['Bartow', 'Cherokee', 'Cobb', 'Gordon', 'Hall', 'Paulding', 'Pickens', 'Other'];
    private const ROLE_OPTIONS = [
        'Candidate',
        'County Chair',
        'District or State EC or Committee Member',
        'Precinct Leadership',
        'Elected Official',
        'Political Organization',
        'Volunteer',
        'Other',
    ];
    private const TAG_OPTIONS = ['vip', 'volunteer', 'donor'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactBackfillService::class);
        $this->seedFixture();
    }

    public function testDiscoversStaleSelectValuesForEachRealProdShape(): void
    {
        // Real district11 prod values as of 2026-05-26.
        $this->insertContact('cheerokee@example.com', ['county' => 'Cheerokee']);
        $this->insertContact('lower@example.com', ['county' => 'cherokee']);
        $this->insertContact('fulton@example.com', ['county' => 'Fulton']);
        $this->insertContact('zip@example.com', ['county' => '30188']);
        $this->insertContact('retired-role@example.com', ['role' => 'District Chair']);

        // A clean record that should NOT appear in stale results.
        $this->insertContact('clean@example.com', ['county' => 'Cherokee', 'role' => 'Precinct Leadership']);

        $rows = $this->fetchAllStale();

        $shape = array_map(fn ($r) => [$r['contact_email'], $r['attribute_name'], $r['current_value']], $rows);

        $this->assertEqualsCanonicalizing([
            ['cheerokee@example.com', 'county', 'Cheerokee'],
            ['lower@example.com', 'county', 'cherokee'],
            ['fulton@example.com', 'county', 'Fulton'],
            ['zip@example.com', 'county', '30188'],
            ['retired-role@example.com', 'role', 'District Chair'],
        ], $shape, 'Every real bad value must surface, and only those values — clean contacts must not appear.');
    }

    public function testDiscoversStaleMultiSelectValues(): void
    {
        $this->insertContact('tags-mixed@example.com', ['tags' => ['vip', 'unknown-tag']]);
        $this->insertContact('tags-clean@example.com', ['tags' => ['vip', 'donor']]);

        $rows = $this->fetchAllStale();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('tags-mixed@example.com', $row['contact_email']);
        $this->assertSame('tags', $row['attribute_name']);
        $this->assertSame(['unknown-tag'], $row['invalid_values'], 'Only the values not in the option list should be flagged as invalid.');
        $this->assertSame(['vip', 'unknown-tag'], $row['current_value']);
    }

    public function testEmptyAndNullAttributeValuesAreNotStale(): void
    {
        $this->insertContact('null-county@example.com', ['county' => null]);
        $this->insertContact('empty-county@example.com', ['county' => '']);
        $this->insertContact('empty-tags@example.com', ['tags' => []]);

        $this->assertSame([], $this->fetchAllStale());
    }

    public function testIncludedInSummaryCount(): void
    {
        $this->insertContact('bad@example.com', ['county' => 'Cheerokee']);
        $this->insertContact('bad2@example.com', ['role' => 'District Chair']);
        $this->insertContact('clean@example.com', ['county' => 'Cobb']);

        $summary = $this->service->getSummaryCounts($this->accountId);

        $this->assertArrayHasKey('stale_values_count', $summary);
        $this->assertSame(2, $summary['stale_values_count']);
    }

    public function testApplyRemapsWritesValidNewValuesAndRecordsHistory(): void
    {
        $contactId = $this->insertContact('jim@example.com', ['county' => 'cherokee', 'role' => 'Precinct Leadership']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => 'Cherokee']],
            $this->userId,
        );

        $this->assertSame(['attributes_written' => 1, 'options_added' => 0], $result);

        $contact = DB::table('contacts')->where('id', $contactId)->first();
        $attrs = json_decode($contact->attributes, true);
        $this->assertSame('Cherokee', $attrs['county']);
        // Role should be preserved — remaps must only touch named attributes.
        $this->assertSame('Precinct Leadership', $attrs['role']);

        $history = json_decode($contact->attributes_history, true);
        $this->assertNotEmpty($history, 'Attribute changes must be recorded in history for audit.');
        $last = end($history);
        $this->assertSame(['county' => 'cherokee'], $last['old_values']);
        $this->assertSame(['county' => 'Cherokee'], $last['new_values']);
    }

    public function testApplyRemapsRejectsValueNotInCurrentOptionList(): void
    {
        $contactId = $this->insertContact('bad@example.com', ['county' => 'cherokee']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => 'NotARealCounty']],
            $this->userId,
        );

        $this->assertSame(0, $result['attributes_written'], 'Remap attempt with invalid replacement must be rejected — otherwise the tool would pollute the data it is meant to clean.');
        $this->assertSame(0, $result['options_added']);

        $contact = DB::table('contacts')->where('id', $contactId)->first();
        $attrs = json_decode($contact->attributes, true);
        $this->assertSame('cherokee', $attrs['county'], 'Original stale value must be left alone when remap is rejected.');
    }

    public function testApplyRemapsCanClearAttributeWithNull(): void
    {
        $contactId = $this->insertContact('clear@example.com', ['county' => 'Cheerokee']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => null]],
            $this->userId,
        );

        $this->assertSame(1, $result['attributes_written']);

        $contact = DB::table('contacts')->where('id', $contactId)->first();
        $attrs = json_decode($contact->attributes, true);
        // The underlying ContactUpsertService::updateContactAttributes uses array_merge,
        // which keeps the key with a null value rather than removing it. Either shape
        // is acceptable for downstream consumers (ContactPrefillService treats both as
        // empty), so accept either.
        $this->assertTrue(
            !array_key_exists('county', $attrs) || $attrs['county'] === null,
            'Null replacement should null/clear the attribute.'
        );
    }

    public function testApplyRemapsTreatsEmptyStringAndEmptyArrayAsClear(): void
    {
        $contactId = $this->insertContact('clear2@example.com', ['county' => 'Cheerokee']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => '']],
            $this->userId,
        );

        $this->assertSame(1, $result['attributes_written']);
        $attrs = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes'), true);
        $this->assertTrue(
            !array_key_exists('county', $attrs) || $attrs['county'] === null,
            'Empty string replacement should null/clear the attribute.'
        );
    }

    public function testApplyRemapsBatchesMultipleRowsForSameContactIntoOneHistoryEntry(): void
    {
        $contactId = $this->insertContact('multi@example.com', ['county' => 'cherokee', 'role' => 'District Chair']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [
                ['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => 'Cherokee'],
                ['contact_id' => $contactId, 'attribute_name' => 'role', 'new_value' => 'District or State EC or Committee Member'],
            ],
            $this->userId,
        );

        $this->assertSame(2, $result['attributes_written']);

        $history = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes_history'), true);
        $this->assertCount(1, $history, 'Two attributes changed in one bulk call should produce one history entry, not two.');
        // Key order isn't significant — array_merge in the upsert service rearranges.
        $this->assertEqualsCanonicalizing(['county' => 'cherokee', 'role' => 'District Chair'], $history[0]['old_values']);
        $this->assertEqualsCanonicalizing(['county' => 'Cherokee', 'role' => 'District or State EC or Committee Member'], $history[0]['new_values']);
    }

    public function testApplyRemapsScopesByAccount(): void
    {
        $contactId = $this->insertContact('cross@example.com', ['county' => 'Cheerokee']);

        // Pretend a remap arrived under a different account_id — must not write.
        $result = $this->service->applyStaleValueRemaps(
            $this->accountId + 1,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'new_value' => 'Cherokee']],
            $this->userId,
        );

        $this->assertSame(0, $result['attributes_written']);
        $attrs = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes'), true);
        $this->assertSame('Cheerokee', $attrs['county'], 'Cross-account remaps must not modify the contact.');
    }

    public function testAddValuesToOptionsExtendsDefinitionOptionList(): void
    {
        $contactId = $this->insertContact('typo@example.com', ['county' => 'Cheerokee']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'county', 'add_values_to_options' => ['Cheerokee']]],
            $this->userId,
        );

        $this->assertSame(1, $result['options_added']);
        // No contact write requested — the existing value becomes valid as-is.
        $this->assertSame(0, $result['attributes_written']);

        $defOptions = json_decode(DB::table('contact_attribute_definitions')->where('id', $this->countyDefId)->value('options'), true);
        $this->assertContains('Cheerokee', $defOptions);

        // Contact attribute untouched.
        $attrs = json_decode(DB::table('contacts')->where('id', $contactId)->value('attributes'), true);
        $this->assertSame('Cheerokee', $attrs['county']);
    }

    public function testAddValuesToOptionsDeduplicatesAcrossRemapsAndAgainstExistingOptions(): void
    {
        // Two contacts both want 'Cheerokee' added; one also wants 'NewValue'.
        $c1 = $this->insertContact('a@example.com', ['county' => 'Cheerokee']);
        $c2 = $this->insertContact('b@example.com', ['county' => 'Cheerokee']);
        $c3 = $this->insertContact('c@example.com', ['county' => 'Cherokee']); // 'Cherokee' already in options

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [
                ['contact_id' => $c1, 'attribute_name' => 'county', 'add_values_to_options' => ['Cheerokee']],
                ['contact_id' => $c2, 'attribute_name' => 'county', 'add_values_to_options' => ['Cheerokee', 'NewValue']],
                ['contact_id' => $c3, 'attribute_name' => 'county', 'add_values_to_options' => ['Cherokee']],
            ],
            $this->userId,
        );

        // 'Cheerokee' added once, 'NewValue' added once, 'Cherokee' already present (skipped). 2 net additions.
        $this->assertSame(2, $result['options_added']);

        $defOptions = json_decode(DB::table('contact_attribute_definitions')->where('id', $this->countyDefId)->value('options'), true);
        $this->assertCount(count(self::COUNTY_OPTIONS) + 2, $defOptions);
        $this->assertContains('Cheerokee', $defOptions);
        $this->assertContains('NewValue', $defOptions);
    }

    public function testAddValuesToOptionsAndWriteCanCombineInSameRemap(): void
    {
        $contactId = $this->insertContact('combo@example.com', ['county' => 'Cheerokee']);

        // Frontend may both add the value AND write it — combined into one remap.
        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [[
                'contact_id' => $contactId,
                'attribute_name' => 'county',
                'add_values_to_options' => ['Cheerokee'],
                'new_value' => 'Cheerokee',
            ]],
            $this->userId,
        );

        $this->assertSame(1, $result['options_added']);
        $this->assertSame(1, $result['attributes_written'], 'Write must succeed because the option was added in the same call before validation.');
    }

    public function testAddValuesToOptionsIgnoresUnknownAttribute(): void
    {
        $contactId = $this->insertContact('a@example.com', ['county' => 'X']);

        $result = $this->service->applyStaleValueRemaps(
            $this->accountId,
            [['contact_id' => $contactId, 'attribute_name' => 'totally-unknown', 'add_values_to_options' => ['foo']]],
            $this->userId,
        );

        $this->assertSame(0, $result['options_added'], 'Unknown attribute names must not silently create new definitions.');
    }

    public function testSearchFiltersRows(): void
    {
        $this->insertContact('alice@example.com', ['county' => 'Cheerokee']);
        $this->insertContact('bob@example.com', ['role' => 'District Chair']);

        $byEmail = $this->service->getStaleValues($this->accountId, QueryParamsDTO::fromArray(['query' => 'alice', 'per_page' => 50, 'page' => 1]));
        $this->assertSame(1, $byEmail->total());
        $this->assertSame('alice@example.com', $byEmail->items()[0]['contact_email']);

        $byAttribute = $this->service->getStaleValues($this->accountId, QueryParamsDTO::fromArray(['query' => 'role', 'per_page' => 50, 'page' => 1]));
        $this->assertSame(1, $byAttribute->total());
        $this->assertSame('bob@example.com', $byAttribute->items()[0]['contact_email']);

        $byValue = $this->service->getStaleValues($this->accountId, QueryParamsDTO::fromArray(['query' => 'cheer', 'per_page' => 50, 'page' => 1]));
        $this->assertSame(1, $byValue->total());
        $this->assertSame('alice@example.com', $byValue->items()[0]['contact_email']);
    }

    private function fetchAllStale(): array
    {
        $paginator = $this->service->getStaleValues(
            $this->accountId,
            QueryParamsDTO::fromArray(['per_page' => 100, 'page' => 1]),
        );

        return $paginator->items();
    }

    private function seedFixture(): void
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $user = User::factory()->withAccount()->create();
        $this->userId = (int) $user->id;
        $this->accountId = (int) $user->accounts()->first()->id;

        $this->countyDefId = $this->insertDefinition('county', 'select', self::COUNTY_OPTIONS);
        $this->roleDefId = $this->insertDefinition('role', 'select', self::ROLE_OPTIONS);
        $this->tagsDefId = $this->insertDefinition('tags', 'multi_select', self::TAG_OPTIONS);
    }

    private function insertDefinition(string $name, string $type, array $options): int
    {
        return (int) DB::table('contact_attribute_definitions')->insertGetId([
            'account_id' => $this->accountId,
            'name' => $name,
            'label' => ucfirst($name),
            'type' => $type,
            'options' => json_encode($options),
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertContact(string $email, array $attributes): int
    {
        return (int) DB::table('contacts')->insertGetId([
            'account_id' => $this->accountId,
            'email' => $email,
            'first_name' => 'F',
            'last_name' => 'L',
            'attributes' => json_encode($attributes),
            'attributes_history' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
