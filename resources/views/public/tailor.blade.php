@extends('public.layout')

@section('title', $profile->business_name . " — Rachels Closet")
@section('description', Str::limit($profile->bio ?: "See work by {$profile->business_name}"
    . ($profile->state ? " in {$profile->state}" : '')
    . ', read reviews, and follow every stage of your garment.', 155))

@php
    $where = collect([$profile->location, $profile->state])->filter()->join(', ');

    // The name set like a fashion house's: the last word in italic champagne,
    // the way the landing page and the Fashion House set theirs. One word
    // stays plain -- italicising a whole one-word name just looks shouted.
    $words = preg_split('/\s+/', trim($profile->business_name));
    $nameTail = count($words) > 1 ? array_pop($words) : null;
    $nameHead = implode(' ', $words);

    $initial = Str::upper(Str::substr($profile->business_name, 0, 1));

    // The tailor learns where the conversation came from, which is the only
    // thing that tells her the listing is worth paying for.
    $whatsapp = $profile->whatsapp_phone
        ? \App\Support\WhatsApp::to(
            $profile->whatsapp_phone,
            "Hello {$profile->business_name}, I found you on Rachels Closet. I would like something made."
        )
        : null;

    $rating = round((float) $profile->avg_rating, 1);

    // A customer is named on a public page by first name and initial. She
    // wrote a review for the tailor, not to be findable by her full name.
    $shortName = function (?string $name): string {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return 'A customer';
        }
        $first = $parts[0];

        return count($parts) > 1 ? $first.' '.Str::upper(Str::substr(end($parts), 0, 1)).'.' : $first;
    };

    // The review that leads: the warmest one with something to say.
    $featured = $reviews->filter(fn ($r) => filled($r->body))
        ->sortByDesc(fn ($r) => [$r->rating, mb_strlen($r->body)])
        ->first();
    $others = $featured ? $reviews->reject(fn ($r) => $r->is($featured)) : $reviews;

    $collage = $gallery->take(3);
@endphp

@section('content')

{{--
    Structured data, because the whole purpose of this page is being found.
    A tailor is a LocalBusiness with an aggregate rating; that is what puts
    the stars in a search result, which is what makes the directory worth
    more than a list of names.
--}}
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => $profile->business_name,
    'description' => $profile->bio,
    'url' => route('tailor', $profile->slug),
    'image' => $gallery->isNotEmpty() ? route('portfolio.photo', basename($gallery->first()->path)) : null,
    'address' => array_filter([
        '@type' => 'PostalAddress',
        'addressLocality' => $profile->location,
        'addressRegion' => $profile->state,
        'addressCountry' => 'NG',
    ]),
    'aggregateRating' => $reviewTotal > 0 ? [
        '@type' => 'AggregateRating',
        'ratingValue' => (string) $rating,
        'reviewCount' => (string) $reviewTotal,
    ] : null,
]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
</script>

<div class="tp">

{{-- =========================================================================
     The house front

     Who she is, set like a fashion house's name, and the one fact the
     platform exists to make visible: whether there is work on her table
     right now. Her photographs beside it, so the first screen is her work
     and not a paragraph about it.
     ========================================================================= --}}
