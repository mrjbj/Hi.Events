<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Contact\ContactPrefillService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The frontend hides a checkout question once `answered_question_ids` includes
 * it (the buyer has supposedly already answered via their contact attributes).
 * If we mark a question as answered with a value that isn't actually a valid
 * dropdown option, the checkout validator later rejects it on a field the
 * buyer can't see — a silent submit failure. Each test below pins one of the
 * real district11 prod data shapes that caused that bug (2026-05-26).
 */
class ContactPrefillServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ContactPrefillService $service;

    private int $accountId;
    private int $eventId;
    private int $countyQuestionId;
    private int $roleQuestionId;
    private int $phoneQuestionId;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactPrefillService::class);
        $this->seedFixture();
    }

    /**
     * @dataProvider invalidDropdownValuesProvider
     */
    public function testInvalidDropdownValueIsNotMarkedAnswered(string $attribute, string $badValue): void
    {
        $resolved = $this->service->resolveForEvent($this->accountId, $this->eventId, [$attribute => $badValue]);

        $this->assertSame([], $resolved['answered_ids'], "Invalid {$attribute} value '{$badValue}' must not appear in answered_ids.");
        $this->assertSame([], $resolved['answers'], "Invalid {$attribute} value '{$badValue}' must not appear in answers.");
    }

    public static function invalidDropdownValuesProvider(): array
    {
        // Real district11 prod values as of 2026-05-26.
        return [
            // county
            'county: typo (Cheerokee)' => ['county', 'Cheerokee'],
            'county: case mismatch (cherokee)' => ['county', 'cherokee'],
            'county: outside list (Fulton)' => ['county', 'Fulton'],
            'county: state value (GA Georgia)' => ['county', 'GA Georgia'],
            'county: state value (Georgia)' => ['county', 'Georgia'],
            'county: zip (30188)' => ['county', '30188'],
            // role
            'role: retired label (District Chair)' => ['role', 'District Chair'],
            'role: retired label (Political Organization Leader)' => ['role', 'Political Organization Leader'],
            'role: retired label (District or State Committee Member)' => ['role', 'District or State Committee Member'],
        ];
    }

    public function testValidDropdownValueIsMarkedAnswered(): void
    {
        $resolved = $this->service->resolveForEvent($this->accountId, $this->eventId, ['county' => 'Cherokee']);

        $this->assertSame([$this->countyQuestionId], $resolved['answered_ids']);
        $this->assertSame([(string) $this->countyQuestionId => 'Cherokee'], $resolved['answers']);
    }

    public function testNonDropdownQuestionStillMarkedAnsweredForAnyNonEmptyValue(): void
    {
        // Phone is a SINGLE_LINE_TEXT — there's no option list to validate against.
        $resolved = $this->service->resolveForEvent($this->accountId, $this->eventId, ['phone' => '404-555-0100']);

        $this->assertSame([$this->phoneQuestionId], $resolved['answered_ids']);
        $this->assertSame([(string) $this->phoneQuestionId => '404-555-0100'], $resolved['answers']);
    }

    public function testEmptyAttributesProduceEmptyResult(): void
    {
        $resolved = $this->service->resolveForEvent($this->accountId, $this->eventId, []);

        $this->assertSame([], $resolved['answered_ids']);
        $this->assertSame([], $resolved['answers']);
    }

    public function testNullAndEmptyStringValuesAreSkipped(): void
    {
        $resolved = $this->service->resolveForEvent($this->accountId, $this->eventId, [
            'county' => null,
            'role' => '',
            'phone' => 'x',
        ]);

        $this->assertSame([$this->phoneQuestionId], $resolved['answered_ids']);
    }

    public function testAcceptsValueReturnsTrueForFreeformQuestionTypes(): void
    {
        $this->assertTrue(ContactPrefillService::acceptsValue('SINGLE_LINE_TEXT', null, 'anything'));
        $this->assertTrue(ContactPrefillService::acceptsValue('MULTI_LINE_TEXT', null, 'anything'));
        $this->assertTrue(ContactPrefillService::acceptsValue('DATE', null, '2026-01-01'));
        $this->assertTrue(ContactPrefillService::acceptsValue('PHONE', null, '404-555-0100'));
        $this->assertTrue(ContactPrefillService::acceptsValue('ADDRESS', null, ['city' => 'Atlanta']));
    }

    public function testAcceptsValueValidatesDropdownAgainstStringOrArrayOptions(): void
    {
        // jsonb column comes back as a JSON-encoded string from raw DB queries.
        $this->assertTrue(ContactPrefillService::acceptsValue('DROPDOWN', json_encode(['A', 'B']), 'A'));
        $this->assertFalse(ContactPrefillService::acceptsValue('DROPDOWN', json_encode(['A', 'B']), 'a'));

        // Tests / future Eloquent casts may pass a pre-decoded array — that must also work.
        $this->assertTrue(ContactPrefillService::acceptsValue('DROPDOWN', ['A', 'B'], 'B'));
        $this->assertFalse(ContactPrefillService::acceptsValue('DROPDOWN', ['A', 'B'], 'C'));
    }

    public function testAcceptsValueValidatesMultiSelectAgainstOptionList(): void
    {
        $opts = ['A', 'B', 'C'];

        $this->assertTrue(ContactPrefillService::acceptsValue('CHECKBOX', $opts, ['A', 'B']));
        $this->assertFalse(ContactPrefillService::acceptsValue('CHECKBOX', $opts, ['A', 'Z']));

        $this->assertTrue(ContactPrefillService::acceptsValue('MULTI_SELECT_DROPDOWN', $opts, ['C']));
        $this->assertFalse(ContactPrefillService::acceptsValue('MULTI_SELECT_DROPDOWN', $opts, ['D']));
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
        $this->accountId = (int) $user->accounts()->first()->id;

        $this->eventId = (int) DB::table('events')->insertGetId([
            'title' => 'Test Event',
            'account_id' => $this->accountId,
            'user_id' => $user->id,
            'currency' => 'USD',
            'short_id' => IdHelper::shortId('e_'),
            'category' => 'OTHER',
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $countyAttrId = $this->insertAttribute('county');
        $roleAttrId = $this->insertAttribute('role');
        $phoneAttrId = $this->insertAttribute('phone');

        $this->countyQuestionId = $this->insertQuestion($countyAttrId, 'DROPDOWN', self::COUNTY_OPTIONS, 'County');
        $this->roleQuestionId = $this->insertQuestion($roleAttrId, 'DROPDOWN', self::ROLE_OPTIONS, 'Role');
        $this->phoneQuestionId = $this->insertQuestion($phoneAttrId, 'SINGLE_LINE_TEXT', null, 'Phone');
    }

    private function insertAttribute(string $name): int
    {
        return (int) DB::table('contact_attribute_definitions')->insertGetId([
            'account_id' => $this->accountId,
            'name' => $name,
            'label' => ucfirst($name),
            'type' => 'string',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertQuestion(int $attributeId, string $type, ?array $options, string $title): int
    {
        return (int) DB::table('questions')->insertGetId([
            'event_id' => $this->eventId,
            'title' => $title,
            'required' => false,
            'type' => $type,
            'options' => $options === null ? null : json_encode($options),
            'belongs_to' => QuestionBelongsTo::ORDER->name,
            'order' => 0,
            'is_hidden' => false,
            'contact_attribute_definition_id' => $attributeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
