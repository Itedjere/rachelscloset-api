<?php

namespace App\Http\Resources;

use App\Models\OrderStep;
use App\Models\OrderStepPhoto;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stage of an order, as either side sees it.
 *
 * Section 9 shaped this in two places -- the tracker endpoint and the order
 * resource -- which was survivable while they were six identical lines and
 * stopped being so the moment photographs had to appear in both. One shape,
 * one place.
 *
 * @mixin OrderStep
 */
class OrderStepResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,

            // The snapshot taken at assembly, always. Never the library.
            'label' => $this->label,
            'instructions' => $this->instructions,
            'voice_note_url' => StoredFile::url($this->voice_note_url),

            'complete' => $this->isComplete(),
            'completed_at' => $this->completed_at,

            'photos' => $this->whenLoaded('photos', fn () => $this->photos
                ->map(fn (OrderStepPhoto $photo) => [
                    'id' => $photo->id,
                    'url' => StoredFile::url($photo->path),
                    'created_at' => $photo->created_at,
                ])->values()),
        ];
    }
}
