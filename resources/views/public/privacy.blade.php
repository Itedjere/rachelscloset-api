@extends('public.layout')

@section('title', "Privacy — Rachels Closet")
@section('description', "What Rachels Closet keeps about you, who can see it, and what you can ask us to do with it.")

@section('content')

    {{--
        DRAFT. Written from what the platform actually does -- see
        LegalController -- but not yet read by a lawyer. Short sentences on
        purpose: the people this protects include many who read poorly.
    --}}
    <section class="section legal">
        <div class="wrap wrap-narrow">
            <p class="eyebrow">Privacy</p>
            <h1>What we keep, and who can see it</h1>

            <p class="legal__draft">
                This is a draft. It describes how Rachels Closet works today, and will be
                reviewed before it is final.
            </p>

            <p class="lead">
                Rachels Closet Fashion House connects tailors with the people they sew for.
                To do that we keep some information about you. This page says what, why, who
                can see it, and what you can ask us to do. It follows Nigeria's Data Protection
                Act, 2023.
            </p>

            <h2>What we keep</h2>
            <ul>
                <li><strong>Your account:</strong> your name, your phone number, and your
                    photograph if you add one. An email address only if you choose to give
                    one. Your PIN is stored scrambled, so nobody can read it &mdash; not even us.</li>
                <li><strong>If you sew:</strong> your shop's name, area and state, what you write
                    about your work, the photographs you add to your gallery, and the bank account
                    you are paid into.</li>
                <li><strong>Your measurements:</strong> the photograph of the measurement book,
                    and any numbers or notes written with it.</li>
                <li><strong>Your orders:</strong> what is being made, the price, the dates, each
                    stage as it is finished, and the photographs taken of the work.</li>
                <li><strong>Money:</strong> what was paid, when, and the payment reference. Card
                    and bank details you type at checkout go to our payment provider,
                    Flutterwave. They never reach us.</li>
                <li><strong>Reviews</strong> you write and receive.</li>
                <li><strong>Alerts:</strong> the messages we send you, and, if you turn on phone
                    alerts, the address your browser gives us to deliver them, with a guess at
                    the kind of phone, so you can tell your devices apart.</li>
            </ul>

            <h2>Who can see your measurements</h2>
            <p>Your measurements are the most private thing here, so the rules are strict:</p>
            <ul>
                <li><strong>You</strong> can always see them.</li>
                <li><strong>A tailor making something for you right now</strong> can see them
                    while that order is open.</li>
                <li><strong>A tailor you have allowed</strong> can see them, until you stop
                    allowing it. You can stop it at any time from &ldquo;Who can see my
                    measurements&rdquo;.</li>
                <li><strong>Rachels Closet staff cannot open them.</strong></li>
                <li>Nobody else can, and nobody can search for them.</li>
            </ul>
            <p>Only you can delete your measurements.</p>

            <h2>Who else sees what</h2>
            <ul>
                <li><strong>The two people on an order</strong> &mdash; the tailor and the
                    customer &mdash; see that order, its stages and its photographs.</li>
                <li><strong>Anybody</strong> can see a listed tailor's public page: her shop
                    name, area, gallery, rating and the reviews customers have written about her,
                    with the reviewer's name.</li>
                <li><strong>Rachels Closet staff</strong> can see orders, payments and the
                    photographs of the work, to help when something goes wrong, to settle a
                    complaint, and to pay tailors.</li>
                <li><strong>Flutterwave</strong> handles payments, checks a tailor's bank
                    account, and sends her money.</li>
                <li><strong>Your phone's alert service</strong> (from Google, Apple or Mozilla,
                    depending on your phone) carries the alerts you turned on.</li>
            </ul>
            <p>We do not sell your information, and we do not show advertising.</p>

            <h2>Cookies and tracking</h2>
            <p>
                We use no tracking or advertising cookies, and no analytics. This website sets
                one small cookie that keeps it working while you use it. The app keeps your
                signed-in session and your light or dark choice on your own device. Some
                photographs on our home page are loaded from Pexels, a photo library, which can
                see that your browser asked for them.
            </p>

            <h2>How long we keep it</h2>
            <ul>
                <li>Alerts you have read are deleted after {{ $n['read_days'] }} days, and ones
                    you have not read after {{ $n['unread_days'] }} days.</li>
                <li>Measurements are kept until you delete them, because last year's numbers are
                    how a tailor knows what changed.</li>
                <li>Orders and payments are kept as long as the law requires us to keep business
                    and financial records.</li>
                <li>Everything else is kept while you have an account.</li>
            </ul>

            <h2>What you can ask us to do</h2>
            <p>You can ask us to:</p>
            <ul>
                <li>show you what we keep about you;</li>
                <li>correct anything that is wrong;</li>
                <li>delete your account and what we keep about you, except records the law makes
                    us keep;</li>
                <li>stop using your information for something you did not agree to.</li>
            </ul>
            <p>
                @if ($n['support_phone'])
                    Call or WhatsApp us on <a href="tel:{{ $n['support_phone'] }}">{{ $n['support_phone'] }}</a>.
                @else
                    Contact Rachels Closet.
                @endif
                We will check it is you before we change or delete anything. If you are not happy
                with our answer, you can complain to the Nigeria Data Protection Commission.
            </p>

            <h2>Keeping it safe</h2>
            <p>
                Photographs of your measurements and of work in progress are never on a public
                web address; each is opened only after checking that the person asking is
                allowed to see it. PINs are stored scrambled, and resetting a PIN signs every
                other device out.
            </p>

            {{-- A fixed date, changed by hand when the wording changes. --}}
            <p class="legal__updated">Last updated 1 October 2026.</p>
        </div>
    </section>

@endsection
