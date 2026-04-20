<?php

namespace HiEvents\Http\Request\Contact;

use HiEvents\Http\Request\BaseRequest;

class PrefillFromTokenRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required|string|max:2048',
        ];
    }
}
