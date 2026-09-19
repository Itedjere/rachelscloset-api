@extends('public.layout')

@section('title', "Rachel's Closet — Watch your clothes being made")

@section('content')

    {{-- =====================================================================
         Hero
         ===================================================================== --}}
    <section class="hero">
        {{-- data-speed is gentle. A strong parallax on a hero is the effect
             most likely to judder on the phones this is actually read on. --}}
        <div class="hero__media parallax" data-speed="0.38">
            @include('public.partials.photo', [
                'image' => config('gallery.hero'),
                'ratio' => '',
                'w' => 1600,
                'eager' => true,
                'sizes' => '100vw',
            ])
        </div>

        <div class="hero__scrim"></div>

        <div class="hero__body">
            <div class="wrap">
                <p class="eyebrow on-dark" data-reveal="up">Tailoring you can watch</p>

                {{-- data-split masks each word so it rises into place, staggered.
                     GSAP's SplitText does this for 123KB; motion.js does it in
                     about thirty lines and keeps the heading readable to a
                     screen reader via aria-label. --}}
                <h1 class="display" data-split data-reveal>
                    Your cloth. Her hands. No more guessing.
                </h1>

                <p class="lead" data-reveal="up" style="--reveal-delay:420ms">
                    Find a tailor near you, hand over your fabric, and watch every stage
                    as she finishes it &mdash; cut, sewn, pressed, ready &mdash; without
                    walking back to the shop to ask.
                </p>

                <div class="hero__actions" data-reveal="up" style="--reveal-delay:560ms">
                    <a class="btn" href="#tailors"><span>Find a tailor</span></a>
                    <a class="btn ghost on-dark" href="#join"><span>I sew &mdash; join</span></a>
                </div>
            </div>
        </div>

        <span class="hero__cue" aria-hidden="true">Scroll</span>

        {{-- The masthead watches this to know when to go solid. Inside the
             hero and near its top, so the bar fills in before the hero's own
             text slides up underneath it. --}}
        <div class="masthead-sentinel" data-masthead-sentinel aria-hidden="true"></div>
    </section>

    {{-- =====================================================================
         Marquee
         ===================================================================== --}}
    <div class="band">
        <div class="marquee">
            {{-- Two identical tracks: the first scrolls out exactly as the
                 second arrives, so the loop has no seam. CSS only. --}}
            @for ($copy = 0; $copy < 2; $copy++)
                <div class="marquee__track" @if ($copy) aria-hidden="true" @endif>
                    @foreach (['Ankara', 'Aso-oke', 'Lace', 'Agbada', 'Iro and buba', 'Senator', 'Adire', 'Bridal'] as $craft)
                        <span>{{ $craft }}</span>
                        <span class="dot">&#10022;</span>
                    @endforeach
                </div>
            @endfor
        </div>
    </div>

    {{-- =====================================================================
         The problem
         ===================================================================== --}}
    <section class="section">
        <div class="wrap">
            <div class="editorial">
                <div class="editorial__media">
                    @include('public.partials.photo', [
                        'image' => config('gallery.craft'),
                        'ratio' => 'tall',
                        'class' => 'zoom parallax drift',
                        'speed' => 0.14,
                        'w' => 900,
                    ])

                    <div class="editorial__inset" data-reveal="scale" style="--reveal-delay:220ms">
                        @include('public.partials.photo', [
                            'image' => config('gallery.craft_inset'),
                            'ratio' => 'square',
                            'w' => 500,
                            'sizes' => '(max-width: 860px) 50vw, 25vw',
                        ])
                    </div>
                </div>

                <div data-reveal="right">
                    <p class="eyebrow">The problem</p>
                    <h2>You hand over cloth and money, then <span class="accent">hear nothing</span>.</h2>
                    <hr class="rule">
                    <p class="lead">
                        She promised Friday. It is Friday. You take a bus across town and
                        find the fabric exactly where you left it, still folded.
                    </p>
                    <p>
                        The problem was never payment. It was not knowing. Rachel&rsquo;s Closet
                        gives every order a checklist your tailor ticks off as she works, and
                        your phone tells you the moment she does.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         How it works — the tracker is the product, so it is shown, not described
         ===================================================================== --}}
    <section class="section on-violet" id="how">
        <div class="wrap">
            <div class="section-head centred" data-reveal="up">
                <p class="eyebrow on-dark">How it works</p>
                <h2 data-split>Three steps, and then you can stop worrying.</h2>
            </div>

            <div class="grid-2" style="margin-bottom:var(--s-16)">
                <div data-reveal="left">
                    <div class="tracker">
                        <div class="tracker__row done">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Fabric received</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                        <div class="tracker__row done">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Measurements taken</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                        <div class="tracker__row done">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Cutting</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                        <div class="tracker__row">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Sewing</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                        <div class="tracker__row">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Finishing and pressing</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                        <div class="tracker__row">
                            <span class="tracker__tick" aria-hidden="true">&#10003;</span>
                            <span class="tracker__label">Ready to collect</span>
                            <button type="button" class="tracker__play" aria-label="Play the voice note for this step">&#9654;</button>
                        </div>
                    </div>
                </div>

                <div data-reveal="right">
                    <h3 style="font-family:var(--font-display);font-size:var(--fs-h2);font-weight:500">
                        Every stage carries a voice note.
                    </h3>
                    <p class="lead">
                        Many tailors read poorly. So each step in the library is recorded
                        aloud &mdash; she taps a step, hears what it means, and ticks it off.
                        Nothing on this platform asks anybody to read a paragraph to do
                        their job.
                    </p>
                </div>
            </div>

            <div class="steps">
                <div class="step" data-reveal="up">
                    <h3>Find her, or bring her</h3>
                    <p>Browse tailors near you by state, or bring the one you already trust.</p>
                </div>
                <div class="step" data-reveal="up" style="--reveal-delay:140ms">
                    <h3>Agree and hand over</h3>
                    <p>Measurements are photographed, not typed. Pay her directly, or let us hold it until you collect.</p>
                </div>
                <div class="step" data-reveal="up" style="--reveal-delay:280ms">
                    <h3>Watch it happen</h3>
                    <p>Your phone buzzes at every stage. When it says ready, it is ready.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Lookbook
         ===================================================================== --}}
    <section class="section" id="lookbook">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">The lookbook</p>
                <h2>Made by hand, <span class="accent">here</span>.</h2>
                <p class="lead">Every piece on this platform was cut and sewn by somebody you can message.</p>
            </div>

            <div class="gallery">
                @foreach (config('gallery.lookbook') as $index => $image)
                    @php
                        $id = $image['id'];
                        $full = "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w=1400";
                    @endphp

                    {{-- A button, not a div: the lightbox has to be reachable by
                         keyboard, and a native <dialog> handles the rest. --}}
                    <button
                        type="button"
                        data-lightbox-open
                        data-full="{{ $full }}"
                        data-caption="{{ $image['alt'] }}"
                        data-reveal="{{ $index === 0 ? 'curtain' : 'scale' }}"
                        style="--reveal-delay:{{ $index * 70 }}ms; border:0; padding:0; background:none"
                        aria-label="Open larger: {{ $image['alt'] }}"
                    >
                        @include('public.partials.photo', [
                            'image' => $image,
                            'ratio' => $loop->first || $index === 5 ? 'tall' : 'square',
                            'class' => 'zoom',
                            'w' => 700,
                            'sizes' => '(max-width: 900px) 50vw, 25vw',
                        ])
                        <figcaption>{{ $image['alt'] }}</figcaption>
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Tailors
         ===================================================================== --}}
    <section class="section on-cream" id="tailors">
        <div class="wrap">
            <div class="carousel" data-carousel data-reveal="up">
                <div class="carousel__head">
                    <div class="section-head" style="margin-bottom:0">
                        <p class="eyebrow">The house</p>
                        <h2>Tailors taking work now.</h2>
                    </div>

                    <div class="carousel__nav">
                        <button type="button" data-carousel-prev aria-label="Previous tailors">&#8592;</button>
                        <button type="button" data-carousel-next aria-label="More tailors">&#8594;</button>
                    </div>
                </div>

                <div class="carousel__track" data-carousel-track tabindex="0" aria-label="Tailors">
                    @foreach (config('gallery.tailors') as $index => $tailor)
                        <article class="tailor" style="--card-index:{{ $index }}">
                            @include('public.partials.photo', [
                                'image' => $tailor,
                                'ratio' => 'portrait',
                                'class' => 'zoom',
                                'w' => 600,
                                'sizes' => '(max-width: 720px) 80vw, 30vw',
                            ])

                            <div>
                                <div class="tailor__meta">
                                    <h3>{{ $tailor['name'] }}</h3>
                                    <span class="stars" aria-label="{{ $tailor['rating'] }} out of 5">
                                        {!! str_repeat('&#9733;', $tailor['rating']) !!}
                                    </span>
                                </div>
                                <p class="where">{{ $tailor['where'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Stats
         ===================================================================== --}}
    <section class="section tight on-violet">
        <div class="wrap">
            <div class="stats">
                <div class="stat" data-reveal="up">
                    <span class="counter" data-count="6" data-count-suffix="">0</span>
                    <p>Stages tracked per garment</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:100ms">
                    <span class="counter" data-count="36" data-count-suffix="">0</span>
                    <p>States covered</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:200ms">
                    <span class="counter" data-count="0" data-count-suffix="&#8358;">0</span>
                    <p>Cost to a customer</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:300ms">
                    <span class="counter" data-count="100" data-count-suffix="%">0</span>
                    <p>Of every stage, voice recorded</p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         The tailor's side
         ===================================================================== --}}
    <section class="section" id="join">
        <div class="wrap">
            <div class="editorial flip">
                <div class="editorial__media">
                    @include('public.partials.photo', [
                        'image' => config('gallery.shop'),
                        'ratio' => 'tall',
                        'class' => 'zoom parallax drift',
                        'speed' => 0.14,
                        'w' => 900,
                    ])

                    <div class="editorial__inset" data-reveal="scale" style="right:auto;left:-8%;--reveal-delay:220ms">
                        @include('public.partials.photo', [
                            'image' => config('gallery.shop_inset'),
                            'ratio' => 'square',
                            'w' => 500,
                            'sizes' => '(max-width: 860px) 50vw, 25vw',
                        ])
                    </div>
                </div>

                <div data-reveal="left">
                    <p class="eyebrow">If you sew</p>
                    <h2>A shopfront that fits <span class="accent">in a purse</span>.</h2>
                    <hr class="rule">
                    <p class="lead">
                        A profile customers can find, a portfolio of your own work, and a
                        printed card with your code on it. Scan it and they are looking at
                        your page.
                    </p>
                    <p>
                        Tick off a stage and the customer is told &mdash; so nobody arrives
                        early, nobody rings to ask, and the work you have already done is
                        visible while you are doing it.
                    </p>
                    <p style="margin-top:var(--s-8)">
                        <a class="btn" href="/#join"><span>Join the house</span></a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Testimonials
         ===================================================================== --}}
    <section class="section on-cream">
        <div class="wrap">
            <div class="carousel" data-carousel data-reveal="up">
                <div class="carousel__head">
                    <div class="section-head" style="margin-bottom:0">
                        <p class="eyebrow">In their words</p>
                        <h2>Both sides of the counter.</h2>
                    </div>

                    <div class="carousel__nav">
                        <button type="button" data-carousel-prev aria-label="Previous testimonials">&#8592;</button>
                        <button type="button" data-carousel-next aria-label="More testimonials">&#8594;</button>
                    </div>
                </div>

                {{-- Swipe with a finger, drag with a mouse, or use the arrows.
                     All three drive the same scroll container. --}}
                <div class="carousel__track" data-carousel-track tabindex="0" aria-label="What people say">
                    @foreach (config('gallery.testimonials') as $index => $said)
                        <figure class="testimonial" style="--card-index:{{ $index }}">
                            <span class="stars" aria-label="{{ $said['rating'] }} out of 5">
                                {!! str_repeat('&#9733;', $said['rating']) !!}
                            </span>

                            <blockquote>{!! $said['quote'] !!}</blockquote>

                            <figcaption>
                                <span class="who">{{ $said['name'] }}</span>
                                <span class="tag">{{ $said['role'] }}</span>
                                <div class="what">{{ $said['where'] }}</div>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Call to action
         ===================================================================== --}}
    <section class="section cta">
        <div class="cta__media parallax" data-speed="0.24">
            @include('public.partials.photo', [
                'image' => config('gallery.cta'),
                'ratio' => '',
                'w' => 1600,
                'sizes' => '100vw',
            ])
        </div>

        <div class="wrap">
            <p class="eyebrow on-dark" data-reveal="up">Start today</p>
            <h2 class="display" data-split data-reveal>Know where your clothes are.</h2>
            <p class="lead" data-reveal="up" style="margin:var(--s-6) auto 0;max-width:46ch;--reveal-delay:300ms">
                Free for customers. Always.
            </p>
            <p style="margin-top:var(--s-8)" data-reveal="up">
                <a class="btn" href="#tailors"><span>Find a tailor</span></a>
            </p>
        </div>
    </section>

    {{-- =====================================================================
         Credits
         ===================================================================== --}}
    <section class="section tight">
        <div class="wrap">
            <p class="credits">
                Photography is placeholder stock from
                <a href="https://www.pexels.com/" rel="noopener">Pexels</a>,
                chosen to show Nigerian tailoring and Nigerian dress rather than generic
                catalogue fashion. Replacing these with Rachel&rsquo;s own work is a
                one-line edit per image in <code>config/gallery.php</code>, and it is the
                single biggest improvement available to this page.
                The tailors and the quotes shown are illustrative; the real directory and
                real reviews arrive with their own sections.
            </p>
        </div>
    </section>

    {{-- =====================================================================
         Lightbox — one native <dialog> for the whole page
         ===================================================================== --}}
    <dialog class="lightbox" data-lightbox aria-label="Larger photograph">
        <img data-lightbox-image src="" alt="">
        <div class="lightbox__bar">
            <button type="button" data-lightbox-prev aria-label="Previous">&#8592;</button>
            <button type="button" data-lightbox-next aria-label="Next">&#8594;</button>
            <p data-lightbox-caption></p>
            <button type="button" data-lightbox-close aria-label="Close">&#10005;</button>
        </div>
    </dialog>

@endsection
