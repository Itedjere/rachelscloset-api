<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stage of one order.
 *
 * The label, instructions and recording are a snapshot taken at assembly. See
 * the migration for why nothing here reads back through production_step_id.
 */
#[Fillable([
    'order_id', 'production_step_id', 'position',
    'label', 'instructions', 'voice_note_url',
])]
class OrderStep extends Model
{
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OrderStepPhoto::class)->orderBy('id');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