<section class="tp-hero">
    <div class="fh-masthead__glow" aria-hidden="true"></div>

    <div class="wrap tp-hero__grid">
        <div class="tp-hero__text">
            <nav class="tp-crumbs" aria-label="Where you are" data-reveal="up">
                <a href="{{ route('directory') }}">The Fashion House</a>
                @if ($profile->state)
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('directory', ['state' => $profile->state]) }}">{{ $profile->state }}</a>
                @endif
            </nav>

            <h1 class="tp-hero__name" data-reveal="up" style="--reveal-delay:80ms">
                {{ $nameHead }}@if ($nameTail) <em>{{ $nameTail }}</em>@endif
            </h1>

            @if ($where)
                <p class="tp-hero__where" data-reveal="up" style="--reveal-delay:140ms">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6.5-8 12-8 12s-8-5.5-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    {{ $where }}
                </p>
            @endif

            <div class="tp-hero__signals" data-reveal="up" style="--reveal-delay:200ms">
                {{-- The same dot as her card in the directory, said in words. --}}
                <span @class(['tp-status', 'is-live' => $live > 0])>
                    <span @class(['live-dot', 'is-live' => $live > 0]) aria-hidden="true"></span>
                    @if ($live > 0)
                        {{ $live }} {{ Str::plural('garment', $live) }} in the making right now
                    @else
                        Taking new work
                    @endif
                </span>

                @if ($reviewTotal > 0)
                    <a class="tp-hero__rating" href="#reviews">
                        <span class="tp-stars" aria-hidden="true">
                            @for ($i = 1; $i <= 5; $i++)
                                <span @class(['on' => $i <= round($rating)])>&#9733;</span>
                            @endfor
                        </span>
                        <strong>{{ number_format($rating, 1) }}</strong>
                        <span>{{ $reviewTotal }} {{ Str::plural('review', $reviewTotal) }}</span>
                    </a>
                @endif
            </div>

            <div class="tp-hero__actions" data-reveal="up" style="--reveal-delay:260ms">
                {{--
                    Her own WhatsApp, which is where this conversation actually
                    happens. Not a contact form: a stranger who found her
                    through a search wants to ask a question now, on the app
                    already open on her phone.
                --}}
                @if ($whatsapp)
                    <a class="btn tp-wa" rel="noopener" href="{{ $whatsapp }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                        <span>Message on WhatsApp</span>
                    </a>
                @endif

                @if ($gallery->isNotEmpty())
                    <a class="btn ghost on-dark" href="#work"><span>See her work</span></a>
                @endif

                {{-- Passing her on is how a tailor here gets known: a phone's
                     own share sheet, or the link copied where there is none. --}}
                <button type="button" class="tp-share" data-share
                        data-share-title="{{ $profile->business_name }}"
                        data-share-url="{{ route('tailor', $profile->slug) }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>
                    <span data-share-label>Share</span>
                </button>
            </div>
        </div>

        {{-- Her work, three pieces at most, arranged like a magazine spread.
             With nothing uploaded yet, her initial on a seal -- never a stock
             photograph that is not hers. --}}
        <div @class(['tp-collage', 'tp-collage--'.$collage->count()]) data-reveal="scale" style="--reveal-delay:120ms">
            @forelse ($collage as $index => $item)
                <a class="tp-collage__item" href="#work" tabindex="-1" aria-hidden="true">
                    <img src="{{ route('portfolio.photo', basename($item->path)) }}"
                         alt="" loading="eager" decoding="async">
                </a>
            @empty
                <div class="tp-seal" aria-hidden="true">
                    <span class="tp-seal__ring"></span>
                    <span class="tp-seal__initial">{{ $initial }}</span>
                    <span class="tp-seal__label">Rachels Closet &middot; Fashion House</span>
                </div>
            @endforelse
        </div>
    </div>
</section>

{{-- =========================================================================
     The facts, in one line. Only the ones that are true and worth saying --
     a newcomer is not shown a row of zeroes.
     ========================================================================= --}}
<section class="tp-facts" aria-label="At a glance">
    <div class="wrap">
        <dl class="tp-facts__row">
            @if ($reviewTotal > 0)
                <div data-reveal="up">
                    <dt>Rated</dt>
                    <dd>{{ number_format($rating, 1) }}<small>&#9733;</small></dd>
                </div>
            @endif
            @if ($profile->orders_completed > 0)
                <div data-reveal="up" style="--reveal-delay:60ms">
                    <dt>Garments finished</dt>
                    <dd>{{ number_format($profile->orders_completed) }}</dd>
                </div>
            @endif
            @if ($gallery->isNotEmpty())
                <div data-reveal="up" style="--reveal-delay:120ms">
                    <dt>In the lookbook</dt>
                    <dd>{{ $gallery->count() }}</dd>
                </div>
            @endif
            <div data-reveal="up" style="--reveal-delay:180ms">
                <dt>In the house since</dt>
                <dd class="tp-facts__date">{{ $tailor->created_at->format('M Y') }}</dd>
            </div>
        </dl>
    </div>
