<?php

declare(strict_types=1);

namespace Tests\Unit\Validators\Rules;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Validators\Rules\ProductQuestionRule;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Mockery as m;
use Tests\TestCase;

class ProductQuestionRuleTest extends TestCase
{
    /**
     * Regression: the throw for "required questions missing" used to pass
     * an unkeyed string, producing a numeric-keyed error the frontend's
     * form.setErrors() couldn't attach. Submit then appeared to silently
     * fail. Must be keyed by 'products.{index}.questions'.
     */
    public function test_throws_with_product_indexed_string_key_when_required_product_question_missing(): void
    {
        $productId = 10;
        $productPriceId = 100;
        $product = $this->makeProduct(id: $productId, priceId: $productPriceId);
        $requiredQuestion = $this->makeProductQuestion(id: 7, required: true, hidden: false, productId: $productId);

        $rule = new ProductQuestionRule(
            questions: collect([$requiredQuestion]),
            products: collect([$product]),
            attendeeDetailsCollectionMethod: null,
        );
        $rule->setValidator($this->mockValidator());

        $submitted = [[
            'product_id' => $productId,
            'product_price_id' => $productPriceId,
            'first_name' => 'A',
            'last_name' => 'B',
            'email' => 'a@b.com',
            'email_confirmation' => 'a@b.com',
            'questions' => [],
        ]];

        try {
            $rule->validate('products', $submitted, fn () => null);
            $this->fail('Expected ValidationException for missing required product question.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('products.0.questions', $errors);
            foreach (array_keys($errors) as $key) {
                $this->assertIsString($key);
                $this->assertFalse(ctype_digit((string) $key), "Error keys must not be purely numeric (got: '$key')");
            }
        }
    }

    /**
     * Bundle siblings (the 2nd+ slot of a min_per_order > 1 product) defer
     * required-question validation to the post-order "Your Order" page, so
     * the existence check must be skipped for those slots.
     */
    public function test_bundle_sibling_slot_does_not_trigger_missing_required_question(): void
    {
        $productId = 20;
        $productPriceId = 200;
        $bundleProduct = $this->makeProduct(id: $productId, priceId: $productPriceId, minPerOrder: 2);
        $requiredQuestion = $this->makeProductQuestion(id: 9, required: true, hidden: false, productId: $productId);

        $rule = new ProductQuestionRule(
            questions: collect([$requiredQuestion]),
            products: collect([$bundleProduct]),
            attendeeDetailsCollectionMethod: null,
        );
        $rule->setValidator($this->mockValidator());

        // First slot answered, second slot (sibling) has no answers — must not throw.
        $submitted = [
            [
                'product_id' => $productId,
                'product_price_id' => $productPriceId,
                'first_name' => 'A',
                'last_name' => 'B',
                'email' => 'a@b.com',
                'email_confirmation' => 'a@b.com',
                'questions' => [['question_id' => 9, 'response' => ['answer' => 'yes']]],
            ],
            [
                'product_id' => $productId,
                'product_price_id' => $productPriceId,
                'first_name' => '',
                'last_name' => '',
                'email' => '',
                'email_confirmation' => '',
                'questions' => [],
            ],
        ];

        $rule->validate('products', $submitted, fn () => null);
        $this->assertTrue(true);
    }

    /**
     * Two PER_TICKET seats may not submit the same email (case-insensitive).
     * The 2nd occurrence is flagged at 'products.{i}.email' so it surfaces
     * inline on the offending seat.
     */
    public function test_duplicate_ticket_emails_produce_uniqueness_error_keyed_by_product_email(): void
    {
        $productId = 30;
        $priceId = 300;
        $product = $this->makeProduct(id: $productId, priceId: $priceId);

        $rule = new ProductQuestionRule(
            questions: collect([]),
            products: collect([$product]),
            attendeeDetailsCollectionMethod: null,
        );
        $validator = $this->mockValidator();
        $rule->setValidator($validator);

        $submitted = [
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'dup@example.com', 'email_confirmation' => 'dup@example.com', 'questions' => []],
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'C', 'last_name' => 'D', 'email' => 'DUP@example.com', 'email_confirmation' => 'DUP@example.com', 'questions' => []],
        ];

