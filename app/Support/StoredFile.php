<?php

namespace App\Support;

/**
 * A stored path, as the client can actually fetch it.
 *
 * Uploads live on the private disk and are served through FileController, so
 * what the database holds is never what a browser can ask for. Every resource
 * carrying a file path goes through here, so the day that route changes it
 * changes once.
 *
 * Named for files in general rather than avatars: the same translation applies
 * to a step's voice note and a photograph of a measurement book, and those are
 * the paths this platform is actually built around.
 */
class StoredFile
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return route('files.show', ['path' => $path], absolute: false);
    }
}
