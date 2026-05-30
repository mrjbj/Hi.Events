<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderPayment extends BaseModel
{
    use SoftDeletes;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function getCastMap(): array
    {
        return [
            'amount' => 'float',
        ];
    }
}