        $rule->validate('products', $submitted, fn () => null);

        $messages = $validator->messages()->messages();
        $this->assertArrayHasKey('products.1.email', $messages);
        $this->assertArrayNotHasKey('products.0.email', $messages);
    }

    /**
     * Blank emails never collide — two unassigned seats are both valid
     * ("assign later"), and a blank email passes basic field validation.
     */
    public function test_blank_ticket_emails_are_allowed_and_do_not_collide(): void
    {
        $productId = 31;
        $priceId = 310;
        $product = $this->makeProduct(id: $productId, priceId: $priceId);

        $rule = new ProductQuestionRule(
            questions: collect([]),
            products: collect([$product]),
            attendeeDetailsCollectionMethod: null,
        );
        $validator = $this->mockValidator();
        $rule->setValidator($validator);

        $submitted = [
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'A', 'last_name' => 'B', 'email' => '', 'email_confirmation' => '', 'questions' => []],
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'C', 'last_name' => 'D', 'email' => '', 'email_confirmation' => '', 'questions' => []],
        ];

        $rule->validate('products', $submitted, fn () => null);

        $messages = $validator->messages()->messages();
        $this->assertArrayNotHasKey('products.0.email', $messages);
        $this->assertArrayNotHasKey('products.1.email', $messages);
    }

    /**
     * PER_ORDER intentionally shares the buyer's identity across every ticket,
     * so the per-order uniqueness rule must not apply.
     */
    public function test_per_order_collection_is_exempt_from_email_uniqueness(): void
    {
        $productId = 32;
        $priceId = 320;
        $product = $this->makeProduct(id: $productId, priceId: $priceId);

        $rule = new ProductQuestionRule(
            questions: collect([]),
            products: collect([$product]),
            attendeeDetailsCollectionMethod: AttendeeDetailsCollectionMethod::PER_ORDER->name,
        );
        $validator = $this->mockValidator();
        $rule->setValidator($validator);

        $submitted = [
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'same@example.com', 'email_confirmation' => 'same@example.com', 'questions' => []],
            ['product_id' => $productId, 'product_price_id' => $priceId, 'first_name' => 'C', 'last_name' => 'D', 'email' => 'same@example.com', 'email_confirmation' => 'same@example.com', 'questions' => []],
        ];

        $rule->validate('products', $submitted, fn () => null);

        $messages = $validator->messages()->messages();
        $this->assertArrayNotHasKey('products.0.email', $messages);
        $this->assertArrayNotHasKey('products.1.email', $messages);
    }

    private function makeProductQuestion(int $id, bool $required, bool $hidden, int $productId): QuestionDomainObject
    {
        $linkedProduct = m::mock(ProductDomainObject::class);
        $linkedProduct->shouldReceive('getId')->andReturn($productId);

        $question = m::mock(QuestionDomainObject::class);
        $question->shouldReceive('getId')->andReturn($id);
        $question->shouldReceive('getRequired')->andReturn($required);
        $question->shouldReceive('getIsHidden')->andReturn($hidden);
        $question->shouldReceive('getProducts')->andReturn(collect([$linkedProduct]));
        $question->shouldReceive('getType')->andReturn('SINGLE_LINE_TEXT');
        $question->shouldReceive('isAnswerValid')->andReturn(true);

        return $question;
    }

    private function makeProduct(int $id, int $priceId, int $minPerOrder = 1): ProductDomainObject
    {
        $price = m::mock(ProductPriceDomainObject::class);
        $price->shouldReceive('getId')->andReturn($priceId);
        $price->shouldReceive('getProductId')->andReturn($id);

        $product = m::mock(ProductDomainObject::class);
        $product->shouldReceive('getId')->andReturn($id);
        $product->shouldReceive('getProductType')->andReturn(ProductType::TICKET->name);
        $product->shouldReceive('getMinPerOrder')->andReturn($minPerOrder);
        $product->shouldReceive('getProductPrices')->andReturn(collect([$price]));

        return $product;
    }

    private function mockValidator(): Validator
    {
        $translator = m::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(fn ($key) => $key);

        return new Validator($translator, [], []);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
