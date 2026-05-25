<?php

namespace HiEvents\Validators\Rules;

use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\QuestionDomainObject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductQuestionRule extends BaseQuestionRule
{
    private bool $skipBasicAttendeeValidation = false;

    public function __construct(
        Collection $questions,
        Collection $products,
        ?string $attendeeDetailsCollectionMethod = null,
    ) {
        parent::__construct($questions, $products);

        $this->skipBasicAttendeeValidation = $attendeeDetailsCollectionMethod === AttendeeDetailsCollectionMethod::PER_ORDER->name;
    }
    /**
     * @throws ValidationException
     */
    protected function validateRequiredQuestionArePresent(Collection $orderProducts): void
    {
        $items = $orderProducts->values();
        foreach ($items as $index => $productData) {
            // Bundle siblings (2nd-and-later seat of a min_per_order > 1 product)
            // defer attendee details to the "Your Order" page, so required-question
            // validation is relaxed here. The buyer still answers them for the
            // first seat of the bundle.
            if ($this->isBundleSiblingSlot($productData, (int) $index, $items)) {
                continue;
            }

            $productId = $this->getProductIdFromProductPriceId($productData['product_price_id']);
            $questions = $productData['questions'] ?? [];

            $requiredQuestionIds = $this->questions
                ->filter(function (QuestionDomainObject $question) use ($productId) {
                    return $question->getRequired()
                        && !$question->getIsHidden()
                        && $question->getProducts()?->map(fn($product) => $product->getId())->contains($productId);
                })
                ->map(fn(QuestionDomainObject $question) => $question->getId());

            if (array_diff($requiredQuestionIds->toArray(), collect($questions)->pluck('question_id')->toArray())) {
                // Keyed by 'products.{index}.questions' so the frontend's
                // form.setErrors() / fallback toast can surface this — previously
                // the message landed at a numeric key with no path and the
                // submit appeared to silently fail.
                throw ValidationException::withMessages([
                    "products.$index.questions" => __('Required questions have not been answered. Please reload the page.'),
                ]);
            }
        }
    }

    /**
     * A "bundle sibling" is the 2nd-or-later attendee slot for the same product
     * when that product has min_per_order > 1 (i.e. it's sold as a pack).
     * The first slot of the bundle still validates normally — that's the buyer's
     * own seat. Subsequent slots are deferred to the Your Order page where the
     * buyer fills in real guest details.
     */
    private function isBundleSiblingSlot(mixed $productRequestData, int $productIndex, Collection $allProducts): bool
    {
        $product = $this->getProductDomainObject($productRequestData['product_id'] ?? null);
        if (!$product) {
            return false;
        }
        $minPerOrder = (int) $product->getMinPerOrder();
        if ($minPerOrder <= 1) {
            return false;
        }

        $firstIndexOfThisProduct = $allProducts
            ->search(fn($p) => ($p['product_id'] ?? null) === ($productRequestData['product_id'] ?? null));

        return $firstIndexOfThisProduct !== false
            && $firstIndexOfThisProduct !== $productIndex;
    }

    protected function validateQuestions(mixed $products): array
    {
        $validationMessages = [];
        $productsCollection = $products instanceof Collection ? $products : collect($products);

        foreach ($products as $productIndex => $productRequestData) {
            $productDomainObject = $this->getProductDomainObject($productRequestData['product_id']);

            if (!$productDomainObject) {
                $validationMessages['products.' . $productIndex][] = __('This product is outdated. Please reload the page.');
                continue;
            }

            $isBundleSibling = $this->isBundleSiblingSlot($productRequestData, (int) $productIndex, $productsCollection);

            if ($productDomainObject->getProductType() === ProductType::TICKET->name
                && !$this->skipBasicAttendeeValidation
                && !$isBundleSibling
            ) {
                $validationMessages = [
                    ...$validationMessages,
                    ...$this->validateBasicTicketFields($productRequestData, $productIndex),
                ];
            }

            // Skip per-question validation for bundle siblings — they defer to
            // the Your Order page. The first seat of the bundle still validates.
            if ($isBundleSibling) {
                continue;
            }

            $questions = $productRequestData['questions'] ?? [];
            foreach ($questions as $questionIndex => $question) {
                $questionDomainObject = $this->getQuestionDomainObject($question['question_id'] ?? null);
                $key = 'products.' . $productIndex . '.questions.' . $questionIndex . '.response';
                $response = empty($question['response']) ? null : $question['response'];
                $answer = $response['answer'] ?? $response;

                if (!$questionDomainObject) {
                    $validationMessages[$key . '.answer'][] = __('This question is outdated. Please reload the page.');
                    continue;
                }

                if (is_null($response) && !$questionDomainObject->getRequired()) {
                    continue;
                }

                if ($questionDomainObject->getRequired()) {
                    $validationMessages = $this->validateRequiredFields($questionDomainObject, $response, $key, $validationMessages);
                }

                if (!$questionDomainObject->isAnswerValid($answer)) {
                    $validationMessages[$key . '.answer'][] = __('Please select an option');
                }

                $validationMessages = $this->validateResponseLength($questionDomainObject, $response, $key, $validationMessages);
            }
        }

        return $validationMessages;
    }

    private function validateBasicTicketFields(mixed $productRequestData, int|string $productIndex): array
    {
        $validationMessages = [];

        $validator = Validator::make($productRequestData, [
            'first_name' => ['required', 'string', 'min:1', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:100'],
            'email_confirmation' => ['required', 'string', 'email', 'max:100', 'same:email'],
        ], [
            'email_confirmation.required' => __('Please confirm the email address'),
            'email_confirmation.same' => __('Email addresses do not match'),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $validationMessages["products.$productIndex.$field"][] = $message;
                }
            }
        }

        return $validationMessages;
    }
}
