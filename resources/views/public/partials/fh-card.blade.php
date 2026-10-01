{{--
    One tailor as a Fashion House card: her photograph, her name over it, and
    how many garments are on her table right now.

    Shared by the directory and the "more tailors" row on a tailor's page, so
    a card means the same thing wherever it appears.

    Expects: $profile (from TailorRanking::search, so it carries review_count
    and live_orders_count), $cover (a PortfolioItem or null), $index, and
    optionally $feature.
--}}
@php($live = (int) $profile->live_orders_count)
<a @class(['fh-card', 'is-feature' => $feature ?? false])
   href="{{ route('tailor', $profile->slug) }}"
   data-reveal="up"
   style="--reveal-delay:{{ ($index % 4) * 60 }}ms">

    <div class="fh-card__media">
        @if ($cover)
            <img
                src="{{ route('portfolio.photo', basename($cover->path)) }}"
                alt="Work by {{ $profile->business_name }}"
                loading="{{ $index < 4 ? 'eager' : 'lazy' }}" decoding="async">
        @else
            {{-- No gallery yet. A monogram beats an empty frame. --}}
            <span class="fh-card__initial" aria-hidden="true">
                {{ Str::upper(Str::substr($profile->business_name, 0, 1)) }}
            </span>
        @endif
    </div>

    {{--
        Garments on her table right now. A dot that sends out waves when she
        is sewing; a still, pale one when she is free. Said in words as well --
        the motion is the flourish, the text is the fact.
    --}}
    <span @class(['fh-card__live', 'is-live' => $live > 0])>
        <span @class(['live-dot', 'is-live' => $live > 0]) aria-hidden="true"></span>
        {{-- Full words on a wide card; on a small phone card only the number
             fits beside the rating, and a legend says what the dots mean. --}}
        @if ($live > 0)
            <span class="fh-live__full">{{ $live }} in the making</span>
            <span class="fh-live__short" aria-hidden="true">{{ $live }}</span>
        @else
            <span class="fh-live__full">Taking new work</span>
        @endif
    </span>

    @if ($profile->review_count > 0)
        <span class="fh-card__rating" aria-label="Rated {{ number_format((float) $profile->avg_rating, 1) }} out of 5 from {{ $profile->review_count }} {{ Str::plural('review', $profile->review_count) }}">
            &#9733; {{ number_format((float) $profile->avg_rating, 1) }}
            <span>({{ $profile->review_count }})</span>
        </span>
    @endif

    <div class="fh-card__caption">
        <h2 class="fh-card__name">{{ $profile->business_name }}</h2>
        <p class="fh-card__where">
            {{ collect([$profile->location, $profile->state])->filter()->join(', ') ?: 'Nigeria' }}
        </p>
        <span class="fh-card__cta" aria-hidden="true">See her work &rarr;</span>
    </div>
</a>
