<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventChannelFee extends BaseModel
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected function getCastMap(): array
    {
        return [
            'fee_amount' => 'float',
        ];
    }
}
