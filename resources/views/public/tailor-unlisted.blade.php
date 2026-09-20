@extends('public.layout')

@section('title', $profile->business_name . " — Rachel's Closet")
{{-- Deliberately not indexed: she is not listed, and a search engine should
     not start ranking a page that says so. --}}
@section('description', 'This tailor is not currently listed on Rachel\'s Closet.')

@section('noindex', true)

@section('content')

    <section class="section">
        <div class="wrap wrap-narrow">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">The Fashion House</p>
                <h1 class="display">{{ $profile->business_name }}</h1>
                <p class="lead">
                    This tailor is not listed at the moment. Her page will come back if she
                    returns.
                </p>
            </div>

            {{--
                The one thing that still helps somebody standing there with a
                card in her hand and her phone out.
            --}}
            <p data-reveal="up" style="--reveal-delay:140ms">
                <a class="btn" href="{{ route('directory', ['state' => $profile->state]) }}">
                    <span>Find another tailor{{ $profile->state ? " in {$profile->state}" : '' }}</span>
                </a>
            </p>
        </div>
    </section>

@endsection
