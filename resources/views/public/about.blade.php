@extends('public.layout')

@section('title', "About us — Rachels Closet")
@section('description', "Rachels Closet Fashion House connects tailors with customers in Nigeria, and lets you watch every stage of your clothes being made.")

@section('content')

    {{--
        Written from what the platform actually does, not from a story about
        its founding: nothing here claims a year, a founder's anecdote or a
        number that is not counted live below. When Rachel's own words and
        photographs exist, this is the page they belong on first.
    --}}

    {{-- =====================================================================
         The masthead -- the Fashion House's, so the two read as one house.
         ===================================================================== --}}
    <section class="fh-masthead page-masthead">
        <div class="fh-masthead__glow" aria-hidden="true"></div>

        <div class="wrap fh-masthead__body">
            <p class="eyebrow on-dark" data-reveal="up">About us</p>
            <h1 class="fh-masthead__title" data-reveal="up">We make the wait <em>visible</em>.</h1>
            <p class="page-masthead__lead" data-reveal="up" style="--reveal-delay:120ms">
                Rachels Closet Fashion House connects tailors with the people they sew for,
                across Nigeria &mdash; and shows the customer every stage of her clothes as it
                is finished.
            </p>
        </div>
    </section>

    {{-- =====================================================================
         Why we exist
         ===================================================================== --}}
    <section class="section">
        <div class="wrap">
            <div class="editorial">
                <div class="editorial__media">
                    @include('public.partials.photo', [
                        'image' => config('gallery.craft'),
                        'ratio' => 'tall',
                        'class' => 'zoom',
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
                    <p class="eyebrow">Why we exist</p>
                    <h2>The problem was never the sewing. It was <span class="accent">not knowing</span>.</h2>
                    <hr class="rule">
                    <p class="lead">
                        You hand over cloth and money, and then you wait. Has she started? Is it
                        cut? Will it be ready for the wedding?
                    </p>
                    <p>
                        Too often the only way to find out is a bus across town. And a good tailor,
                        busy with real work, has no easy way to show it. Rachels Closet gives every
                        order a list of stages. She taps each one as she finishes it, and your
                        phone tells you at once &mdash; often with a photograph of the work.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         What we built -- three things, each answering a real problem
         ===================================================================== --}}
    <section class="section on-cream">
        <div class="wrap">
            <div class="section-head centred" data-reveal="up">
                <p class="eyebrow">What we built</p>
                <h2>Three things, done properly</h2>
            </div>

            <div class="pillars">
                <article class="pillar" data-reveal="up">
                    <span class="pillar__icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <h3>The tracker</h3>
                    <p>
                        Every order is a list of stages &mdash; cutting, sewing, fitting. The tailor
                        ticks them off, the customer is told each time. Nobody has to chase anybody.
                    </p>
                </article>

                <article class="pillar" data-reveal="up" style="--reveal-delay:100ms">
                    <span class="pillar__icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9 4.5 4h15L21 9M3 9v11h18V9M3 9h18M9 20v-6h6v6"/></svg>
                    </span>
                    <h3>The Fashion House</h3>
                    <p>
                        A directory of tailors, by state, with their work, their reviews and how
                        many garments are on their table right now. Every tailor gets a page and a
                        business card that leads to it.
                    </p>
                </article>

                <article class="pillar" data-reveal="up" style="--reveal-delay:200ms">
                    <span class="pillar__icon pillar__icon--gold" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10ZM9 12l2 2 4-4"/></svg>
                    </span>
                    <h3>Money held safely</h3>
                    <p>
                        When you choose, you pay Rachels Closet instead of the tailor. We hold it
                        until the garment is in your hands, then pass it on. It costs neither of you
                        anything extra.
                    </p>
                </article>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         What we believe -- each one is a rule the platform actually keeps
         ===================================================================== --}}
    <section class="section">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">What we believe</p>
                <h2>The rules we built it by</h2>
            </div>

            <ol class="beliefs">
                <li data-reveal="up">
                    <h3>Built for tapping and listening</h3>
                    <p>
                        Many good tailors read slowly. So every stage has a voice note that says what
                        it means, and a measurement is a photograph of the book she already writes in
                        &mdash; not a form.
                    </p>
                </li>
                <li data-reveal="up" style="--reveal-delay:60ms">
                    <h3>Just a phone number</h3>
                    <p>
                        You sign in with your phone number and six secret numbers. No email address
                        needed, and nothing here sends you a text you pay for.
                    </p>
                </li>
                <li data-reveal="up" style="--reveal-delay:120ms">
                    <h3>Your measurements are yours</h3>
                    <p>
                        A tailor sees them only while she is making something for you, or after you
                        allow it &mdash; tailor by tailor. You can take it back with one tap. Our own
                        staff cannot open them.
                    </p>
                </li>
                <li data-reveal="up" style="--reveal-delay:180ms">
                    <h3>No commission, ever</h3>
                    <p>
                        A tailor pays one flat subscription to be listed. We take nothing from her
                        orders, and when we hold the money, we pay the payment charge ourselves.
                    </p>
                </li>
                <li data-reveal="up" style="--reveal-delay:240ms">
                    <h3>Nothing held hostage</h3>
                    <p>
                        If a tailor's listing lapses, she leaves the directory &mdash; and that is all.
                        Her orders, her money and her customers' clothes are never held back over a bill.
                    </p>
                </li>
                <li data-reveal="up" style="--reveal-delay:300ms">
                    <h3>Reviews that mean something</h3>
                    <p>
                        Reviewing opens only on a real order. Glowing reviews need the work to have
                        been photographed as it was made. Complaints are never held back.
                    </p>
                </li>
            </ol>
        </div>
    </section>

    {{-- =====================================================================
         The house, counted live. Hidden until there is a house to count --
         a band of zeroes on launch day would argue against everything above.
         ===================================================================== --}}
    @if ($house['tailors'] > 0)
        <section class="section tight on-violet">
            <div class="wrap">
                <div class="stats">
                    <div class="stat" data-reveal="up">
                        <span class="counter" data-count="{{ $house['tailors'] }}">0</span>
                        <p>{{ Str::plural('Tailor', $house['tailors']) }} in the house</p>
                    </div>
                    <div class="stat" data-reveal="up" style="--reveal-delay:100ms">
                        <span class="counter" data-count="{{ $house['states'] }}">0</span>
                        <p>{{ Str::plural('State', $house['states']) }} with a tailor</p>
                    </div>
                    <div class="stat" data-reveal="up" style="--reveal-delay:200ms">
                        <span class="counter" data-count="{{ $house['in_the_making'] }}">0</span>
                        <p>{{ Str::plural('Garment', $house['in_the_making']) }} in the making now</p>
                    </div>
                    <div class="stat" data-reveal="up" style="--reveal-delay:300ms">
                        <span class="counter" data-count="{{ $house['finished'] }}">0</span>
                        <p>{{ Str::plural('Garment', $house['finished']) }} finished</p>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- =====================================================================
         For tailors
         ===================================================================== --}}
    <section class="section">
        <div class="wrap">
            <div class="editorial flip">
                <div class="editorial__media">
                    @include('public.partials.photo', [
                        'image' => config('gallery.shop'),
                        'ratio' => 'tall',
                        'class' => 'zoom',
                        'w' => 900,
                    ])

                    <div class="editorial__inset" data-reveal="scale" style="--reveal-delay:220ms">
                        @include('public.partials.photo', [
                            'image' => config('gallery.shop_inset'),
                            'ratio' => 'square',
                            'w' => 500,
                            'sizes' => '(max-width: 860px) 50vw, 25vw',
                        ])
                    </div>
                </div>

                <div data-reveal="left">
                    <p class="eyebrow">For tailors</p>
                    <h2>Good work deserves to be <span class="accent">seen</span>.</h2>
                    <hr class="rule">
                    <p class="lead">
                        Your customers stop ringing to ask, because they can see. New ones find you
                        by your state, your photographs and what people say about you.
                    </p>
                    <p>
                        You get your own page in the Fashion House, a business card with a code that
                        leads to it, and a record of every garment you have finished.
                    </p>
                    <p style="margin-top:var(--s-6)">
                        <a class="btn" href="{{ $appUrl }}/join?as=tailor"><span>Join the house</span></a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         The invitation
         ===================================================================== --}}
    <section class="section cta">
        <div class="wrap">
            <p class="eyebrow on-dark" data-reveal="up">Start today</p>
            <h2 class="display" data-reveal="up">Find your <em class="page-em">tailor</em>.</h2>
            <p class="lead" data-reveal="up" style="margin:var(--s-6) auto 0;max-width:46ch;--reveal-delay:200ms">
                See their work, read what customers say, and watch every stage of your clothes.
            </p>
            <p class="page-cta__actions" data-reveal="up" style="--reveal-delay:260ms">
                <a class="btn" href="{{ route('directory') }}"><span>Find a tailor</span></a>
                <a class="btn ghost on-dark" href="{{ route('contact') }}"><span>Talk to us</span></a>
            </p>
        </div>
    </section>

@endsection
