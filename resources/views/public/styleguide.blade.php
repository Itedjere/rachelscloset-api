@extends('public.layout')

@section('title', "Design language — Rachel's Closet")

@section('content')

    <section class="section" style="padding-top:calc(78px + var(--s-16))">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">The design language</p>
                <h1 class="display" data-split>Lilac, champagne, and a slab.</h1>
                <p class="lead" style="margin-top:var(--s-6)">
                    One token set, one stylesheet, one small motion file, no dependencies.
                    Everything the public site and the app are built from is on this page.
                </p>
            </div>
        </div>
    </section>

    {{-- Brand --}}
    <section class="section tight">
        <div class="wrap">
            <p class="eyebrow">The mark</p>
            <h2 style="margin-bottom:var(--s-4)">An R and a C, locked together</h2>
            <p class="lead" style="margin-bottom:var(--s-8)">
                An interlocking monogram in Zilla Slab, the same face as the wordmark.
                The C passes <em>behind</em> the R&rsquo;s bowl at the top and
                <em>in front</em> of its leg at the bottom &mdash; that change of order at
                the second crossing is the whole trick. Two letters that merely overlap
                read as one sitting on the other; only the alternation makes them look
                woven. A monogram rather than a needle or a hanger, because it cannot be
                mistaken for another business the way a generic tick or spool can.
            </p>

            <div class="specimen-row">
                @foreach ([96, 64, 40, 24, 16] as $size)
                    <figure>
                        <img src="{{ asset('brand/mark.svg') }}" width="{{ $size }}" height="{{ $size }}" alt="Rachel&rsquo;s Closet mark at {{ $size }} pixels">
                        <figcaption>{{ $size }}</figcaption>
                    </figure>
                @endforeach

                <figure style="background:var(--violet-ink);padding:var(--s-4);border-radius:var(--radius)">
                    <img src="{{ asset('brand/mark.svg') }}" width="64" height="64" alt="">
                    <figcaption style="color:rgba(252,250,255,.6)">on violet</figcaption>
                </figure>

                {{-- Inlined, not <img>. An SVG loaded through <img> is an
                     isolated document and cannot see the page's currentColor,
                     so the one-colour mark only takes its surroundings' colour
                     when it is inlined or placed by a design tool. --}}
                <figure style="color:var(--lilac);width:64px">
                    {!! str_replace('<svg ', '<svg width="64" height="64" ', file_get_contents(public_path('brand/mark-mono.svg'))) !!}
                    <figcaption>one colour</figcaption>
                </figure>

                <figure style="background:#fcfaff;padding:var(--s-3);border-radius:var(--radius)">
                    <img src="{{ asset('brand/logo.png') }}" width="220" alt="Rachel&rsquo;s Closet logo">
                    <figcaption style="color:#877b93">logo.png</figcaption>
                </figure>

                <figure style="background:#251540;padding:var(--s-3);border-radius:var(--radius)">
                    <img src="{{ asset('brand/logo-on-dark.png') }}" width="220" alt="Rachel&rsquo;s Closet logo">
                    <figcaption style="color:rgba(252,250,255,.6)">logo-on-dark.png</figcaption>
                </figure>
            </div>

            <p class="credits" style="margin-top:var(--s-8);max-width:64ch">
                <code>mark.svg</code> carries both letters as outlined paths, not live text,
                so it renders identically with no webfont and no network &mdash; as a
                favicon, in an email, or at the centre of a printed QR code on a business
                card. <code>mark-mono.svg</code> inherits <code>currentColor</code> for
                anything that can only hold one colour: a stamp, a single-plate print run,
                an embroidered label. The raster assets &mdash; <code>.ico</code>, the
                home-screen icons and the social card &mdash; are rendered from the same
                geometry by <code>php artisan brand:build</code>, so they cannot drift.
            </p>
        </div>
    </section>

    {{-- Colour --}}
    <section class="section tight on-cream">
        <div class="wrap">
            <p class="eyebrow">Colour</p>
            <h2 style="margin-bottom:var(--s-8)">The palette</h2>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:var(--s-4)">
                @foreach ([
                    'violet-ink' => 'Deepest ground',
                    'violet-deep' => 'Dark sections',
                    'lilac' => 'The house colour',
                    'lilac-bright' => 'Decoration only',
                    'lilac-tint' => 'Quiet lilac ground',
                    'champagne' => 'Eyebrows, rules, accents',
                    'champagne-soft' => 'Champagne on dark',
                    'champagne-tint' => 'Quiet champagne ground',
                    'ink' => 'Body text',
                    'ink-soft' => 'Secondary text',
                    'ink-mute' => 'Captions',
                    'bone' => 'Page ground',
                    'cream' => 'Alternate section',
                ] as $token => $use)
                    <div data-reveal="up">
                        <div style="height:74px;border-radius:var(--radius);border:1px solid var(--line);background:var(--{{ $token }})"></div>
                        <p style="margin:var(--s-2) 0 0;font-size:.75rem;font-weight:600">--{{ $token }}</p>
                        <p style="margin:0;font-size:.75rem;color:var(--ink-mute)">{{ $use }}</p>
                    </div>
                @endforeach
            </div>

            <p class="credits" style="margin-top:var(--s-8);max-width:60ch">
                Champagne is never used for body text &mdash; at 3.1:1 it does not carry
                enough contrast. It appears on eyebrows, rules, counters and hover states,
                which is what keeps it reading as a metal rather than as a colour.
                <code>--lilac</code> is deliberately not true pale lilac: it is taken down
                to 5.2:1 against white so it can carry white text and serve as a link
                colour. The pale one is <code>--lilac-bright</code>, decoration only.
            </p>
        </div>
    </section>

    {{-- Type --}}
    <section class="section tight">
        <div class="wrap">
            <p class="eyebrow">Type</p>
            <h2 style="margin-bottom:var(--s-8)">Zilla Slab and Monda</h2>

            <div style="display:grid;gap:var(--s-8)">
                <div data-reveal="up">
                    <p class="credits">display &mdash; clamp(2.75rem, 9.5vw, 7rem)</p>
                    <p class="display" style="margin:0">Aso-oke</p>
                </div>
                <div data-reveal="up">
                    <p class="credits">h1 &mdash; clamp(2.125rem, 5.5vw, 3.875rem)</p>
                    <h1 style="margin:0">A shopfront in a purse</h1>
                </div>
                <div data-reveal="up">
                    <p class="credits">h2 &mdash; with an <em>accent</em> span</p>
                    <h2 style="margin:0">Made by hand, <span class="accent">here</span>.</h2>
                </div>
                <div data-reveal="up">
                    <p class="credits">lead / body / eyebrow</p>
                    <p class="eyebrow">The lookbook</p>
                    <p class="lead" style="margin:0 0 var(--s-3)">
                        Lead paragraphs sit at a comfortable reading size and a muted ink.
                    </p>
                    <p style="margin:0">
                        Body text is Monda at 16px, not 15 &mdash; read on a cheap phone in a
                        busy shop, often by somebody who finds reading hard, and the extra
                        pixel costs nothing.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Motion --}}
    <section class="section tight on-cream">
        <div class="wrap">
            <p class="eyebrow">Motion</p>
            <h2 style="margin-bottom:var(--s-4)">Six primitives, no libraries</h2>
            <p class="lead" style="margin-bottom:var(--s-8)">
                Scroll this section to see each fire. All of them stop dead under
                <code>prefers-reduced-motion</code>, and everything stays visible.
            </p>

            <div class="grid-3">
                @foreach ([
                    ['up', 'reveal="up"', 'Rises and fades. The default.'],
                    ['left', 'reveal="left"', 'Enters from the left.'],
                    ['right', 'reveal="right"', 'Enters from the right.'],
                    ['scale', 'reveal="scale"', 'Grows slightly into place.'],
                    ['curtain', 'reveal="curtain"', 'A violet panel wipes away.'],
                ] as $index => $item)
                    <div data-reveal="{{ $item[0] }}" style="--reveal-delay:{{ $index * 120 }}ms;position:relative;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:var(--s-6);overflow:hidden">
                        <p style="margin:0 0 var(--s-2);font-weight:600;font-size:.8125rem">data-{{ $item[1] }}</p>
                        <p style="margin:0;font-size:.8125rem;color:var(--ink-mute)">{{ $item[2] }}</p>
                    </div>
                @endforeach

                <div style="background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:var(--s-6)">
                    <p style="margin:0 0 var(--s-2);font-weight:600;font-size:.8125rem">data-count</p>
                    <span class="counter" data-count="1600" style="color:var(--lilac)">0</span>
                    <p style="margin:var(--s-2) 0 0;font-size:.8125rem;color:var(--ink-mute)">Counts up once, on entry.</p>
                </div>
            </div>

            <div style="margin-top:var(--s-12)" data-reveal="up">
                <p class="credits">data-split &mdash; each word masked, rising, staggered</p>
                <h2 data-split style="margin:0">Every stage carries a voice note.</h2>
            </div>
        </div>
    </section>

    {{-- Controls --}}
    <section class="section tight">
        <div class="wrap">
            <p class="eyebrow">Controls</p>
            <h2 style="margin-bottom:var(--s-8)">Buttons and links</h2>

            <div style="display:flex;flex-wrap:wrap;gap:var(--s-4);align-items:center">
                <a class="btn" href="#"><span>Primary</span></a>
                <a class="btn ghost" href="#"><span>Ghost</span></a>
                <a class="link" href="#">A text link</a>
            </div>

            <p class="credits" style="margin-top:var(--s-6);max-width:60ch">
                Everything tappable is at least 48px tall. Hover is a gold wipe from the
                bottom rather than a colour swap &mdash; one pseudo-element, and it reads
                as considered rather than as a browser default.
            </p>
        </div>
    </section>

    {{-- Weight --}}
    <section class="section tight on-violet">
        <div class="wrap">
            <p class="eyebrow on-dark">What it costs</p>
            <h2 style="margin-bottom:var(--s-8)">Measured against the reference</h2>

            <div class="stats" style="text-align:left">
                <div class="stat" data-reveal="up">
                    <span class="counter" data-count="1120" data-count-suffix="KB">0</span>
                    <p style="margin-inline:0">The reference template&rsquo;s libraries</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:120ms">
                    <span class="counter" data-count="0" data-count-suffix="KB">0</span>
                    <p style="margin-inline:0">Ours &mdash; there are none</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:240ms">
                    <span class="counter" data-count="27" data-count-suffix="">0</span>
                    <p style="margin-inline:0">Asset files it loads</p>
                </div>
                <div class="stat" data-reveal="up" style="--reveal-delay:360ms">
                    <span class="counter" data-count="3" data-count-suffix="">0</span>
                    <p style="margin-inline:0">Ours &mdash; two CSS, one JS</p>
                </div>
            </div>

            <p class="credits" style="margin-top:var(--s-8);max-width:64ch;color:rgba(251,249,247,.6)">
                The reference loads jQuery, Bootstrap, Swiper, GSAP with ScrollTrigger and
                SplitText, WOW, Isotope, Parallaxie, magnific-popup, SmoothScroll and more
                &mdash; 1.12MB before a single photograph. Every effect on these pages is
                native CSS or about two hundred lines of vanilla JavaScript, because the
                people this platform is for pay for bandwidth by the megabyte.
            </p>
        </div>
    </section>

@endsection
