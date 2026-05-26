<?php

namespace HiEvents\Models;

class OutgoingMessageEvent extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }
}
