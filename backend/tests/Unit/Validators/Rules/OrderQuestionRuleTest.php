<?php

declare(strict_types=1);

namespace Tests\Unit\Validators\Rules;

use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Validators\Rules\OrderQuestionRule;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Mockery as m;
use Tests\TestCase;

class OrderQuestionRuleTest extends TestCase
{
    /**
     * Regression: this throw used to pass an unkeyed string to
     * ValidationException::withMessages([...]), which Laravel returned as
     * {"errors": {"0": ["..."]}}. The frontend's form.setErrors() can't
     * attach numeric-keyed errors to any input, so "Continue to Payment"
     * appeared to silently fail. The key must be a string field path.
     */
    public function testThrowsWithStringKeyWhenRequiredOrderQuestionMissingFromSubmission(): void
    {
        $requiredQuestion = $this->makeQuestion(id: 42, required: true, hidden: false);
        $rule = new OrderQuestionRule(
            questions: collect([$requiredQuestion]),
            products: collect(),
        );
        $rule->setValidator($this->mockValidator());

        try {
            $rule->validate('order.questions', [], fn () => null);
            $this->fail('Expected ValidationException to be thrown for missing required question.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('order.questions', $errors, 'Error must be keyed by a field path so frontend can surface it.');
            foreach (array_keys($errors) as $key) {
                $this->assertIsString($key);
                $this->assertNotSame('', $key);
                $this->assertFalse(ctype_digit((string) $key), "Error keys must not be purely numeric (got: '$key')");
            }
        }
    }

    public function testDoesNotThrowWhenAllRequiredQuestionsArePresent(): void
    {
        $requiredQuestion = $this->makeQuestion(id: 42, required: true, hidden: false);
        $rule = new OrderQuestionRule(
            questions: collect([$requiredQuestion]),
            products: collect(),
        );
        $validator = $this->mockValidator();
        $rule->setValidator($validator);

        $rule->validate('order.questions', [['question_id' => 42, 'response' => ['answer' => 'X']]], fn () => null);

        // No exception thrown; existence-check passed. The per-question
        // validation that follows is independent and tested elsewhere.
        $this->assertTrue(true);
    }

    public function testHiddenRequiredQuestionsDoNotTriggerMissingError(): void
    {
        $hiddenRequired = $this->makeQuestion(id: 99, required: true, hidden: true);
        $rule = new OrderQuestionRule(
            questions: collect([$hiddenRequired]),
            products: collect(),
        );
        $rule->setValidator($this->mockValidator());

        $rule->validate('order.questions', [], fn () => null);

        $this->assertTrue(true);
    }

    private function makeQuestion(int $id, bool $required, bool $hidden): QuestionDomainObject
    {
        $question = m::mock(QuestionDomainObject::class);
        $question->shouldReceive('getId')->andReturn($id);
        $question->shouldReceive('getRequired')->andReturn($required);
        $question->shouldReceive('getIsHidden')->andReturn($hidden);
        // Defaults so validateQuestions (called after the missing-required
        // check) won't BadMethodCall on tests that submit answers.
        $question->shouldReceive('getType')->andReturn('SINGLE_LINE_TEXT');
        $question->shouldReceive('isAnswerValid')->andReturn(true);
        return $question;
    }

    private function mockValidator(): Validator
    {
        // Real validator wired with a no-op translator — the rule only calls
        // ->messages()->merge() on it; a stub would have to reimplement that.
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
