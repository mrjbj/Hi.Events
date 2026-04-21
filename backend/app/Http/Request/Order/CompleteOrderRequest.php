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
        $eventId = (int) $this->route('event_id');
        if ($eventId <= 0) {
            return;
        }

        $filled = app(ContactRequestAutofillService::class)
            ->fillRequestInput($this->all(), $eventId);

        $this->replace($filled);
    }
}
