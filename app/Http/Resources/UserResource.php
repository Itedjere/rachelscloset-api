<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'status' => $this->status,
            /*
             * The routed URL, never the stored path. Uploads live on the
             * private disk and are only reachable through FileController, so
             * what the database holds is not something a browser can ask for.
             */
            'avatar_url' => StoredFile::url($this->avatar_url),
            'claimed' => $this->isClaimed(),
            'created_at' => $this->created_at,
            'tailor_profile' => $this->whenLoaded('tailorProfile', fn () => [
                'id' => $this->tailorProfile->id,
                'business_name' => $this->tailorProfile->business_name,
                'slug' => $this->tailorProfile->slug,
                'bio' => $this->tailorProfile->bio,
                'location' => $this->tailorProfile->location,
                'state' => $this->tailorProfile->state,
                'whatsapp_phone' => $this->tailorProfile->whatsapp_phone,
                'avg_rating' => (float) $this->tailorProfile->avg_rating,
                'orders_completed' => $this->tailorProfile->orders_completed,
            ]),
        ];
    }
}
