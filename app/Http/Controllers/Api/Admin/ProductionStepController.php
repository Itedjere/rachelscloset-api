<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductionStep;
use App\Services\FileAccess;
use App\Support\StoredFile;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The step library.
 *
 * An admin writes a label, and — the part that matters — records somebody
 * saying what the step means. Many tailors read poorly; the recording is how
 * the library is actually read.
 */
class ProductionStepController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $steps = ProductionStep::query()
            ->when(! $request->boolean('include_retired'), fn ($q) => $q->whereNull('retired_at'))
            ->orderBy('label')
            ->get()
            ->map(fn (ProductionStep $s) => $this->shape($s));

        return response()->json(['data' => $steps]);
    }

    public function store(Request $request): JsonResponse
    {
        $step = ProductionStep::create($this->validated($request));

        return response()->json(['data' => $this->shape($step)], 201);
    }

    public function update(Request $request, ProductionStep $productionStep): JsonResponse
    {
        /*
         * Renaming a step does NOT change any order already in progress.
         * Orders snapshot the label they were told at assembly time, because
         * a customer was shown those words and an admin tidying up wording
         * afterwards must not rewrite the timeline she already read. Section 9
         * builds that snapshot; there is a test for it there.
         */
        $productionStep->update($this->validated($request));

        return response()->json(['data' => $this->shape($productionStep->fresh())]);
    }

    /**
     * Attach a recording.
     *
     * Write-once: this stores a new path and leaves any previous file exactly
     * where it is. See ProductionStep::setVoiceNote for why.
     */
    public function voiceNote(Request $request, ProductionStep $productionStep): JsonResponse
    {
        $request->validate([
            'voice_note' => [
                'required',
                'file',
                'max:'.UploadLimits::maxKilobytes(),
                // Whatever the browser's MediaRecorder produced: webm/opus on
                // Android Chrome, mp4/aac on Safari. Both, plus the ordinary
                // formats somebody might attach from their phone's files.
                'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/aac,audio/flac,audio/wav,audio/x-wav,audio/x-m4a,video/webm,video/mp4',
            ],
        ], [
            'voice_note.mimetypes' => 'That file is not audio this platform can play.',
        ]);

        $productionStep->setVoiceNote(
            $request->file('voice_note')->store(FileAccess::STEP_VOICE_NOTES, 'local'),
        );

        return response()->json(['data' => $this->shape($productionStep->fresh())]);
    }

    public function retire(Request $request, ProductionStep $productionStep): JsonResponse
    {
        $validated = $request->validate(['retired' => ['required', 'boolean']]);

        $productionStep->forceFill([
            'retired_at' => $validated['retired'] ? now() : null,
        ])->save();

        return response()->json(['data' => $this->shape($productionStep->fresh())]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function shape(ProductionStep $step): array
    {
        return [
            'id' => $step->id,
            'label' => $step->label,
            'instructions' => $step->instructions,
            // The routed URL, never the disk path.
            'voice_note_url' => StoredFile::url($step->voice_note_url),
            'has_voice_note' => filled($step->voice_note_url),
            'retired' => $step->isRetired(),
        ];
    }
}
