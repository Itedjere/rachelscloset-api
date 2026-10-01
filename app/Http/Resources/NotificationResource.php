<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = $this->payload ?? [];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $payload['title'] ?? 'Rachels Closet',
            'message' => $payload['message'] ?? '',
            // Where tapping it should land, as a path in the React app.
            'url' => $payload['url'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
