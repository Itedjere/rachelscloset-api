<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer standing in front of a tailor, as the new-order screen sees her.
 *
 * One shape for "found her" and "added her", so the screen carries on the same
 * way whichever happened. Deliberately thin: no email, no order history, no
 * measurements -- a tailor who can look somebody up by phone learns her name
 * and nothing more.
 *
 * @mixin User
 */
class FoundCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'avatar_url' => StoredFile::url($this->avatar_url),
            // An unclaimed profile has consented to nothing, and cannot be
            // signed into until she sets a PIN through the claim flow.
            'claimed' => $this->isClaimed(),
        ];
    }
}
