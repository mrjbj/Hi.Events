<?php

namespace HiEvents\Http\Request\User;

use HiEvents\Http\Request\BaseRequest;

class UpdateUserSuperAdminStatusRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'is_super_admin' => ['required', 'boolean'],
        ];
    }
}
