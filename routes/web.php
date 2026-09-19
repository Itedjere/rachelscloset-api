<?php

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
