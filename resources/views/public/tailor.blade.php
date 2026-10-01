@extends('public.layout')

@section('title', $profile->business_name . " — Rachel's Closet")
@section('description', Str::limit($profile->bio ?: "See work by {$profile->business_name}"
    . ($profile->state ? " in {$profile->state}" : '')
    . ', read reviews, and follow every stage of your garment.', 155))

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
    'address' => array_filter([
        '@type' => 'PostalAddress',
        'addressLocality' => $profile->location,
        'addressRegion' => $profile->state,
        'addressCountry' => 'NG',
    ]),
    'aggregateRating' => $reviews->isNotEmpty() ? [
        '@type' => 'AggregateRating',
        'ratingValue' => (string) round((float) $profile->avg_rating, 1),
        'reviewCount' => (string) $reviews->count(),
    ] : null,
]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
</script>

<section class="section tight">
    <div class="wrap">
        <div class="section-head" data-reveal="up">
            <p class="eyebrow">
                <a href="{{ route('directory') }}">The Fashion House</a>
                @if ($profile->state)
                    <span aria-hidden="true">&middot;</span>
                    <a href="{{ route('directory', ['state' => $profile->state]) }}">{{ $profile->state }}</a>
                @endif
            </p>

            <h1 class="display">{{ $profile->business_name }}</h1>

            @if ($profile->location || $profile->state)
                <p class="lead">{{ collect([$profile->location, $profile->state])->filter()->join(', ') }}</p>
            @endif
        </div>

        <div class="profile-meta" data-reveal="up" style="--reveal-delay:140ms">
            @if ($reviews->isNotEmpty())
                <p class="rating rating--large">
                    <span class="rating__stars" aria-hidden="true">
                        @for ($i = 1; $i <= 5; $i++)
                            <span @class(['on' => $i <= round($profile->avg_rating)])>&#9733;</span>
                        @endfor
                    </span>
                    {{ number_format((float) $profile->avg_rating, 1) }}
                    <span class="muted">from {{ $reviews->count() }} {{ Str::plural('review', $reviews->count()) }}</span>
                </p>
            @endif

            {{--
                Her own WhatsApp, which is where this conversation actually
                happens. Not a contact form: a stranger who found her through
                a search wants to ask a question now, on the app already open
                on her phone.
            --}}
            @if ($profile->whatsapp_phone)
                <a class="btn" rel="noopener"
                   href="{{ \App\Support\WhatsApp::to($profile->whatsapp_phone) }}">
                    <span>Message on WhatsApp</span>
                </a>
            @endif
        </div>
    </div>
</section>

@if ($profile->bio)
    <section class="section tight">
        <div class="wrap wrap-narrow">
            <p class="lead" data-reveal="up">{{ $profile->bio }}</p>
        </div>
    </section>
@endif

@if ($gallery->isNotEmpty())
    <section class="section" id="work">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">The lookbook</p>
                <h2>Her work</h2>
            </div>

        {{--
            The same gallery and the same native <dialog> as the landing page,
            reused rather than reimplemented: any [data-lightbox-open] button
            carrying data-full joins the set motion.js already drives, so this
            page gets keyboard navigation and Escape for free.

            Most of these were photographed by customers wearing the finished
            garment, which is what makes the section worth looking at.
        --}}
        <div class="gallery">
            @foreach ($gallery as $index => $item)
                @php($src = route('portfolio.photo', basename($item->path)))
                <button
                    type="button"
                    data-lightbox-open
                    data-full="{{ $src }}"
                    data-caption="{{ $item->caption ?: 'Work by '.$profile->business_name }}"
                    data-reveal="scale"
                    style="--reveal-delay:{{ $index * 70 }}ms; border:0; padding:0; background:none"
                    aria-label="Open larger: {{ $item->caption ?: 'photograph of her work' }}"
                >
                    <div class="frame {{ $index % 5 === 0 ? 'tall' : 'square' }}">
                        <img
                            src="{{ $src }}"
                            alt="{{ $item->caption ?: 'Work by '.$profile->business_name }}"
                            class="zoom"
                            loading="lazy" decoding="async">
                    </div>
                    @if ($item->caption)
                        <figcaption>{{ $item->caption }}</figcaption>
                    @endif
                </button>
            @endforeach
        </div>

            @include('public.partials.lightbox')
        </div>
    </section>
@endif

@if ($reviews->isNotEmpty())
    <section class="section">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">In their words</p>
                <h2>What her customers say</h2>
            </div>

            <div class="review-grid">
            @foreach ($reviews as $review)
                <figure class="review-card" data-reveal="up">
                    <div class="rating">
                        <span class="rating__stars" aria-hidden="true">
                            @for ($i = 1; $i <= 5; $i++)
                                <span class="{{ $i <= $review->rating ? 'on' : '' }}">&#9733;</span>
                            @endfor
                        </span>
                        <span class="sr-only">{{ $review->rating }} out of 5</span>
                    </div>

                    @if ($review->body)
                        <blockquote>{{ $review->body }}</blockquote>
                    @endif

                    <figcaption>
                        {{ $review->author?->name ?? 'A customer' }}
                        @if ($review->published_at)
                            <span class="muted">· {{ $review->published_at->format('M Y') }}</span>
                        @endif
                    </figcaption>
                </figure>
                @endforeach
            </div>
        </div>
    </section>
@endif

<section class="cta">
    <div class="wrap">
        <p class="eyebrow on-dark" data-reveal="up">Start today</p>
        <h2 class="display" data-split data-reveal>Want something made?</h2>
        <p class="lead" data-reveal="up" style="margin:var(--s-6) auto 0;max-width:46ch;--reveal-delay:300ms">
            Ask {{ $profile->business_name }} to start an order, and you will see every stage as
            it happens — until it is ready to collect.
        </p>
        @if ($profile->whatsapp_phone)
            <p style="margin-top:var(--s-8)" data-reveal="up">
                <a class="btn" rel="noopener"
                   href="{{ \App\Support\WhatsApp::to($profile->whatsapp_phone) }}">
                    <span>Message her on WhatsApp</span>
                </a>
            </p>
        @endif
    </div>
</section>

@endsection
