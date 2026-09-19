<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph of one stage of one order.
 *
 * `order_id` is denormalised off the step; see the migration. Nothing but
 * OrderStepPhotoController creates one of these, and it copies that value from
 * the step it was handed rather than trusting a request.
 */
#[Fillable(['order_step_id', 'order_id', 'path', 'uploaded_by'])]
class OrderStepPhoto extends Model
{
    use HasFactory;

    /**
     * How many photographs one stage may carry.
     *
     * Storage is a real constraint here, not a theoretical one: the plan puts
     * a thousand orders at roughly 3GB against a shared-hosting quota. A cap
     * per stage is the cheapest place to hold that line, and three is more
     * than anybody needs to show one stage of one garment.
     */
    public const MAX_PER_STEP = 3;

    public function step(): BelongsTo
    {
        return $this->belongsTo(OrderStep::class, 'order_step_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
