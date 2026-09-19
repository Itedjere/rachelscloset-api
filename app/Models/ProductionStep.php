<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'instructions'])]
class ProductionStep extends Model
{
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    /**
     * Point a step at a new recording.
     *
     * WRITE-ONCE, deliberately. The old file is left exactly where it is,
     * because an order snapshots the path it was told when it was assembled.
     * Deleting the previous recording would silence that step for every
     * customer part-way through an order -- somebody who tapped play and heard
     * what "cutting" meant yesterday would get nothing today.
     *
     * This is the opposite rule to an avatar, where the old file is deleted on
     * replace. Nothing snapshots an avatar.
     */
    public function setVoiceNote(string $path): void
    {
        $this->forceFill(['voice_note_url' => $path])->save();
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('retired_at');
    }
}
