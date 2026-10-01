@extends('public.layout')

@section('title', $state ? "Tailors in {$state} — The Fashion House" : "The Fashion House — Rachels Closet")
@section('description', $state
    ? "Tailors in {$state}. See their work, read reviews, and follow every stage of your garment."
    : 'Find a tailor near you in Nigeria. See their work, read real reviews, and follow every stage of your garment from cutting to collection.')

@section('content')

    {{-- =====================================================================
         The masthead

         A magazine's designer index, not a search form: the house's name set
         large, what is happening in it right now, and the search sitting in
         the band rather than above a list.
         ===================================================================== --}}
    <section class="fh-masthead">
        <div class="fh-masthead__glow" aria-hidden="true"></div>

        <div class="wrap fh-masthead__body">
            <p class="eyebrow on-dark" data-reveal="up">Rachels Closet presents</p>
            <h1 class="fh-masthead__title" data-reveal="up">
                @if ($state)
                    Tailors of <em>{{ $state }}</em>
                @else
                    The Fashion <em>House</em>
                @endif
            </h1>

            {{-- The house, live. Counted over every listed tailor, through the
                 same gate as the listing -- see DirectoryController. --}}
            <p class="fh-masthead__stats" data-reveal="up" style="--reveal-delay:120ms">
                <span><strong>{{ $house['tailors'] }}</strong> {{ Str::plural('tailor', $house['tailors']) }}</span>
                <span class="fh-sep" aria-hidden="true">&middot;</span>
                <span><strong>{{ $house['states'] }}</strong> {{ Str::plural('state', $house['states']) }}</span>
                <span class="fh-sep" aria-hidden="true">&middot;</span>
                <span class="fh-masthead__live">
                    <span @class(['live-dot', 'is-live' => $house['in_the_making'] > 0]) aria-hidden="true"></span>
                    <strong>{{ $house['in_the_making'] }}</strong>
                    {{ Str::plural('garment', $house['in_the_making']) }} in the making right now
                </span>
            </p>

            {{--
                A GET form, on purpose. The result of a search is then an
                address you can send to somebody, bookmark, or let a search
                engine index -- which is the entire point of this page.
            --}}
            <form class="fh-search" method="get" action="{{ route('directory') }}" data-reveal="up" style="--reveal-delay:200ms">
                <label class="fh-search__field">
                    <span class="sr-only">Search by name</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" name="q" value="{{ $term }}" placeholder="A tailor's name">
                </label>

                <label class="fh-search__field fh-search__state">
                    <span class="sr-only">State</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 0 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    <select name="state">
                        <option value="">Anywhere in Nigeria</option>
                        @foreach ($states as $option)
                            <option value="{{ $option }}" @selected($state === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </label>

                <button type="submit" class="btn"><span>Find a tailor</span></button>
            </form>
        </div>
    </section>

    {{-- =====================================================================
         The states, as one tap each -- "near me" is the search that matters,
         and a row of names with counts answers it without opening a menu.
         ===================================================================== --}}
    {{-- One box around the chips and the grid, so the chips stick while the
         tailors scroll past and let go when the list ends -- not over the
         invitation and the footer. --}}
    <div class="fh-browse">
    @if ($byState->isNotEmpty())
        <nav class="fh-states" aria-label="Tailors by state">
            <div class="wrap">
                <div class="fh-states__track">
                    <a href="{{ route('directory', array_filter(['q' => $term])) }}" @class(['fh-chip', 'is-on' => ! $state])>
                        Everywhere <span>{{ $house['tailors'] }}</span>
                    </a>
                    @foreach ($byState as $name => $count)
                        <a href="{{ route('directory', array_filter(['state' => $name, 'q' => $term])) }}" @class(['fh-chip', 'is-on' => $state === $name])>
                            {{ $name }} <span>{{ $count }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </nav>
    @endif

    {{-- =====================================================================
         The house
         ===================================================================== --}}
    <section class="section fh-house">
        <div class="wrap">
            @if ($tailors->isEmpty())
                <div class="fh-empty" data-reveal="up">
                    <p class="fh-empty__title">No tailors {{ $state ? "in {$state}" : '' }}{{ $term ? " called \u{201C}{$term}\u{201D}" : '' }} yet.</p>
                    @if ($state || $term)
                        <a class="btn ghost" href="{{ route('directory') }}"><span>See the whole house</span></a>
                    @endif
                </div>
            @else
                <div class="fh-house__head" data-reveal="up">
                    <p class="fh-house__count">
                        {{ $tailors->total() }} {{ Str::plural('tailor', $tailors->total()) }}{{ $state ? " in {$state}" : '' }}{{ $term ? " matching \u{201C}{$term}\u{201D}" : '' }}
                    </p>
                    <p class="fh-legend">
                        <span class="live-dot is-live" aria-hidden="true"></span> Sewing now
                        <span class="live-dot" aria-hidden="true" style="margin-left:1rem"></span> Taking new work
                    </p>
                </div>

                @php($featureFirst = $tailors->currentPage() === 1 && ! $state && ! $term)

                <div class="fh-grid">
                    @foreach ($tailors as $index => $profile)
                        @php($cover = ($covers[$profile->user_id] ?? collect())->first())
                        @include('public.partials.fh-card', [
                            'cover' => $cover,
                            'feature' => $featureFirst && $index === 0,
                        ])
                    @endforeach

                    {{--
                        The tile that squares the grid. The feature takes four
                        cells, so a page of 24 fills 27 and leaves a ragged last
                        row; this makes it 28 -- seven full rows of four, ten of
                        three on a tablet (where the feature spans the width),
                        thirteen of two on a phone. And it is the right thing
                        to say at the end of the house: there is room.
                    --}}
                    @if ($featureFirst && $tailors->count() === $tailors->perPage())
                        <a class="fh-card is-invite" href="{{ $appUrl }}/join?as=tailor" data-reveal="up">
                            <span class="fh-invite-tile__mark" aria-hidden="true">+</span>
                            <span class="fh-invite-tile__title">Your shop could be here</span>
                            <span class="fh-invite-tile__cta">Join the house &rarr;</span>
                        </a>
                    @endif
                </div>

                {{-- Previous / where you are / next. Big targets, no strip of tiny numbers. --}}
                @if ($tailors->lastPage() > 1)
                    <nav class="fh-pager" aria-label="Pages">
                        @if ($tailors->onFirstPage())
                            <span class="btn ghost is-disabled" aria-disabled="true"><span>&larr; Previous</span></span>
                        @else
                            <a class="btn ghost" href="{{ $tailors->appends(array_filter(['state' => $state, 'q' => $term]))->previousPageUrl() }}"><span>&larr; Previous</span></a>
                        @endif

                        <span class="fh-pager__where">Page {{ $tailors->currentPage() }} of {{ $tailors->lastPage() }}</span>

                        @if ($tailors->hasMorePages())
                            <a class="btn ghost" href="{{ $tailors->appends(array_filter(['state' => $state, 'q' => $term]))->nextPageUrl() }}"><span>Next &rarr;</span></a>
                        @else
                            <span class="btn ghost is-disabled" aria-disabled="true"><span>Next &rarr;</span></span>
                        @endif
                    </nav>
                @endif
            @endif
        </div>
    </section>

    </div>

    {{-- An invitation at the foot, for the tailor who came to look. --}}
    <section class="section tight fh-invite">
        <div class="wrap fh-invite__body" data-reveal="up">
            <div>
                <p class="eyebrow">If you sew</p>
                <h2>Your shop belongs in this house.</h2>
            </div>
            <a class="btn" href="{{ $appUrl }}/join?as=tailor"><span>Join the house</span></a>
        </div>
    </section>

@endsection
