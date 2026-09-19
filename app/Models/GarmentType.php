<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'description', 'position'])]
class GarmentType extends Model
{
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    /**
     * The slug is generated once and never moved by a rename.
     *
     * Same reasoning as a tailor's profile slug: it ends up in a URL somebody
     * keeps. A rename changes the name, not the address.
     */
    protected static function booted(): void
    {
        static::creating(function (self $type) {
            $type->slug ??= static::uniqueSlug($type->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'garment';
        $slug = $base;
        $n = 2;

        while (static::withRetired()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function templates(): HasMany
    {
        return $this->hasMany(StepTemplate::class);
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /** Everything a customer may choose from, in the order an admin arranged. */
    public function scopeActive($query)
    {
        return $query->whereNull('retired_at')->orderBy('position')->orderBy('id');
    }

    /** Including retired ones -- for the admin screen, and for slug collisions. */
    public function scopeWithRetired($query)
    {
        return $query;
    }
}
