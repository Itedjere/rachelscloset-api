<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's verdict on the other, for one order.
 *
 * `status`, `published_at` and the approval columns are deliberately absent
 * from Fillable, like an order's status: whether a review is visible is our
 * decision, made in PublishReview, and no request body may reach it.
 */
#[Fillable(['order_id', 'direction', 'author_id', 'subject_id', 'rating', 'body'])]
class Review extends Model
{
    use HasFactory;

    public const CUSTOMER_TO_TAILOR = 'customer_to_tailor';

    public const TAILOR_TO_CUSTOMER = 'tailor_to_customer';

    public const PUBLISHED = 'published';

    public const HELD = 'held';

    /** Where the proof gate starts to apply. Below this, nothing is held. */
    public const GATED_FROM = 4;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
            'rating' => 'integer',
            'proof_ratio_snapshot' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** @param  Builder<Review>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::PUBLISHED);
    }

    public function publish(?User $approvedBy = null): void
    {
        $this->forceFill([
            'status' => self::PUBLISHED,
            'published_at' => $this->published_at ?? now(),
            'approved_by' => $approvedBy?->id ?? $this->approved_by,
            'approved_at' => $approvedBy ? now() : $this->approved_at,
        ])->save();
    }

    public function hold(string $ratio): void
    {
        $this->forceFill([
            'status' => self::HELD,
            'published_at' => null,
            'proof_ratio_snapshot' => $ratio,
        ])->save();
    }
}
