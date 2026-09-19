<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One measuring of one body. The photograph is the record.
 *
 * Never edited -- a body changes, and last year's numbers are how you know by
 * how much, so a new measuring is a new row.
 */
#[Fillable(['customer_id', 'recorded_by', 'photo_url', 'label', 'notes', 'taken_on'])]
class MeasurementSet extends Model
{
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['taken_on' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MeasurementValue::class)->orderBy('position')->orderBy('id');
    }
}
