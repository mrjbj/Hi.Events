<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Contact;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Contact\ContactRequestAutofillService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the autofill that runs inside CompleteOrderRequest::prepareForValidation
 * to populate question responses for questions hidden by the frontend's
 * "hide-if-answered" logic.
 *
 * The user-visible regression: the frontend hides a question after a contact
 * lookup returns answered_question_ids=[X]. If the submitted order's email
 * matches the looked-up contact, autofill fills X from the contact's
 * attribute and validation passes. If the email DOES NOT match (user edited
 * it after the lookup), autofill cannot fill X, validation rejects, and
 * because X has no rendered input the rejection is invisible to the buyer —
 * the "Continue to Payment" button spins and silently returns.
 */
class ContactRequestAutofillServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ContactRequestAutofillService $service;

    private int $accountId;
    private int $eventId;
    private int $orderQuestionId;
    private int $productQuestionId;
    private int $orderCountyQuestionId;
    private int $productCountyQuestionId;

    /**
     * Mirror of district11 prod's County dropdown options (8 choices). Used
     * by the validity tests so they exercise the same option-list the real
     * checkout uses, and so a future option-list edit here flags any test
     * that assumes a now-retired value.
     */
    private const COUNTY_OPTIONS = ['Bartow', 'Cherokee', 'Cobb', 'Gordon', 'Hall', 'Paulding', 'Pickens', 'Other'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ContactRequestAutofillService::class);

        $this->seedFixture();
    }

    public function testFillsHiddenOrderQuestionWhenSubmittedEmailMatchesContact(): void
    {
        $this->insertContact('alice@example.com', ['shirt_size' => 'L']);

        $input = [
            'order' => [
                'email' => 'alice@example.com',
                'questions' => [
                    ['question_id' => $this->orderQuestionId, 'response' => []],
                ],
            ],
            'products' => [],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            ['answer' => 'L'],
            $result['order']['questions'][0]['response'],
            'Autofill must populate the hidden order question from the contact attribute.'
        );
    }

    /**
     * This is the silent-failure root cause: lookup at time T1 used one
     * email, the frontend hid the question, then the user edited the email
     * to one with no contact. Autofill (running at submit time) uses the
     * NEW email, finds no contact, and leaves the response empty — which
     * trips the required-question validator on a field the user can't see.
     */
    public function testDoesNotFillWhenSubmittedEmailDoesNotMatchAnyContact(): void
    {
        $this->insertContact('alice@example.com', ['shirt_size' => 'L']);

        $input = [
            'order' => [
                'email' => 'bob-no-such-contact@example.com',
                'questions' => [
                    ['question_id' => $this->orderQuestionId, 'response' => []],
                ],
            ],
            'products' => [],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            [],
            $result['order']['questions'][0]['response'],
            'Autofill must NOT invent a value when the submitted email has no matching contact.'
        );
    }

    public function testDoesNotOverwriteAnAnswerTheBuyerAlreadyTyped(): void
    {
        $this->insertContact('alice@example.com', ['shirt_size' => 'L']);

        $input = [
            'order' => [
                'email' => 'alice@example.com',
                'questions' => [
                    ['question_id' => $this->orderQuestionId, 'response' => ['answer' => 'XL']],
                ],
            ],
            'products' => [],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            ['answer' => 'XL'],
            $result['order']['questions'][0]['response'],
            'Autofill must not overwrite an answer the buyer typed directly.'
        );
    }

    public function testFillsProductQuestionFromAttendeeEmailNotOrderEmail(): void
    {
        // Per-attendee questions key off the attendee's email, not the buyer's.
        // If the two emails resolve to different contacts, each section must
        // use its own.
        $this->insertContact('alice@example.com', ['shirt_size' => 'L']);
        $this->insertContact('attendee@example.com', ['shirt_size' => 'S']);

        $input = [
            'order' => [
                'email' => 'alice@example.com',
                'questions' => [],
            ],
            'products' => [
                [
                    'product_id' => 1, // unused by autofill, but the request shape includes it
                    'email' => 'attendee@example.com',
                    'questions' => [
                        ['question_id' => $this->productQuestionId, 'response' => []],
                    ],
                ],
            ],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            ['answer' => 'S'],
            $result['products'][0]['questions'][0]['response'],
            'Product-level autofill must use the attendee email, not the buyer email.'
        );
    }

    /**
     * Regression for district11 prod (2026-05-26): contact attribute values
     * that don't match the current dropdown option list (typos, casing,
     * retired labels, freeform imports) were being autofilled into hidden
     * question responses, then rejected by the checkout validator on a field
     * the buyer can't see. Each case below is a real prod row.
     *
     * Autofill must SKIP these — that, combined with the matching skip in
     * ContactPrefillService (which keeps the field visible), lets the buyer
     * pick a valid value themselves instead of looping on a silent toast.
     *
     * @dataProvider invalidDropdownValuesProvider
     */
    public function testDoesNotAutofillDropdownWhenContactValueIsNotInOptionList(string $description, string $badValue): void
    {
        $email = 'stale-' . md5($badValue) . '@example.com';
        $this->insertContact($email, ['county' => $badValue]);

        $input = [
            'order' => [
                'email' => $email,
                'questions' => [
                    ['question_id' => $this->orderCountyQuestionId, 'response' => []],
                ],
            ],
            'products' => [
                [
                    'product_id' => 1,
                    'email' => $email,
                    'questions' => [
                        ['question_id' => $this->productCountyQuestionId, 'response' => []],
                    ],
                ],
            ],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            [],
            $result['order']['questions'][0]['response'],
            "Order-level dropdown must not be autofilled with invalid value ({$description}: '{$badValue}')."
        );
        $this->assertSame(
            [],
            $result['products'][0]['questions'][0]['response'],
            "Product-level dropdown must not be autofilled with invalid value ({$description}: '{$badValue}')."
        );
    }

    public static function invalidDropdownValuesProvider(): array
    {
        // Each row is a real district11 prod contact attribute value as of 2026-05-26.
        return [
            'typo' => ['typo', 'Cheerokee'],
            'wrong case' => ['case mismatch (lowercase)', 'cherokee'],
            'county not in list (Fulton)' => ['county outside list', 'Fulton'],
            'state instead of county (GA Georgia)' => ['state instead of county', 'GA Georgia'],
            'state instead of county (Georgia)' => ['state instead of county', 'Georgia'],
            'zip instead of county' => ['zip code instead of county', '30188'],
        ];
    }

    public function testStillAutofillsDropdownWhenContactValueIsValid(): void
    {
        $this->insertContact('valid@example.com', ['county' => 'Cherokee']);

        $input = [
            'order' => [
                'email' => 'valid@example.com',
                'questions' => [
                    ['question_id' => $this->orderCountyQuestionId, 'response' => []],
                ],
            ],
            'products' => [],
        ];

        $result = $this->service->fillRequestInput($input, $this->eventId);

        $this->assertSame(
            ['answer' => 'Cherokee'],
            $result['order']['questions'][0]['response'],
            'A valid dropdown value must still be autofilled — the validity check must not break the happy path.'
        );
    }

    public function testIsNoopWhenEventDoesNotExist(): void
    {
        $input = ['order' => ['email' => 'x@y.com', 'questions' => []], 'products' => []];

        $result = $this->service->fillRequestInput($input, 9999999);

        $this->assertSame($input, $result);
    }

    /**
     * Setup helpers
     */
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

        $this->eventId = $this->insertEvent($user->id, $this->accountId);

        $attributeId = $this->insertAttributeDefinition($this->accountId, 'shirt_size');

        $this->orderQuestionId = $this->insertQuestion(
            eventId: $this->eventId,
            attributeId: $attributeId,
            belongsTo: QuestionBelongsTo::ORDER->name,
        );

        $this->productQuestionId = $this->insertQuestion(
            eventId: $this->eventId,
            attributeId: $attributeId,
            belongsTo: QuestionBelongsTo::PRODUCT->name,
        );

        $countyAttributeId = $this->insertAttributeDefinition($this->accountId, 'county');

        $this->orderCountyQuestionId = $this->insertQuestion(
            eventId: $this->eventId,
            attributeId: $countyAttributeId,
            belongsTo: QuestionBelongsTo::ORDER->name,
            type: 'DROPDOWN',
            options: self::COUNTY_OPTIONS,
            title: 'County',
        );

        $this->productCountyQuestionId = $this->insertQuestion(
            eventId: $this->eventId,
            attributeId: $countyAttributeId,
            belongsTo: QuestionBelongsTo::PRODUCT->name,
            type: 'DROPDOWN',
            options: self::COUNTY_OPTIONS,
            title: 'County',
        );
    }

    private function insertEvent(int $userId, int $accountId): int
    {
        return (int) DB::table('events')->insertGetId([
            'title' => 'Test Event',
            'account_id' => $accountId,
            'user_id' => $userId,
            'currency' => 'USD',
            'short_id' => IdHelper::shortId('e_'),
            'category' => 'OTHER',
            'status' => 'DRAFT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttributeDefinition(int $accountId, string $name): int
    {
        return (int) DB::table('contact_attribute_definitions')->insertGetId([
            'account_id' => $accountId,
            'name' => $name,
            'label' => ucfirst($name),
            'type' => 'string',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertQuestion(
        int $eventId,
        int $attributeId,
        string $belongsTo,
        string $type = 'SINGLE_LINE_TEXT',
        ?array $options = null,
        string $title = 'Shirt Size',
    ): int {
        return (int) DB::table('questions')->insertGetId([
            'event_id' => $eventId,
            'title' => $title,
            'required' => true,
            'type' => $type,
            'options' => $options === null ? null : json_encode($options),
            'belongs_to' => $belongsTo,
            'order' => 0,
            'is_hidden' => false,
            'contact_attribute_definition_id' => $attributeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertContact(string $email, array $attributes): int
    {
        return (int) DB::table('contacts')->insertGetId([
            'account_id' => $this->accountId,
            'email' => $email,
            'first_name' => 'X',
            'last_name' => 'Y',
            'attributes' => json_encode($attributes),
            'attributes_history' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
