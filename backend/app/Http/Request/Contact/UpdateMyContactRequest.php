<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class UpdateMyContactRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:2048',
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'attributes' => 'sometimes|array',
        ];
    }
}
