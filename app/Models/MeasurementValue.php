<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One typed number on a measurement set. See the migration for why it is a string. */
#[Fillable(['measurement_set_id', 'label', 'value', 'unit', 'position'])]
class MeasurementValue extends Model
{
    use HasFactory;

    public function set(): BelongsTo
    {
        return $this->belongsTo(MeasurementSet::class, 'measurement_set_id');
    }
}
