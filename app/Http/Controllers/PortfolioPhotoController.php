<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a gallery photograph to anybody.
 *
 * The one public file route on the platform, and the reason it exists is the
 * QR code printed on cardboard: somebody scanning it in a market has no
 * account, and a gallery that needed a bearer token would be a blank page.
 *
 * Unauthenticated does not mean unresolved. The path is still looked up
 * against a real, visible row, so a guessed path finds nothing and a
 * photograph stops being reachable the moment the tailor hides it. That is
 * the same discipline as FileAccess, with a different answer to "who".
 */
class PortfolioPhotoController extends Controller
{
    public function __invoke(string $path): StreamedResponse
    {
        $path = PortfolioItem::DIRECTORY.'/'.ltrim($path, '/');

        // Traversal is rejected outright rather than trusted to the disk.
        // Backslashes too: this is developed on Windows, where a URL-decoded
        // one is a separator.
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            abort(404);
        }

        $exists = PortfolioItem::query()
            ->visible()
            ->where('path', $path)
            ->exists();

        abort_unless($exists, 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            // User content; never let a browser sniff it into HTML.
            'X-Content-Type-Options' => 'nosniff',
            /*
             * Public and long-lived, unlike every other file here. These are
             * meant to be cached by anything between us and the phone: the
             * hosting is shared, the connection is metered, and the same
             * five photographs are served to everybody who scans the card.
             */
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
