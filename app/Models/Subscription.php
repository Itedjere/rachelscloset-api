<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tailor's standing with the platform.
 *
 * Nothing here is mass-assignable: every column is either money-derived or a
 * decision about whether she is listed, and both are our own code's business.
 *
 * THE TIMESTAMPS ARE THE TRUTH AND `status` IS A LABEL. If you are about to
 * write `where('status', 'active')`, that is the bug this class exists to
 * prevent -- use `scopeCovering()`, which compares timestamps. A status column
 * is only ever as fresh as the last thing that recomputed it, and on a host
 * with no worker that can be never.
 */
class Subscription extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';

    /** Paid days are over, the courtesy window is not. Still listed. */
    public const GRACE = 'grace';

    public const LAPSED = 'lapsed';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(SubscriptionTerm::class)->orderBy('starts_at');
    }

    /* ===================================================================== */

    /**
     * Is she covered right now?
     *
     * Grace counts. A Nigerian bank transfer settling a day late must not
     * delist somebody who paid on time, and the difference between "her money
     * is in transit" and "she has stopped paying" is not one we can see.
     */
    public function covers(): bool
    {
        return $this->coversAt(now());
    }

    public function coversAt(\DateTimeInterface $moment): bool
    {
        $end = $this->grace_ends_at ?? $this->current_period_end;

        return $end !== null && $end->greaterThan($moment);
    }

    /**
     * The only correct way to ask this in a query.
     *
     * Compares timestamps, never `status`, so a stale label cannot list a
     * tailor whose term ended while no cron was running.
     *
     * @param  Builder<Subscription>  $query
     */
    public function scopeCovering(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('grace_ends_at', '>', now())
                ->orWhere(function (Builder $inner) {
                    $inner->whereNull('grace_ends_at')->where('current_period_end', '>', now());
                });
        });
    }

    /** Days left on the paid period, negative once it has passed. */
    public function daysRemaining(): ?int
    {
        return $this->current_period_end
            ? (int) ceil(now()->diffInDays($this->current_period_end, false))
            : null;
    }

    /**
     * Recompute the label from the timestamps.
     *
     * Called whenever anything reads the subscription, so the wording is
     * right on screen. Nothing depends on it having been called.
     */
    public function syncStatus(): self
    {
        $status = match (true) {
            $this->current_period_end?->isFuture() === true => self::ACTIVE,
            $this->covers() => self::GRACE,
            default => self::LAPSED,
        };

        if ($status !== $this->status) {
            $this->forceFill(['status' => $status])->save();
        }

        return $this;
    }

    /** Hers, creating the row on first sight rather than at registration. */
    public static function forTailor(User $tailor): self
    {
        return static::firstOrCreate(['user_id' => $tailor->id])->syncStatus();
    }
}
