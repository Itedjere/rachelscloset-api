<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody said something is wrong.
 *
 * `status` and everything about the resolution are absent from Fillable, for
 * the same reason an order's status is: whether money is frozen is our own
 * code's decision and no request body may reach it.
 */
#[Fillable(['order_id', 'raised_by', 'reason'])]
class Dispute extends Model
{
    use HasFactory;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** The customer got her money back, in whole or in part. */
    public const REFUNDED = 'refunded';

    /** The work stood up; the tailor was paid. */
    public const RELEASED = 'released';

    /** Not a judgement: the parcel turned up after all. */
    public const WITHDRAWN = 'withdrawn';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * Opened by staff after a phone call rather than by the customer
     * herself -- she may be ringing precisely because she cannot work the
     * app, and the screen should say so rather than implying she tapped it.
     */
    public function wasOpenedByStaff(): bool
    {
        return $this->raisedBy?->isAdmin() ?? false;
    }
}
