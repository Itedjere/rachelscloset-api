/*
 * Rachel's Closet — motion.
 * ============================================================================
 *
 * Everything the reference template spends 1.12MB of libraries on, in one file
 * with no dependencies: scroll reveals, split text, parallax, counters, a
 * scroll-snap carousel and a native-dialog lightbox.
 *
 * Three rules it follows throughout:
 *
 * 1. One IntersectionObserver for every reveal on the page, not one each.
 * 2. One requestAnimationFrame loop for parallax, and it only runs while
 *    something parallaxed is actually on screen.
 * 3. If the person asked for reduced motion, do none of it. The page is built
 *    so that doing nothing leaves everything visible and in place.
 *
 * Plain ES5-compatible syntax where it costs nothing: this runs on old Chrome
 * on cheap Android, and a syntax error there is a blank page, not a warning.
 */

(function () {
  "use strict";

  var reduced =
    window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ========================================================================
     Photographs: fade in once decoded
     ======================================================================== */

  function watchImages() {
    var images = document.querySelectorAll(".frame > img");

    Array.prototype.forEach.call(images, function (img) {
      if (img.complete && img.naturalWidth > 0) {
        img.classList.add("is-loaded");
        return;
      }

      img.addEventListener("load", function () {
        img.classList.add("is-loaded");
      });

      // A photograph that fails is still better shown than left at opacity 0 --
      // the alt text has to be able to get out.
      img.addEventListener("error", function () {
        img.classList.add("is-loaded");
      });
    });
  }

  /* ========================================================================
     Split text
     ======================================================================== */

  /*
   * Wraps each word in a mask so it can rise into place, staggered.
   *
   * Words, not characters: a didone at display size already has enough going
   * on, per-letter stagger on a long line reads as a novelty, and splitting to
   * characters breaks how a screen reader announces the heading.
   *
   * The original text is kept in aria-label and the spans hidden from the
   * accessibility tree, so the heading is still announced as one sentence.
   */
  function splitText() {
    var targets = document.querySelectorAll("[data-split]");

    Array.prototype.forEach.call(targets, function (element) {
      var text = element.textContent.trim();
      if (!text) return;

      element.setAttribute("aria-label", text);

      var words = text.split(/\s+/);
      var html = "";

      for (var i = 0; i < words.length; i++) {
        html +=
          '<span class="split-word" aria-hidden="true" style="--word-index:' +
          i +
          '"><span>' +
          words[i].replace(/&/g, "&amp;").replace(/</g, "&lt;") +
          "</span></span>";

        if (i < words.length - 1) html += " ";
      }

      element.innerHTML = html;
    });
  }

  /* ========================================================================
     Reveals
     ======================================================================== */

  function watchReveals() {
    var targets = document.querySelectorAll("[data-reveal], [data-split], [data-count]");

    if (!("IntersectionObserver" in window)) {
      // No observer: show everything immediately rather than nothing ever.
      Array.prototype.forEach.call(targets, function (el) {
        el.classList.add("is-in");
        if (el.hasAttribute("data-count")) el.textContent = el.getAttribute("data-count");
      });
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;

          entry.target.classList.add("is-in");

          if (entry.target.hasAttribute("data-count")) countUp(entry.target);

          // Reveals happen once. Re-animating on the way back up is the thing
          // that makes a long page feel restless.
          observer.unobserve(entry.target);
        });
      },
      {
        // Fires a little before the element is fully in view, so the movement
        // has finished by the time it is properly being looked at.
        rootMargin: "0px 0px -12% 0px",
        threshold: 0.01,
      },
    );

    Array.prototype.forEach.call(targets, function (el) {
      observer.observe(el);
    });
  }

  /* ========================================================================
     Counters
     ======================================================================== */

  function countUp(element) {
    var target = parseFloat(element.getAttribute("data-count"));
    var suffix = element.getAttribute("data-count-suffix") || "";

    if (isNaN(target)) return;

    if (reduced) {
      element.textContent = String(target) + suffix;
      return;
    }

    var duration = 1600;
    var started = null;

    function step(now) {
      if (started === null) started = now;

      var progress = Math.min((now - started) / duration, 1);
      // Ease out, so it decelerates into the final number instead of stopping.
      var eased = 1 - Math.pow(1 - progress, 4);

      element.textContent = String(Math.round(target * eased)) + suffix;

      if (progress < 1) requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
  }

  /* ========================================================================
     Parallax
     ======================================================================== */

  /*
   * One loop for every parallaxed element, and it idles unless at least one of
   * them is on screen. The transform is translate3d so it stays on the
   * compositor -- a top/margin animation here would repaint the whole section
   * on every frame, which is exactly how these effects earn their reputation
   * for making cheap phones stutter.
   */
  function watchParallax() {
    var elements = Array.prototype.slice.call(document.querySelectorAll(".parallax"));

    if (reduced || !elements.length) return;

    var visible = [];
    var bases = new WeakMap();
    var ticking = false;

    /*
     * The element that actually moves.
     *
     * For a `.drift` frame it is the image inside, so the frame keeps its
     * place in the layout and clips the movement. For everything else it is
     * the element itself.
     */
    function targetOf(el) {
      return el.classList.contains("drift") ? el.querySelector("img") || el : el;
    }

    /*
     * Where the element sits in the document with no transform applied.
     *
     * Measured with the transform cleared, which is the whole point: reading
     * getBoundingClientRect() while our own translate is still on the element
     * returns a position that already includes it, and feeding that back in
     * makes the offset converge on a fixed point instead of tracking the
     * scroll. That is a self-damping loop, and it is why this looked like
     * almost nothing was happening.
     */
    function measure(el) {
      var target = targetOf(el);
      var previous = target.style.transform;

      target.style.transform = "";

      var box = el.getBoundingClientRect();
      var base = { top: box.top + window.scrollY, height: box.height };

      target.style.transform = previous;
      bases.set(el, base);

      return base;
    }

    function apply() {
      var viewportCentre = window.scrollY + window.innerHeight / 2;

      visible.forEach(function (el) {
        var base = bases.get(el) || measure(el);
        var speed = parseFloat(el.getAttribute("data-speed")) || 0.2;
        var elementCentre = base.top + base.height / 2;

        /*
         * Positive as the element rises past the middle of the screen, so the
         * translate is downward -- the image lags the page rather than racing
         * ahead of it. The sign here was inverted, which made the hero scroll
         * away faster than everything around it: technically movement, but
         * the opposite of what parallax is meant to read as.
         */
        var offset = (viewportCentre - elementCentre) * speed;

        targetOf(el).style.transform = "translate3d(0," + offset.toFixed(2) + "px,0)";
      });

      ticking = false;
    }

    function request() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(apply);
    }

    function remeasure() {
      elements.forEach(measure);
      request();
    }

    if ("IntersectionObserver" in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          var index = visible.indexOf(entry.target);

          if (entry.isIntersecting && index === -1) visible.push(entry.target);
          if (!entry.isIntersecting && index > -1) visible.splice(index, 1);
        });

        if (visible.length) request();
      });

      elements.forEach(function (el) {
        observer.observe(el);
      });
    } else {
      visible = elements;
    }

    remeasure();

    window.addEventListener("scroll", request, { passive: true });
    window.addEventListener("resize", remeasure, { passive: true });

    /*
     * A lazily-loaded photograph changes the height of everything below it, so
     * every base measured before it arrived is now wrong. Re-measure once the
     * page has settled rather than on every single load event.
     */
    window.addEventListener("load", remeasure);
  }

  /* ========================================================================
     Masthead
     ======================================================================== */

  /*
   * Transparent over the hero, solid once past it. Driven by a sentinel
   * element and an observer rather than a scroll handler, so it costs nothing
   * while scrolling.
   */
  function watchMasthead() {
    var masthead = document.querySelector("[data-masthead]");
    var sentinel = document.querySelector("[data-masthead-sentinel]");

    if (!masthead) return;

    if (!sentinel || !("IntersectionObserver" in window)) {
      masthead.classList.add("is-solid");
      return;
    }

    new IntersectionObserver(function (entries) {
      masthead.classList.toggle("is-solid", !entries[0].isIntersecting);
    }).observe(sentinel);
  }

  /* ========================================================================
     Mobile navigation
     ======================================================================== */

  function watchNav() {
    var toggle = document.querySelector("[data-nav-toggle]");
    var nav = document.querySelector("[data-nav]");

    if (!toggle || !nav) return;

    function setOpen(open) {
      nav.classList.toggle("is-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      // The panel covers the screen; letting the page scroll behind it means
      // closing the menu drops you somewhere you did not choose.
      document.body.style.overflow = open ? "hidden" : "";
    }

    toggle.addEventListener("click", function () {
      setOpen(!nav.classList.contains("is-open"));
    });

    // Escape closes it, as it does any other full-screen layer.
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && nav.classList.contains("is-open")) setOpen(false);
    });

    // Following a link should close the menu it was tapped in.
    nav.addEventListener("click", function (event) {
      if (event.target.closest("a")) setOpen(false);
    });
  }

  /* ========================================================================
     Carousel
     ======================================================================== */

  /*
   * Scroll-snap does the whole job. The buttons just scroll the track by one
   * card, which means the thing still works perfectly with JavaScript off, on
   * a trackpad, with a finger, or with a keyboard -- none of which is reliably
   * true of a carousel library.
   */
  function watchCarousels() {
    var carousels = document.querySelectorAll("[data-carousel]");

    Array.prototype.forEach.call(carousels, function (carousel) {
      var track = carousel.querySelector("[data-carousel-track]");
      var previous = carousel.querySelector("[data-carousel-prev]");
      var next = carousel.querySelector("[data-carousel-next]");

      if (!track) return;

      function step() {
        var card = track.firstElementChild;
        if (!card) return track.clientWidth;

        var gap = parseFloat(getComputedStyle(track).columnGap) || 0;

        return card.getBoundingClientRect().width + gap;
      }

      function update() {
        // A hair of tolerance: sub-pixel widths mean scrollLeft rarely lands
        // exactly on the maximum, which would leave the button wrongly enabled.
        var max = track.scrollWidth - track.clientWidth - 2;

        if (previous) previous.disabled = track.scrollLeft <= 2;
        if (next) next.disabled = track.scrollLeft >= max;
      }

      if (previous) {
        previous.addEventListener("click", function () {
          track.scrollBy({ left: -step(), behavior: reduced ? "auto" : "smooth" });
        });
      }

      if (next) {
        next.addEventListener("click", function () {
          track.scrollBy({ left: step(), behavior: reduced ? "auto" : "smooth" });
        });
      }

      /*
       * Drag to swipe.
       *
       * A finger already works: scroll-snap on a real scroll container is
       * native touch scrolling, and taking it over with JavaScript would only
       * make it worse. A mouse cannot drag a scroll container at all though,
       * so that case -- and pen -- is handled here and nowhere else.
       *
       * Snapping is switched off for the duration of the drag. Left on, it
       * fights every pointermove by yanking the track back to the nearest
       * card; restoring it on release is what makes the cards settle.
       */
      var down = false;
      var dragged = false;
      var startX = 0;
      var startScroll = 0;

      track.addEventListener("pointerdown", function (event) {
        if (event.pointerType === "touch") return;
        if (event.pointerType === "mouse" && event.button !== 0) return;

        down = true;
        dragged = false;
        startX = event.clientX;
        startScroll = track.scrollLeft;

        track.classList.add("is-dragging");
        track.style.scrollSnapType = "none";
      });

      track.addEventListener("pointermove", function (event) {
        if (!down) return;

        var distance = event.clientX - startX;

        // A few pixels of slop, so a slightly shaky click is still a click.
        if (!dragged && Math.abs(distance) < 4) return;

        if (!dragged) {
          dragged = true;
          try {
            track.setPointerCapture(event.pointerId);
          } catch (error) {
            // Capture is a convenience; the pointerup handler copes without it.
          }
        }

        event.preventDefault();
        track.scrollLeft = startScroll - distance;
      });

      function endDrag(event) {
        if (!down) return;

        down = false;
        track.classList.remove("is-dragging");
        track.style.scrollSnapType = "";

        if (event && event.pointerId !== undefined) {
          try {
            track.releasePointerCapture(event.pointerId);
          } catch (error) {
            // Nothing was captured, which is the common case for a plain click.
          }
        }

        update();
      }

      track.addEventListener("pointerup", endDrag);
      track.addEventListener("pointercancel", endDrag);

      /*
       * A drag that finishes on top of a link or button must not also count as
       * a click on it. Captured on the way down so it beats the element's own
       * handler, and only swallows the one click that ended the drag.
       */
      track.addEventListener(
        "click",
        function (event) {
          if (!dragged) return;

          event.preventDefault();
          event.stopPropagation();
          dragged = false;
        },
        true,
      );

      // Dragging a photograph would otherwise start a native image drag.
      track.addEventListener("dragstart", function (event) {
        event.preventDefault();
      });

      track.addEventListener("scroll", update, { passive: true });
      window.addEventListener("resize", update, { passive: true });
      update();
    });
  }

  /* ========================================================================
     Lightbox
     ======================================================================== */

  /*
   * A native <dialog>. It brings the backdrop, focus trapping, Escape to close
   * and inertness of the page behind it for nothing -- all the things a
   * lightbox plugin is actually bought for.
   */
  function watchLightbox() {
    var dialog = document.querySelector("[data-lightbox]");
    if (!dialog || typeof dialog.showModal !== "function") return;

    var image = dialog.querySelector("[data-lightbox-image]");
    var caption = dialog.querySelector("[data-lightbox-caption]");
    var closer = dialog.querySelector("[data-lightbox-close]");
    var previous = dialog.querySelector("[data-lightbox-prev]");
    var next = dialog.querySelector("[data-lightbox-next]");

    var items = Array.prototype.slice.call(document.querySelectorAll("[data-lightbox-open]"));
    var current = 0;

    function show(index) {
      current = (index + items.length) % items.length;

      var trigger = items[current];
      var full = trigger.getAttribute("data-full");
      var text = trigger.getAttribute("data-caption") || "";

      if (image) {
        image.classList.remove("is-loaded");
        image.src = full;
        image.alt = text;
      }

      if (caption) caption.textContent = text;
    }

    items.forEach(function (trigger, index) {
      trigger.addEventListener("click", function (event) {
        event.preventDefault();
        show(index);
        dialog.showModal();
      });
    });

    if (closer) closer.addEventListener("click", function () { dialog.close(); });
    if (previous) previous.addEventListener("click", function () { show(current - 1); });
    if (next) next.addEventListener("click", function () { show(current + 1); });

    // Clicking the backdrop closes it. The dialog element itself is the
    // backdrop, so a click that landed on it and not on its contents is one.
    dialog.addEventListener("click", function (event) {
      if (event.target === dialog) dialog.close();
    });

    dialog.addEventListener("keydown", function (event) {
      if (event.key === "ArrowRight") show(current + 1);
      if (event.key === "ArrowLeft") show(current - 1);
    });

    if (image) {
      image.addEventListener("load", function () {
        image.classList.add("is-loaded");
      });
    }
  }

  /* ========================================================================
     Theme
     ======================================================================== */

  function watchTheme() {
    var toggle = document.querySelector("[data-theme-toggle]");
    if (!toggle) return;

    toggle.addEventListener("click", function () {
      var next =
        document.documentElement.getAttribute("data-theme") === "dark" ? "light" : "dark";

      document.documentElement.setAttribute("data-theme", next);

      try {
        localStorage.setItem("rc-theme", next);
      } catch (error) {
        // Private browsing with storage blocked: it just will not persist.
      }
    });
  }

  /* ======================================================================== */

  function start() {
    splitText();
    watchImages();
    watchReveals();
    watchParallax();
    watchMasthead();
    watchNav();
    watchCarousels();
    watchLightbox();
    watchTheme();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
