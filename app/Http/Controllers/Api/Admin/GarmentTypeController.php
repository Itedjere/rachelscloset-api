<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GarmentType;
use App\Models\StepTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GarmentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = GarmentType::query()
            ->when(! $request->boolean('include_retired'), fn ($q) => $q->whereNull('retired_at'))
            ->orderBy('position')
            ->orderBy('id')
            ->withCount(['templates as arrangements_count'])
            ->get()
            ->map(fn (GarmentType $t) => $this->shape($t));

        return response()->json(['data' => $types]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // Straight onto the end of the list; an admin reorders afterwards.
        $validated['position'] = (int) GarmentType::max('position') + 1;

        $type = GarmentType::create($validated);

        // Every garment type has exactly one default arrangement from birth,
        // so there is never a garment a tailor cannot get steps for.
        StepTemplate::defaultFor($type);

        return response()->json(['data' => $this->shape($type)], 201);
    }

    public function update(Request $request, GarmentType $garmentType): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // The slug is deliberately not updatable. See the model.
        $garmentType->update($validated);

        return response()->json(['data' => $this->shape($garmentType->fresh())]);
    }

    /**
     * Retire or bring back.
     *
     * Never a delete. Orders placed against a garment type have to keep
     * reading correctly years later, and a row that disappears takes a
     * customer's history with it.
     */
    public function retire(Request $request, GarmentType $garmentType): JsonResponse
    {
        $validated = $request->validate(['retired' => ['required', 'boolean']]);

        $garmentType->forceFill([
            'retired_at' => $validated['retired'] ? now() : null,
        ])->save();

        return response()->json(['data' => $this->shape($garmentType->fresh())]);
    }

    /**
     * Rewrite the whole order from an array of ids.
     *
     * Same shape as reordering steps within an arrangement, and for the same
     * reasons: one idempotent request, dense positions, no gap arithmetic.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => [Rule::exists('garment_types', 'id')],
        ]);

        foreach (array_values(array_unique($validated['ids'])) as $position => $id) {
            GarmentType::whereKey($id)->update(['position' => $position + 1]);
        }

        return $this->index($request);
    }

    private function shape(GarmentType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'description' => $type->description,
            'position' => $type->position,
            'retired' => $type->isRetired(),
            'arrangements_count' => $type->arrangements_count ?? null,
        ];
    }
}
