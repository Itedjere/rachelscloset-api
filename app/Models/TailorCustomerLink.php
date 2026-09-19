<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's answer about one tailor.
 *
 * `status` is not fillable: granting and revoking are our own code, for the
 * same reason an order's status is not. A request body must never be able to
 * grant somebody access to a body.
 */
#[Fillable(['tailor_id', 'customer_id'])]
class TailorCustomerLink extends Model
{
    use HasFactory;

    public const GRANTED = 'granted';

    public const REVOKED = 'revoked';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED;
    }

    public function grant(): void
    {
        $this->forceFill([
            'status' => self::GRANTED,
            'granted_at' => now(),
            'revoked_at' => null,
        ])->save();
    }

    public function revoke(): void
    {
        $this->forceFill([
            'status' => self::REVOKED,
            'revoked_at' => now(),
        ])->save();
    }
}
