@extends('public.layout')

@section('title', $state ? "Tailors in {$state} — Rachel's Closet" : "Find a tailor — Rachel's Closet")
@section('description', $state
    ? "Tailors in {$state}. See their work, read reviews, and follow every stage of your garment."
    : 'Find a tailor near you in Nigeria. See their work, read real reviews, and follow every stage of your garment from cutting to collection.')

@section('content')

    <section class="section tight">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">The Fashion House</p>
                <h1 class="display">{{ $state ? "Tailors in {$state}" : 'Find a tailor' }}</h1>
                <p class="lead">
                    Every tailor here shows you the work as it happens — stage by stage, with
                    photographs, until it is ready to collect.
                </p>
            </div>

            {{--
                A GET form, on purpose. The result of a search is then an
                address you can send to somebody, bookmark, or let a search
                engine index — which is the entire point of this page.
            --}}
            <form class="finder" method="get" action="{{ route('directory') }}" data-reveal="up">
                <label class="finder__field">
                    <span class="sr-only">Search by name</span>
                    <input type="search" name="q" value="{{ $term }}" placeholder="Search by name">
                </label>

                <label class="finder__field">
                    <span class="sr-only">State</span>
                    <select name="state">
                        <option value="">Anywhere in Nigeria</option>
                        @foreach ($states as $option)
                            <option value="{{ $option }}" @selected($state === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>

                <button type="submit" class="btn"><span>Search</span></button>
            </form>
        </div>
    </section>

    <section class="section tight">
        <div class="wrap">
            @if ($tailors->isEmpty())
                <p class="finder__empty" data-reveal="up">
                    No tailors {{ $state ? "in {$state}" : 'yet' }}.
                    @if ($state || $term)
                        <a href="{{ route('directory') }}">See everybody</a>.
                    @endif
                </p>
            @else
                <p class="finder__count" data-reveal="up">
                    {{ $tailors->total() }} {{ Str::plural('tailor', $tailors->total()) }}
                </p>

                <div class="tailor-grid">
                    @foreach ($tailors as $index => $profile)
                        @php($gallery = $covers[$profile->user_id] ?? collect())
                        <a class="tailor-card"
                           href="{{ route('tailor', $profile->slug) }}"
                           data-reveal="up"
                           style="--reveal-delay:{{ ($index % 4) * 70 }}ms">
                            <div class="frame tall">
                                @if ($gallery->isNotEmpty())
                                    <img
                                        src="{{ route('portfolio.photo', basename($gallery->first()->path)) }}"
                                        alt="Work by {{ $profile->business_name }}"
                                        class="zoom"
                                        loading="lazy" decoding="async">
                                @else
                                    {{-- No gallery yet. A monogram beats an empty frame. --}}
                                    <span class="tailor-card__initial" aria-hidden="true">
                                        {{ Str::upper(Str::substr($profile->business_name, 0, 1)) }}
                                    </span>
                                @endif
                            </div>

                            <div class="tailor-card__body">
                                <h2>{{ $profile->business_name }}</h2>

                                @if ($profile->state)
                                    <p class="tailor-card__where">
                                        {{ collect([$profile->location, $profile->state])->filter()->join(', ') }}
                                    </p>
                                @endif

                                @if ($profile->review_count > 0)
                                    <p class="rating">
                                        <span class="rating__stars" aria-hidden="true">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <span @class(['on' => $i <= round($profile->avg_rating)])>&#9733;</span>
                                            @endfor
                                        </span>
                                        {{ number_format((float) $profile->avg_rating, 1) }}
                                        <span class="muted">({{ $profile->review_count }})</span>
                                    </p>
                                @else
                                    <p class="rating"><span class="muted">New here</span></p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="pager">{{ $tailors->links() }}</div>
            @endif
        </div>
    </section>

@endsection
