<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'event', 'provider_reference', 'signature_valid', 'payload', 'outcome'])]
class WebhookEvent extends Model
{
    /** Written once, never edited. */
    public const UPDATED_AT = null;

    public const PROCESSED = 'processed';

    public const IGNORED_DUPLICATE = 'ignored_duplicate';

    public const BAD_SIGNATURE = 'bad_signature';

    public const UNKNOWN_REFERENCE = 'unknown_reference';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
        ];
    }
}
