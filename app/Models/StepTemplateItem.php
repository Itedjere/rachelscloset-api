<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['step_template_id', 'production_step_id', 'position'])]
class StepTemplateItem extends Model
{
    use HasFactory;

    public function template(): BelongsTo
    {
        return $this->belongsTo(StepTemplate::class, 'step_template_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ProductionStep::class, 'production_step_id');
    }
}
