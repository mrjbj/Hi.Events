<?php

namespace HiEvents\Http\Request\ContactAttributeDefinition;

use HiEvents\Http\Request\BaseRequest;
use Illuminate\Validation\Rule;

class UpdateContactAttributeDefinitionRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100|alpha_dash',
            'label' => 'required|string|max:255',
            'type' => ['required', Rule::in(['text', 'select', 'multi_select'])],
            'options' => 'nullable|array|required_if:type,select|required_if:type,multi_select',
            'options.*' => 'string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'is_globally_recommended' => 'nullable|boolean',
            'option_migrations' => 'nullable|array',
            'option_migrations.*.from' => 'required_with:option_migrations|string|max:255',
            'option_migrations.*.action' => ['required_with:option_migrations', Rule::in(['rename', 'delete'])],
            'option_migrations.*.to' => 'nullable|string|max:255|required_if:option_migrations.*.action,rename',
        ];
    }
}
