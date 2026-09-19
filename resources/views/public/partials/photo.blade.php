@php
    /*
     * One photograph.
     *
     * $image   array from config/gallery.php: ['id' => int|string, 'alt' => string]
     * $ratio   a .frame modifier: portrait | tall | square | landscape
     * $w       the width actually requested, so a phone is never sent a 4000px original
     * $class   extra classes on the frame (`drift` parallaxes the image inside it)
     * $speed   parallax speed, only meaningful alongside `parallax drift`
     * $eager   true for anything above the fold; everything else is lazy
     *
     * A local path in `id` (anything containing a slash) is used as-is, which is
     * the swap path when Rachel's own photographs replace the placeholders.
     */
    $id = $image['id'];
    $isRemote = ! str_contains((string) $id, '/');
    $w = $w ?? 900;

    $src = $isRemote
        ? "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w={$w}"
        : $id;

    // Two widths is enough. A third costs another cache entry for a difference
    // nobody can see, and these are already being resized server-side.
    $srcset = $isRemote
        ? "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w=".intval($w / 2)." ".intval($w / 2)."w, {$src} {$w}w"
        : null;
@endphp

<div class="frame {{ $ratio ?? 'portrait' }} {{ $class ?? '' }}"@isset($speed) data-speed="{{ $speed }}"@endisset>
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes ?? '(max-width: 860px) 100vw, 50vw' }}" @endif
        alt="{{ $image['alt'] }}"
        {{-- Above-the-fold images block nothing; everything else waits until it is needed. --}}
        loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}"
        fetchpriority="{{ ($eager ?? false) ? 'high' : 'auto' }}"
        decoding="async"
    >
</div>
