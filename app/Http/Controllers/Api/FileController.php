<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves uploaded files by streaming them out of storage/app/private.
 *
 * Deliberately not `php artisan storage:link`: symlinks are unreliable on
 * shared hosting, and routing every file through a controller is what makes
 * access control possible at all. Files are never reachable by direct URL.
 *
 * Who may open what is decided by FileAccess, which resolves each path back to
 * the record that owns it. A path that belongs to nobody is refused.
 */
class FileController extends Controller
{
    public function __construct(private readonly FileAccess $access) {}

    public function show(Request $request, string $path): StreamedResponse
    {
        $path = ltrim($path, '/');

        // Reject traversal outright rather than relying on the disk to
        // normalise it. Backslashes too: this is developed on Windows, where
        // they are a separator and a URL-decoded one would otherwise pass.
        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '.')) {
            abort(404);
        }

        // A 404 rather than a 403: whether a file exists is itself worth not
        // confirming to somebody with no business reading it. That matters most
        // for a measurement book, where the existence of the record is the
        // sensitive fact.
        if (! $this->access->allows($request->user(), $path)) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            // Uploads are user content; never let a browser sniff them into HTML.
            'X-Content-Type-Options' => 'nosniff',
            // Private: a shared cache must not hold one person's measurements.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
