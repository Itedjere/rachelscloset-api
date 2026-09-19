<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'tailor_id', 'gross_amount', 'refunded_amount', 'net_amount',
    'provider', 'provider_transfer_reference', 'status', 'failure_reason', 'released_at',
])]
class Payout extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const RELEASED = 'released';

    public const FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'released_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    /**
     * Record what is owed, the moment escrow money lands.
     *
     * Created at payment rather than at release, so what the platform owes is
     * a row from the first naira it holds rather than something computed on
     * demand at the end. `firstOrCreate` on a uniquely-keyed order_id makes a
     * replayed webhook unable to mint a second one.
     */
    public static function recordFor(Order $order, string $gross): self
    {
        return static::firstOrCreate(
            ['order_id' => $order->id],
            [
                'tailor_id' => $order->tailor_id,
                'gross_amount' => $gross,
                'refunded_amount' => 0,
                'net_amount' => $gross,
                'status' => self::PENDING,
            ],
        );
    }

    public function isReleased(): bool
    {
        return $this->status === self::RELEASED;
    }
}