</section>

{{-- =========================================================================
     The house, and how working with her goes

     The bio is hers. The three steps are the platform's promise, said about
     HER -- this is the page where a stranger decides to trust somebody they
     have never met, and "you will see every stage" is the reason to.
     ========================================================================= --}}
<section class="section tp-about">
    <div class="wrap tp-about__grid">
        <div class="tp-about__story" data-reveal="up">
            <p class="eyebrow">The house</p>
            @if ($profile->bio)
                <p class="tp-about__bio">{{ $profile->bio }}</p>
            @else
                <p class="tp-about__bio">{{ $profile->business_name }} makes clothes{{ $where ? " in {$where}" : '' }}, and shows you every stage of yours as it happens.</p>
            @endif
        </div>

        <ol class="tp-steps">
            <li data-reveal="up">
                <span class="tp-steps__icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                </span>
                <div>
                    <h3>Tell her what you want</h3>
                    <p>{{ $whatsapp ? 'Message her, or visit the shop' : 'Visit the shop' }}{{ $profile->location ? " in {$profile->location}" : '' }}. She takes your measurements and opens your order.</p>
                </div>
            </li>
            <li data-reveal="up" style="--reveal-delay:80ms">
                <span class="tp-steps__icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </span>
                <div>
                    <h3>Watch every stage</h3>
                    <p>Cutting, sewing, fitting. Each time she finishes a stage, your phone tells you — often with a photograph.</p>
                </div>
            </li>
            <li data-reveal="up" style="--reveal-delay:160ms">
                <span class="tp-steps__icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 5.5a2 2 0 1 1 3 1.7c-.6.4-1 .9-1 1.6V9l8.6 5.7a1 1 0 0 1-.6 1.8H4a1 1 0 0 1-.6-1.8L12 9"/></svg>
                </span>
                <div>
                    <h3>Collect it, ready</h3>
                    <p>You know the day it is finished. Pay through Rachels Closet and we can hold the money until it is in your hands.</p>
                </div>
            </li>
        </ol>
    </div>
</section>

{{-- =========================================================================
     The lookbook

     Columns rather than a grid, so every photograph keeps its own shape and
     nothing leaves a hole -- a portrait gown and a landscape workshop shot
     sit together without either being cropped to fit the other.

     Most of these were photographed by customers wearing the finished
     garment, which is what makes the section worth looking at.
     ========================================================================= --}}
