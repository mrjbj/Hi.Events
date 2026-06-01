<?php

namespace HiEvents\Http\Request\Event;

use Illuminate\Foundation\Http\FormRequest;

class UpsertEventExpensesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expenses' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
