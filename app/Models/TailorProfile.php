<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'business_name', 'slug', 'bio',
    'location', 'state', 'whatsapp_phone',
])]
class TailorProfile extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Reviews of this tailor, keyed through the account rather than the
     * profile: a review is written about a person, and the profile is her
     * shopfront. The directory counts these, so it needs them here.
     */
    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'subject_id', 'user_id');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(PortfolioItem::class, 'tailor_id', 'user_id');
    }

    /**
     * A unique, readable address for her public profile.
     *
     * Generated once when the profile is created and then left alone. The slug
     * is what a QR code on a printed business card resolves to, so changing it
     * does not merely break a link somebody might have bookmarked -- it turns
     * cardboard already in circulation into a dead end, with no way to reissue
     * it. Renaming the business does not move the address.
     */
    public static function slugFor(string $businessName, ?int $ignoreId = null): string
    {
        $base = Str::slug($businessName) ?: 'tailor';
        $slug = $base;
        $suffix = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }
}
