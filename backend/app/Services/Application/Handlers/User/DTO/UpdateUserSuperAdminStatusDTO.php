<?php

namespace HiEvents\Services\Application\Handlers\User\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpdateUserSuperAdminStatusDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $target_user_id,
        public readonly bool $is_super_admin,
        public readonly int $acting_user_id,
    ) {}
}
