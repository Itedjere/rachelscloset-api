<?php

use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\PortfolioPhotoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
|
| Server-rendered Blade, not the React app. Three reasons, in order of how
| much they matter:
|
| 1. A QR code printed on cardboard points at a tailor's profile. Somebody
|    scanning it in a market on a cheap phone must get a readable page, not a
|    spinner waiting on a JavaScript bundle. A 404 or a blank page on a printed
|    card is a permanent physical failure -- the card cannot be reissued.
| 2. "Tailors lack visibility" is one of the six problems this platform exists
|    to solve, and the answer to it is a directory search engines can read.
| 3. It is the fastest thing we can serve on the hosting we actually have.
|
| The React app keeps the signed-in experience. They share a design language,
| not a renderer.
|
*/

Route::view('/', 'public.home')->name('home');

/*
| The design language, visible. Not decoration: it is what stops the next
| twenty pages each inventing their own spacing and their own shade of plum.
*/
Route::view('/styleguide', 'public.styleguide')->name('styleguide');

/*
| The Fashion House directory.
|
| The answer to "tailors lack visibility", and the address a QR code printed
| on cardboard resolves to. Nothing here needs an account, and the slug never
| moves -- see TailorProfile::slugFor().
*/
Route::get('/tailors', [DirectoryController::class, 'index'])->name('directory');
Route::get('/t/{slug}', [DirectoryController::class, 'show'])->name('tailor');

/*
| Gallery photographs: the one public file route on the platform. Still
| resolved against a visible row, so a guessed path finds nothing and hiding
| a photograph takes it offline.
*/
Route::get('/photo/{path}', PortfolioPhotoController::class)
    ->where('path', '[A-Za-z0-9._-]+')
    ->name('portfolio.photo');

/*
| Being found is the point of this section, so the sitemap is served rather
| than written to disk -- there is no queue to regenerate it on.
*/
Route::get('/sitemap.xml', [DirectoryController::class, 'sitemap'])->name('sitemap');

Route::get('/robots.txt', function () {
    $lines = [
        'User-agent: *',
        'Allow: /',
        // The API is not a page and has nothing to index.
        'Disallow: /api/',
        'Sitemap: '.route('sitemap'),
    ];

    return response(implode('
', $lines).'
')->header('Content-Type', 'text/plain');
})->name('robots');
