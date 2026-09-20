{{--
    One native <dialog> per page, driven by motion.js.

    Any [data-lightbox-open] button carrying data-full and data-caption joins
    the same set, so a page gets a working gallery -- keyboard, arrows, Escape
    -- by including this once. Built for the landing page; the directory uses
    it unchanged rather than growing a second implementation.
--}}
<dialog class="lightbox" data-lightbox aria-label="Larger photograph">
    <img data-lightbox-image src="" alt="">
    <div class="lightbox__bar">
        <button type="button" data-lightbox-prev aria-label="Previous">&#8592;</button>
        <button type="button" data-lightbox-next aria-label="Next">&#8594;</button>
        <p data-lightbox-caption></p>
        <button type="button" data-lightbox-close aria-label="Close">&#10005;</button>
    </div>
</dialog>
