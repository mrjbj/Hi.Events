<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentAdjustment extends BaseModel
{
    protected function getTimestampsEnabled(): bool
    {
        return false;
    }

    protected function getCastMap(): array
    {
        return [
            'original_total_gross' => 'float',
            'original_total_before_additions' => 'float',
            'original_total_tax' => 'float',
            'original_total_fee' => 'float',
            'adjusted_total_gross' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
