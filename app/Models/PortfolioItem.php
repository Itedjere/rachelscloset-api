<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photograph in a tailor's gallery.
 *
 * `hidden_at` and `position` are not fillable: what appears on a public page
 * and in what order is our decision, made in the controller.
 */
#[Fillable(['tailor_id', 'order_id', 'uploaded_by', 'path', 'caption'])]
class PortfolioItem extends Model
{
    use HasFactory;

    /**
     * Where these are stored.
     *
     * Deliberately NOT a FileAccess prefix. Every other upload on this
     * platform is private and FileAccess exists to decide who may open it;
     * these are the one kind that is public by design, because a stranger
     * scanning a QR code off a business card has no account and must still
     * see the gallery. They are served by their own unauthenticated route,
     * which resolves the path to a visible row -- so a guessed path still
     * finds nothing, and a hidden photograph stops being reachable the
     * moment the tailor hides it.
     */
    public const DIRECTORY = 'portfolio';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Uploaded by the tailor herself rather than by a customer. */
    public function isHerOwn(): bool
    {
        return $this->order_id === null;
    }

    public function isVisible(): bool
    {
        return $this->hidden_at === null;
    }

    /** @param  Builder<PortfolioItem>  $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
