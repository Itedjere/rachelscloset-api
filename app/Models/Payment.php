<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purpose', 'order_id', 'subscription_id', 'payer_id',
    'provider', 'provider_reference', 'amount', 'status', 'paid_at',
])]
class Payment extends Model
{
    use HasFactory;

    public const PURPOSE_ORDER = 'order';

    public const PURPOSE_SUBSCRIPTION = 'subscription';

    public const PENDING = 'pending';

    public const SUCCESSFUL = 'successful';

    public const FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::SUCCESSFUL;
    }
}
