<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GarmentType;
use App\Models\ProductionStep;
use App\Models\StepTemplate;
use App\Support\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Arrangements of steps, for admins and for tailors.
 *
 * One controller for both, because they are the same operation on the same
 * table: an admin edits the default (owner_id null), a tailor edits her own.
 * Which one a request may touch is decided in one place, `templateFor()`, so
 * there is no route a tailor can reach that writes somebody else's.
 */
class StepTemplateController extends Controller
{
    /**
     * What this account should see for a garment: her own arrangement if she
     * has saved one, otherwise the admin's default.
     */
    public function show(Request $request, GarmentType $garmentType): JsonResponse
    {
        $user = $request->user();
        $own = StepTemplate::where('garment_type_id', $garmentType->id)
            ->where('owner_id', $user->id)
            ->first();

        $template = $own ?? StepTemplate::defaultFor($garmentType);

        return response()->json(['data' => $this->shape($template, $own !== null)]);
    }

    /**
     * Rewrite an arrangement from the whole ordered array of step ids.
     *
     * The entire ordering API -- there is no "move up" endpoint. The arrows in
     * the interface reorder the array on the client and PUT the lot, so a
     * swap, a move, an insertion and a removal are one idempotent request that
     * cannot desynchronise and is safe to retry.
     */
    public function update(Request $request, GarmentType $garmentType): JsonResponse
    {
        $validated = $request->validate([
            'steps' => ['present', 'array'],
            /*
             * Retired steps may not be added. They stay readable in
             * arrangements that already hold them -- retiring is not meant to
             * rewrite anybody's setup -- but nothing new picks one up.
             */
            'steps.*' => [Rule::exists('production_steps', 'id')->whereNull('retired_at')],
        ]);

        $template = $this->templateFor($request, $garmentType);
        $template->reorder($validated['steps']);

        return response()->json([
            'data' => $this->shape($template->fresh(), ! $template->isDefault()),
        ]);
    }

    /**
     * A tailor discarding her own arrangement and going back to the default.
     *
     * Only ever deletes a row she owns; the default is not reachable here.
     */
    public function destroy(Request $request, GarmentType $garmentType): JsonResponse
    {
        StepTemplate::where('garment_type_id', $garmentType->id)
            ->where('owner_id', $request->user()->id)
            ->delete();

        return response()->json([
            'data' => $this->shape(StepTemplate::defaultFor($garmentType), false),
        ]);
    }

    /**
     * Which arrangement this request is allowed to write.
     *
     * An admin writes the default. Anyone else writes their own, created on
     * first save. There is no parameter for whose arrangement to edit, so
     * there is nothing to tamper with.
     */
    private function templateFor(Request $request, GarmentType $garmentType): StepTemplate
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return StepTemplate::defaultFor($garmentType);
        }

        return StepTemplate::firstOrCreate(
            ['garment_type_id' => $garmentType->id, 'owner_id' => $user->id],
            ['name' => 'My '.$garmentType->name],
        );
    }

    private function shape(StepTemplate $template, bool $isOwn): array
    {
        $template->loadMissing('items.step');

        return [
            'id' => $template->id,
            'name' => $template->name,
            'garment_type' => ['id' => $template->garment_type_id],
            // Whether the reader is looking at their own saved arrangement or
            // at the default they would inherit.
            'is_own' => $isOwn,
            'is_default' => $template->isDefault(),
            'steps' => $template->items
                ->map(fn ($item) => [
                    'id' => $item->step->id,
                    'label' => $item->step->label,
                    'instructions' => $item->step->instructions,
                    'voice_note_url' => StoredFile::url($item->step->voice_note_url),
                    'retired' => $item->step->retired_at !== null,
                    'position' => $item->position,
                ])
                ->values(),
        ];
    }

    /** The steps an arrangement can be built from. */
    public function library(): JsonResponse
    {
        return response()->json([
            'data' => ProductionStep::active()
                ->orderBy('label')
                ->get()
                ->map(fn (ProductionStep $s) => [
                    'id' => $s->id,
                    'label' => $s->label,
                    'instructions' => $s->instructions,
                    'voice_note_url' => StoredFile::url($s->voice_note_url),
                ]),
        ]);
    }
}
