{{--
    One native <dialog> per page, driven by motion.js.

    Any [data-lightbox-open] button carrying data-full and data-caption joins
    the same set, so a page gets a working gallery -- keyboard, arrows, Escape
    -- by including this once. Built for the landing page; the directory uses
    it unchanged rather than growing a second implementation.
--}}
<dialog class="lightbox" data-lightbox aria-label="Larger photograph">
    {{-- The arrows sit either side of the photograph rather than on top of
         it: as absolutely positioned overlays a landscape shot covered both
         and the only way past was the keyboard. --}}
    <div class="lightbox__stage" data-lightbox-stage>
        <button type="button" class="lightbox__step" data-lightbox-prev aria-label="Previous">&#8249;</button>
        <img data-lightbox-image src="" alt="" draggable="false">
        <button type="button" class="lightbox__step" data-lightbox-next aria-label="Next">&#8250;</button>
    </div>
    <div class="lightbox__bar">
        <p data-lightbox-caption></p>
        <button type="button" data-lightbox-close aria-label="Close">&#10005;</button>
    </div>
    {{-- Built by motion.js from the same [data-lightbox-open] set the arrows
         walk, so a page gets a rail by including this file and nothing else.
         Empty and hidden until there is more than one photograph. --}}
    <div class="lightbox__rail" data-lightbox-rail hidden></div>
</dialog>
