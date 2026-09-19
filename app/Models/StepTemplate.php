<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['garment_type_id', 'owner_id', 'name'])]
class StepTemplate extends Model
{
    use HasFactory;

    public function garmentType(): BelongsTo
    {
        return $this->belongsTo(GarmentType::class);
    }

    /** Null for the admin's default arrangement. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StepTemplateItem::class)->orderBy('position');
    }

    public function isDefault(): bool
    {
        return $this->owner_id === null;
    }

    /**
     * The admin's default arrangement for a garment type.
     *
     * The only thing that creates one, which is what makes "exactly one
     * default per garment type" true -- the schema cannot express it, because
     * MySQL allows any number of rows where part of a unique key is NULL.
     */
    public static function defaultFor(GarmentType $type): self
    {
        return static::firstOrCreate(
            ['garment_type_id' => $type->id, 'owner_id' => null],
            ['name' => $type->name.' — standard'],
        );
    }

    /**
     * What this tailor should actually see for this garment.
     *
     * Her own arrangement if she has saved one, otherwise the admin's default.
     * This is the single question assembling an order asks, and the reason the
     * two live in one table.
     */
    public static function forTailor(GarmentType $type, ?User $tailor): ?self
    {
        $own = $tailor
            ? static::where('garment_type_id', $type->id)->where('owner_id', $tailor->id)->first()
            : null;

        return $own ?? static::where('garment_type_id', $type->id)->whereNull('owner_id')->first();
    }

    /**
     * Rewrite the whole arrangement from an ordered array of step ids.
     *
     * The entire ordering API. A swap, a move, an insertion and a removal are
     * all the same call, positions are renumbered densely from one, and
     * sending it twice leaves the same result -- so a retry on a flaky
     * connection cannot corrupt the order. There is no "move up" endpoint; the
     * arrows in the interface reorder the array client-side and PUT the lot.
     */
    public function reorder(array $stepIds): void
    {
        // Keep the caller's order, drop anything repeated.
        $stepIds = array_values(array_unique(array_map('intval', $stepIds)));

        DB::transaction(function () use ($stepIds) {
            // Anything no longer in the array is gone. `[0]` stands in for an
            // empty array, which whereNotIn would otherwise treat as "match
            // nothing" and leave every row in place.
            $this->items()->whereNotIn('production_step_id', $stepIds ?: [0])->delete();

            foreach ($stepIds as $position => $stepId) {
                StepTemplateItem::updateOrCreate(
                    ['step_template_id' => $this->id, 'production_step_id' => $stepId],
                    ['position' => $position + 1],
                );
            }
        });
    }
}
