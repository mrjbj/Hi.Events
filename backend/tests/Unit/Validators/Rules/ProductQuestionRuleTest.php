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
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;
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
    public function testThrowsWithProductIndexedStringKeyWhenRequiredProductQuestionMissing(): void
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
    public function testBundleSiblingSlotDoesNotTriggerMissingRequiredQuestion(): void
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