@if ($gallery->isNotEmpty())
    <section class="section tp-lookbook" id="work">
        <div class="wrap">
            <div class="tp-head" data-reveal="up">
                <div>
                    <p class="eyebrow">The lookbook</p>
                    <h2>Her work</h2>
                </div>
                <p class="tp-head__aside">{{ $gallery->count() }} {{ Str::plural('piece', $gallery->count()) }} &middot; tap one to see it larger</p>
            </div>

            {{--
                The same native <dialog> as the landing page: any
                [data-lightbox-open] button carrying data-full joins the set
                motion.js already drives -- keyboard, arrows and Escape for free.
            --}}
            <div @class(['tp-masonry', 'tp-masonry--few' => $gallery->count() < 3])>
                @foreach ($gallery as $index => $item)
                    @php($src = route('portfolio.photo', basename($item->path)))
                    <button
                        type="button"
                        class="tp-piece"
                        data-lightbox-open
                        data-full="{{ $src }}"
                        data-caption="{{ $item->caption ?: 'Work by '.$profile->business_name }}"
                        data-reveal="up"
                        style="--reveal-delay:{{ ($index % 3) * 70 }}ms"
                        aria-label="Open larger: {{ $item->caption ?: 'photograph of her work' }}"
                    >
                        <img src="{{ $src }}"
                             alt="{{ $item->caption ?: 'Work by '.$profile->business_name }}"
                             loading="lazy" decoding="async">
                        @if ($item->caption)
                            <span class="tp-piece__caption">{{ $item->caption }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            @include('public.partials.lightbox')
        </div>
    </section>
@endif

{{-- =========================================================================
     In their words

     The summary first -- the number, the stars, and how they are spread --
     because that is what most people read. Then the warmest review set large,
     then the rest.
     ========================================================================= --}}
@if ($reviewTotal > 0)
    <section class="section tp-reviews" id="reviews">
        <div class="wrap tp-reviews__grid">
            <aside class="tp-score" data-reveal="up">
                <p class="eyebrow">In their words</p>
                <p class="tp-score__number">{{ number_format($rating, 1) }}</p>
                <p class="tp-stars tp-stars--large" aria-label="{{ number_format($rating, 1) }} out of 5">
                    @for ($i = 1; $i <= 5; $i++)
                        <span @class(['on' => $i <= round($rating)]) aria-hidden="true">&#9733;</span>
                    @endfor
                </p>
                <p class="tp-score__from">from {{ $reviewTotal }} {{ Str::plural('review', $reviewTotal) }} by customers who had clothes made</p>

                <ul class="tp-bars" aria-label="How the reviews are spread">
                    @for ($star = 5; $star >= 1; $star--)
                        @php($count = (int) ($ratingCounts[$star] ?? 0))
                        <li>
                            <span class="tp-bars__label">{{ $star }}&#9733;</span>
                            <span class="tp-bars__track"><span style="width:{{ $reviewTotal ? round($count / $reviewTotal * 100) : 0 }}%"></span></span>
                            <span class="tp-bars__count">{{ $count }}</span>
                        </li>
                    @endfor
                </ul>
            </aside>

            <div class="tp-voices">
                @if ($featured)
                    <figure class="tp-quote" data-reveal="up">
                        <span class="tp-quote__mark" aria-hidden="true">&ldquo;</span>
                        <blockquote>{{ $featured->body }}</blockquote>
                        <figcaption>
                            <span class="tp-stars" aria-label="{{ $featured->rating }} out of 5">
                                @for ($i = 1; $i <= 5; $i++)
                                    <span @class(['on' => $i <= $featured->rating]) aria-hidden="true">&#9733;</span>
                                @endfor
                            </span>
                            <strong>{{ $shortName($featured->author?->name) }}</strong>
                            @if ($featured->published_at)
                                <span>{{ $featured->published_at->format('F Y') }}</span>
                            @endif
                        </figcaption>
                    </figure>
                @endif

                @if ($others->isNotEmpty())
                    <div class="tp-review-list">
                        @foreach ($others as $review)
                            <figure class="tp-review" data-reveal="up" style="--reveal-delay:{{ ($loop->index % 2) * 60 }}ms">
                                <div class="tp-review__top">
                                    <span class="tp-avatar" aria-hidden="true">{{ Str::upper(Str::substr($shortName($review->author?->name), 0, 1)) }}</span>
                                    <div>
                                        <strong>{{ $shortName($review->author?->name) }}</strong>
                                        <span class="tp-stars tp-stars--small" aria-label="{{ $review->rating }} out of 5">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <span @class(['on' => $i <= $review->rating]) aria-hidden="true">&#9733;</span>
                                            @endfor
                                        </span>
                                    </div>
                                    @if ($review->published_at)
                                        <span class="tp-review__when">{{ $review->published_at->format('M Y') }}</span>
                                    @endif
                                </div>
                                @if ($review->body)
                                    <blockquote>{{ $review->body }}</blockquote>
                                @endif
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </section>
@endif

{{-- =========================================================================
     The invitation, in her name
     ========================================================================= --}}
<section class="cta tp-cta">
    <div class="wrap">
        <p class="eyebrow on-dark" data-reveal="up">Start today</p>
        <h2 class="display" data-reveal="up">Want something made by <em>{{ $profile->business_name }}</em>?</h2>
        <p class="lead" data-reveal="up" style="margin:var(--s-6) auto 0;max-width:46ch;--reveal-delay:200ms">
            @if ($whatsapp)
                Send her a message. Once she opens your order, you will see every stage as it happens — until it is ready to collect.
            @else
                Visit her{{ $where ? " in {$where}" : '' }}. Once she opens your order, you will see every stage as it happens — until it is ready to collect.
            @endif
        </p>
        <p class="tp-cta__actions" data-reveal="up" style="--reveal-delay:260ms">
            @if ($whatsapp)
                <a class="btn" rel="noopener" href="{{ $whatsapp }}"><span>Message her on WhatsApp</span></a>
            @endif
            <a class="btn ghost on-dark" href="{{ route('directory', array_filter(['state' => $profile->state])) }}">
                <span>{{ $profile->state ? "More tailors in {$profile->state}" : 'See the whole house' }}</span>
            </a>
        </p>
    </div>
</section>

{{-- =========================================================================
     More of the house -- never a dead end for somebody who scanned a card
     and finds she is not quite right.
     ========================================================================= --}}
@if ($nearby->isNotEmpty())
    <section class="section tp-more">
        <div class="wrap">
            <div class="tp-head" data-reveal="up">
                <div>
                    <p class="eyebrow">Also in the house</p>
                    <h2>{{ $nearbyIsLocal ? "More tailors in {$profile->state}" : 'More tailors to see' }}</h2>
                </div>
                <a class="tp-head__link" href="{{ route('directory', array_filter(['state' => $nearbyIsLocal ? $profile->state : null])) }}">See all &rarr;</a>
            </div>

            <div class="fh-grid tp-more__grid">
                @foreach ($nearby as $index => $other)
                    @include('public.partials.fh-card', [
                        'profile' => $other,
                        'cover' => ($nearbyCovers[$other->user_id] ?? collect())->first(),
                        'index' => $index,
                    ])
                @endforeach
            </div>
        </div>
    </section>
@endif

{{--
    On a phone the message button follows her down the page: sticky at the
    foot of the page body, so it lets go before the footer rather than
    covering it. It slides in only once the hero's own button has scrolled
    away -- two identical buttons on one screen is one too many. Without
    script it is simply always there.
--}}
@if ($whatsapp)
    <div class="tp-dock" data-dock>
        <a class="btn tp-wa" rel="noopener" href="{{ $whatsapp }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></svg>
            <span>Message on WhatsApp</span>
        </a>
    </div>
@endif

</div>

<script>
    // The share button: the phone's own share sheet where there is one, the
    // link copied where there is not. Nothing is sent anywhere by us.
    (function () {
        var button = document.querySelector('[data-share]');
        if (!button) return;
        var label = button.querySelector('[data-share-label]');

        button.addEventListener('click', function () {
            var title = button.getAttribute('data-share-title');
            var url = button.getAttribute('data-share-url');

            if (navigator.share) {
                navigator.share({ title: title, url: url }).catch(function () {});
                return;
            }

            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () {
                    label.textContent = 'Link copied';
                    setTimeout(function () { label.textContent = 'Share'; }, 2200);
                });
            }
        });
    })();

    (function () {
        var dock = document.querySelector('[data-dock]');
        var actions = document.querySelector('.tp-hero__actions');
        if (!dock || !actions || !('IntersectionObserver' in window)) return;

        dock.classList.add('is-waiting');
        new IntersectionObserver(function (entries) {
            dock.classList.toggle('is-shown', !entries[0].isIntersecting);
        }).observe(actions);
    })();
</script>

@endsection
