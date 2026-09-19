<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeasurementSet;
use App\Models\MeasurementValue;
use App\Models\User;
use App\Services\FileAccess;
use App\Services\Measurements\MeasurementAccess;
use App\Support\StoredFile;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Measurements.
 *
 * Every refusal here is 404, never 403. A 403 tells the asker the record
 * exists, and for this data the existence of a row is itself worth not
 * confirming: it says a named person has been measured by somebody.
 */
class MeasurementController extends Controller
{
    public function __construct(private readonly MeasurementAccess $access) {}

    /** One customer's measuring history, newest first. */
    public function index(Request $request, User $customer): JsonResponse
    {
        abort_unless($this->access->canSeeAnyOf($request->user(), $customer), 404);

        $sets = MeasurementSet::query()
            ->with(['values', 'recordedBy'])
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->get()
            /*
             * Filtered per row, not by the query.
             *
             * On an unclaimed profile each set belongs to whichever tailor
             * took it, and two tailors may each have measured the same
             * walk-in. canSeeAnyOf() let her in; this decides what she sees.
             */
            ->filter(fn (MeasurementSet $set) => $this->access->allows($request->user(), $set))
            ->values();

        /*
         * The customer rides along with her own list.
         *
         * A tailor needs the name at the top of the page and whether the
         * profile is claimed, so she knows whether to offer an invite. The
         * alternative -- a lookup endpoint taking an id -- would let anybody
         * walk 1..n and build a directory of other people's clients, which
         * is the exact thing CustomerLookupController refuses to allow. Here
         * the access check has already happened.
         */
        return response()->json([
            'data' => $sets->map(fn (MeasurementSet $set) => $this->shape($set)),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'claimed' => $customer->isClaimed(),
            ],
        ]);
    }

    public function show(Request $request, MeasurementSet $measurementSet): JsonResponse
    {
        $measurementSet->load(['values', 'recordedBy', 'customer']);

        abort_unless($this->access->allows($request->user(), $measurementSet), 404);

        return response()->json(['data' => $this->shape($measurementSet)]);
    }

    /**
     * Record a measuring.
     *
     * The photograph is the record, so it is required and the typed numbers
     * are not. A tailor who reads poorly photographs the page of her book and
     * is finished; one who wants the numbers searchable adds them too.
     */
    public function store(Request $request, User $customer): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);
        abort_unless($customer->isCustomer(), 404);

        /*
         * Recording is not the same permission as reading.
         *
         * A tailor may write a set for somebody she is working with or has
         * consent from -- and for an unclaimed profile, because creating the
         * customer and measuring her is one continuous act on the shop floor.
         */
        abort_unless($this->access->canSeeAnyOf($tailor, $customer), 404);

        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'max:'.UploadLimits::maxKilobytes(),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'taken_on' => ['nullable', 'date'],

            // Optional, and validated only if she bothered.
            'values' => ['nullable', 'array', 'max:40'],
            'values.*.label' => ['required', 'string', 'max:60'],
            'values.*.value' => ['required', 'string', 'max:40'],
            'values.*.unit' => ['nullable', 'string', 'max:20'],
        ], [
            'photo.required' => 'A photograph of the measurements is needed.',
            'photo.mimetypes' => 'That file is not a photograph this platform can show.',
        ]);

        $set = DB::transaction(function () use ($request, $validated, $customer, $tailor) {
            $set = MeasurementSet::create([
                'customer_id' => $customer->id,
                'recorded_by' => $tailor->id,
                'photo_url' => $request->file('photo')->store(FileAccess::MEASUREMENTS, 'local'),
                'label' => $validated['label'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'taken_on' => $validated['taken_on'] ?? now()->toDateString(),
            ]);

            foreach (array_values($validated['values'] ?? []) as $position => $value) {
                MeasurementValue::create([
                    'measurement_set_id' => $set->id,
                    'label' => $value['label'],
                    'value' => $value['value'],
                    'unit' => $value['unit'] ?? null,
                    'position' => $position + 1,
                ]);
            }

            return $set;
        });

        return response()->json(
            ['data' => $this->shape($set->fresh()->load(['values', 'recordedBy']))],
            201,
        );
    }

    /**
     * Only the person whose body it is may delete a set.
     *
     * Not the tailor who recorded it: a record of somebody's body is hers,
     * and a tailor removing measurements she took is removing the customer's
     * history from her, not her own. A wrong set is a new set -- the history
     * is the point.
     */
    public function destroy(Request $request, MeasurementSet $measurementSet): JsonResponse
    {
        abort_unless($measurementSet->customer_id === $request->user()->id, 404);

        $measurementSet->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function shape(MeasurementSet $set): array
    {
        return [
            'id' => $set->id,
            'label' => $set->label,
            'notes' => $set->notes,
            'taken_on' => $set->taken_on?->toDateString(),
            'photo_url' => StoredFile::url($set->photo_url),
            'recorded_by' => $set->recordedBy?->only(['id', 'name']),
            'values' => $set->values->map(fn (MeasurementValue $value) => [
                'id' => $value->id,
                'label' => $value->label,
                'value' => $value->value,
                'unit' => $value->unit,
            ])->values(),
            'created_at' => $set->created_at,
        ];
    }
}
