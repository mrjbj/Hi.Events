<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Services\Domain\Contact\ContactRequestAutofillService;
use HiEvents\Validators\CompleteOrderValidator;

class CompleteOrderRequest extends BaseRequest
{
    public function rules(CompleteOrderValidator $orderValidator): array
    {
        return $orderValidator->rules();
    }

    public function messages(): array
    {
        return app(CompleteOrderValidator::class)->messages();
    }

    protected function prepareForValidation(): void
    {
        $input = $this->normalizeEmails($this->all());

        $eventId = (int) $this->route('event_id');
        if ($eventId > 0) {
            $input = app(ContactRequestAutofillService::class)
                ->fillRequestInput($input, $eventId);
        }

        $this->replace($input);
    }

    /**
     * Lowercase + trim email fields so the `same:` validator on
     * email_confirmation passes regardless of what case the user typed.
     * Emails are case-insensitive in practice (per RFC 5321 the local part
     * is technically case-sensitive, but all real providers treat it as
     * insensitive). Storing lowercase also keeps contact lookup consistent
     * with the `lower(email)` partial index on the contacts table.
     */
    private function normalizeEmails(array $input): array
    {
        foreach (['email', 'email_confirmation'] as $field) {
            if (isset($input['order'][$field]) && is_string($input['order'][$field])) {
                $input['order'][$field] = strtolower(trim($input['order'][$field]));
            }
        }
        if (isset($input['products']) && is_array($input['products'])) {
            foreach ($input['products'] as $idx => $product) {
                foreach (['email', 'email_confirmation'] as $field) {
                    if (isset($product[$field]) && is_string($product[$field])) {
                        $input['products'][$idx][$field] = strtolower(trim($product[$field]));
                    }
                }
            }
        }
        return $input;
    }
}
