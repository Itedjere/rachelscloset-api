@extends('public.layout')

@section('title', "Contact us — Rachels Closet")
@section('description', "Ring Rachels Closet or send us a WhatsApp message. Help with orders, PINs, measurements and joining as a tailor.")

@section('content')

    {{--
        A phone number and a WhatsApp chat, not a form. Nothing on this
        platform sends email and most of the people who need this page would
        rather talk -- many read slowly, and the person who has forgotten her
        PIN cannot sign in to anything anyway. The number is the help line from
        admin Settings (see PagesController), so a changed SIM is not a deploy.

        Deliberately absent: opening hours and a street address. We do not
        have either on record, and a made-up one is worse than none.
    --}}

    <section class="fh-masthead page-masthead">
        <div class="fh-masthead__glow" aria-hidden="true"></div>

        <div class="wrap fh-masthead__body">
            <p class="eyebrow on-dark" data-reveal="up">Contact us</p>
            <h1 class="fh-masthead__title" data-reveal="up">Talk to a <em>person</em>.</h1>
            <p class="page-masthead__lead" data-reveal="up" style="--reveal-delay:120ms">
                No forms and no waiting for an email. Ring us, or send us a WhatsApp message,
                and a person from Rachels Closet will answer.
            </p>
        </div>
    </section>

    {{-- =====================================================================
         The two ways in, as big as a thumb could want
         ===================================================================== --}}
    <section class="section tight contact-ways">
        <div class="wrap">
            @if ($phone)
                <div class="contact-cards">
                    <a class="contact-card" href="tel:{{ $phone }}" data-reveal="up">
                        <span class="contact-card__icon" aria-hidden="true">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>
                        </span>
                        <span class="contact-card__label">Ring us</span>
                        <span class="contact-card__value">{{ $phoneSpaced }}</span>
                        <span class="contact-card__cta">Tap to call &rarr;</span>
                    </a>

                    <a class="contact-card contact-card--wa" href="{{ $whatsapp }}" rel="noopener" data-reveal="up" style="--reveal-delay:100ms">
                        <span class="contact-card__icon" aria-hidden="true">
                            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                        </span>
                        <span class="contact-card__label">Send a WhatsApp message</span>
                        <span class="contact-card__value">{{ $phoneSpaced }}</span>
                        <span class="contact-card__cta">Open WhatsApp &rarr;</span>
                    </a>
                </div>

                <p class="contact-note" data-reveal="up">
                    A voice note on WhatsApp is fine &mdash; you do not have to type.
                </p>
            @else
                {{-- No help line set yet. Say so plainly rather than show an
                     empty button; the rest of the page still helps. --}}
                <div class="contact-cards">
                    <div class="contact-card contact-card--quiet" data-reveal="up">
                        <span class="contact-card__label">Our help line is being set up</span>
                        <span class="contact-card__value">Please check back soon.</span>
                        <span class="contact-card__cta">Meanwhile, the answers below may help.</span>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- =====================================================================
         What is it about? Some questions are fastest answered elsewhere --
         and saying where saves a phone call on both ends.
         ===================================================================== --}}
    <section class="section on-cream">
        <div class="wrap">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">What is it about?</p>
                <h2>The quickest way to sort it</h2>
            </div>

            <div class="routes">
                <div class="route" data-reveal="up">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M7.8 3 4 5.2l1.7 3.6L8 7.6V21h8V7.6l2.3 1.2L20 5.2 16.2 3a4.2 4.2 0 0 1-8.4 0Z"/></svg>
                    </span>
                    <h3>An order you are waiting for</h3>
                    <p>
                        The tracker on your order shows every stage she has finished, and your tailor
                        is the first person to ask. If we hold the money and the clothes are not right,
                        tap &ldquo;Something is wrong&rdquo; on the order and we will ring you.
                    </p>
                    <a class="route__link" href="{{ $appUrl }}/sign-in">Open my orders &rarr;</a>
                </div>

                <div class="route" data-reveal="up" style="--reveal-delay:60ms">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 11.5 21 2M17 6l3 3M14.5 8.5 17 11"/><circle cx="7.5" cy="15.5" r="5"/></svg>
                    </span>
                    <h3>I forgot my PIN</h3>
                    <p>
                        Ring us. We check it is you, and give you six numbers to choose a new PIN.
                        Nobody can look up your old one &mdash; not even us.
                    </p>
                    <a class="route__link" href="{{ $appUrl }}/forgot">What to do &rarr;</a>
                </div>

                <div class="route" data-reveal="up" style="--reveal-delay:120ms">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></svg>
                    </span>
                    <h3>My tailor read me six numbers</h3>
                    <p>
                        She made an account for you in her shop. Type the numbers in, choose your own
                        PIN, and you can follow your clothes from your phone.
                    </p>
                    <a class="route__link" href="{{ $appUrl }}/claim">Use my six numbers &rarr;</a>
                </div>

                <div class="route" data-reveal="up" style="--reveal-delay:180ms">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/></svg>
                    </span>
                    <h3>I sew, and want to join</h3>
                    <p>
                        Put your shop in the Fashion House in a few minutes, with just your phone.
                        Ring us if you would like a hand setting it up.
                    </p>
                    <a class="route__link" href="{{ $appUrl }}/join?as=tailor">Join the house &rarr;</a>
                </div>

                <div class="route" data-reveal="up" style="--reveal-delay:240ms">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6.5-8 12-8 12s-8-5.5-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    </span>
                    <h3>Finding a tailor near me</h3>
                    <p>
                        Choose your state in the Fashion House and see who is sewing near you, with
                        their work and their reviews.
                    </p>
                    <a class="route__link" href="{{ route('directory') }}">Find a tailor &rarr;</a>
                </div>

                <div class="route" data-reveal="up" style="--reveal-delay:300ms">
                    <span class="route__icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    </span>
                    <h3>My measurements and my privacy</h3>
                    <p>
                        You choose which tailors can see your measurements, and can stop it from your
                        account at any time. To have something removed, ring us.
                    </p>
                    <a class="route__link" href="{{ route('privacy') }}">How we keep your data &rarr;</a>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Questions people ask. <details>, so it works with no script at all.
         ===================================================================== --}}
    <section class="section">
        <div class="wrap wrap-narrow">
            <div class="section-head" data-reveal="up">
                <p class="eyebrow">Questions people ask</p>
                <h2>Before you ring</h2>
            </div>

            <div class="faq" data-reveal="up">
                <details>
                    <summary>Does it cost a customer anything?</summary>
                    <p>No. You pay your tailor for your clothes, as you always have. Following
                        your order on Rachels Closet is free.</p>
                </details>
                <details>
                    <summary>Do I need an email address?</summary>
                    <p>No. You sign in with your phone number and six secret numbers you choose.
                        Nothing here needs an email address, and we do not send texts you pay for.</p>
                </details>
                <details>
                    <summary>I do not read well. Can I still use it?</summary>
                    <p>Yes. It is built for tapping, not typing. Every stage of your clothes has a
                        short voice recording saying what it means, and your tailor can add photographs
                        as she works.</p>
                </details>
                <details>
                    <summary>How does holding the money work?</summary>
                    <p>If your tailor offers it, you pay Rachels Closet instead of paying her
                        directly. We keep the money until you have your clothes, then pay her. If
                        they are not right when you get them, tap &ldquo;Something is wrong&rdquo; on
                        the order, and we will ring you both before any money moves.</p>
                </details>
                <details>
                    <summary>Who can see my measurements?</summary>
                    <p>You, a tailor while she is making something for you, and any tailor you have
                        allowed. You can stop a tailor seeing them at any time. Our staff cannot open them.</p>
                </details>
                <details>
                    <summary>What does it cost a tailor?</summary>
                    <p>One flat subscription to be listed in the Fashion House. We take no commission
                        from your orders. The price is shown in the app when you join.</p>
                </details>
            </div>
        </div>
    </section>

    <section class="section cta">
        <div class="wrap">
            <p class="eyebrow on-dark" data-reveal="up">Rachels Closet</p>
            <h2 class="display" data-reveal="up">We would love to <em class="page-em">hear</em> from you.</h2>
            <p class="page-cta__actions" data-reveal="up" style="--reveal-delay:200ms">
                @if ($phone)
                    <a class="btn" href="tel:{{ $phone }}"><span>Call {{ $phoneSpaced }}</span></a>
                @endif
                <a class="btn ghost on-dark" href="{{ route('about') }}"><span>About us</span></a>
            </p>
        </div>
    </section>

@endsection
