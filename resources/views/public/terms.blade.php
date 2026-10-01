@extends('public.layout')

@section('title', "Terms — Rachels Closet")
@section('description', "The rules for using Rachels Closet: orders, money held until you have your clothes, complaints, listings and reviews.")

@section('content')

    {{--
        DRAFT. Every number is read from platform_settings through
        LegalController, so the terms say what the platform actually does
        today. Not yet read by a lawyer.
    --}}
    <section class="section legal">
        <div class="wrap wrap-narrow">
            <p class="eyebrow">Terms</p>
            <h1>How Rachels Closet works</h1>

            <p class="legal__draft">
                This is a draft. It describes how Rachels Closet works today, and will be
                reviewed before it is final.
            </p>

            <p class="lead">
                Rachels Closet Fashion House runs this platform. We connect tailors with the
                people they sew for, and help each side see what is happening. The agreement to
                make the clothes is between the tailor and the customer. By using Rachels Closet
                you agree to these rules.
            </p>

            <h2>Your account</h2>
            <ul>
                <li>You sign in with your phone number and six secret numbers (your PIN). Keep
                    your PIN to yourself. Anything done with it is treated as done by you.</li>
                <li>Use your real name, and a phone number that is yours.</li>
                <li>If you forget your PIN, contact us. We will check it is you and give you a
                    way to choose a new one.</li>
                <li>We may pause an account that breaks these rules. You will be told when it
                    happens.</li>
            </ul>

            <h2>Orders</h2>
            <ul>
                <li>The tailor opens the order, with the price, any deposit, and the date she
                    promises it will be ready.</li>
                <li>The tailor marks each stage as she finishes it, and the customer is told. Mark
                    a stage only when it is truly done.</li>
                <li>When every stage is done, the order is ready. The customer has
                    {{ $n['collect_days'] }} days to collect it.</li>
                <li>An order can be cancelled only before anything has been paid.</li>
            </ul>

            <h2>Paying for an order</h2>
            <p>The tailor chooses, when she opens the order, one of two ways:</p>

            <h3>Money held by Rachels Closet</h3>
            <ul>
                <li>The customer pays Rachels Closet through Flutterwave. We keep the money until
                    the customer has the clothes.</li>
                <li>The tailor is paid {{ $n['hold_days'] }} days after the customer has the
                    clothes, or at once if the customer says she is happy with them.</li>
                <li>If clothes were posted and the customer never says they arrived, the tailor is
                    paid {{ $n['backstop_days'] }} days after they were sent.</li>
                <li>We take no commission, and we pay the payment charge on these orders.</li>
            </ul>

            <h3>Paid straight to the tailor</h3>
            <ul>
                <li>The customer pays the tailor herself &mdash; cash, transfer or POS. None of the
                    money passes through Rachels Closet.</li>
                <li>The tailor records each amount she receives, and the customer is sent a note
                    of it to check.</li>
                <li>Because we never hold this money, we cannot refund it or settle a complaint
                    about it. That is between the tailor and the customer.</li>
            </ul>

            <h2>If something is wrong</h2>
            <ul>
                <li>On an order where we hold the money, the customer can tell us something is
                    wrong once she has the clothes, and before she has said she is happy.</li>
                <li>When she does, we keep the money where it is and telephone both of you. We
                    then do what is agreed: return the money, pay the tailor, or split it.</li>
                <li>Once the customer has said she is happy, or the tailor has been paid, any
                    disagreement is between the tailor and the customer.</li>
            </ul>

            <h2>Listing in the Fashion House</h2>
            <ul>
                <li>A tailor can pay to be listed in the Fashion House, where customers find
                    tailors, for {{ $n['monthly_days'] }} or {{ $n['yearly_days'] }} days at a
                    time. Prices are shown before you pay.</li>
                <li>A listing is paid for once. It does not renew by itself, and nothing is taken
                    from you again without you choosing to pay.</li>
                <li>Buying more days adds them to the end of the days you already have.</li>
                <li>If your days run out you stay listed for {{ $n['grace_days'] }} more days, in
                    case a payment is on its way. After that you are not listed. Your orders,
                    money, measurements and your own page are not affected.</li>
            </ul>

            <h2>Reviews</h2>
            <ul>
                <li>Once a customer has her clothes, she and the tailor can each review the other.
                    Be honest and fair.</li>
                <li>A four- or five-star review of a tailor waits to be checked if fewer than
                    {{ $n['proof_threshold'] }}% of that order's stages were photographed. This
                    stops made-up orders from inflating ratings. Lower ratings are never held.</li>
            </ul>

            <h2>Things you must not do</h2>
            <ul>
                <li>Pretend to be somebody else, or use somebody else's account.</li>
                <li>Mark work done that was not done, or make up orders or reviews.</li>
                <li>Use somebody's measurements or details for anything but their order.</li>
                <li>Upload photographs that are not yours to share, or that are offensive.</li>
            </ul>

            <h2>What we are responsible for</h2>
            <p>
                We run the platform carefully, but we do not make the clothes. The quality of the
                work, and keeping promises about it, is the tailor's responsibility. We are
                responsible for money we hold, as described above. These terms are governed by
                the laws of the Federal Republic of Nigeria.
            </p>

            <h2>Contact</h2>
            <p>
                @if ($n['support_phone'])
                    Call or WhatsApp us on <a href="tel:{{ $n['support_phone'] }}">{{ $n['support_phone'] }}</a>.
                @else
                    Contact Rachels Closet.
                @endif
                See also our <a href="{{ route('privacy') }}">privacy notice</a>.
            </p>

            {{-- A fixed date, changed by hand when the wording changes. --}}
            <p class="legal__updated">Last updated 1 October 2026.</p>
        </div>
    </section>

@endsection
