<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One block of days she bought. A ledger row, never edited.
 *
 * `days` and `amount` are snapshotted rather than read back from settings:
 * both are numbers an admin is expected to revise, and a term already sold
 * must not move when they do.
 */
class SubscriptionTerm extends Model
{
    use HasFactory;

    /** A ledger row is written once; there is nothing to update. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
