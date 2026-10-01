<!doctype html>
<html lang="en" @class([])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#251540">

    <title>@yield('title', "Rachels Closet")</title>
    <meta name="description" content="@yield('description', 'Find a tailor in Nigeria, and watch your clothes being made. Rachels Closet shows you every stage, from cutting to collection.')">

    {{--
        Server-rendered on purpose. A QR code printed on cardboard in somebody's
        purse has to resolve to a readable page on a cheap phone on a bad
        connection -- not to a loading spinner waiting on a JavaScript bundle.
        The whole point of the directory is being findable, which also means
        being indexable.
    --}}
    <link rel="canonical" href="{{ url()->current() }}">
    @hasSection('noindex')
        {{-- A page that says somebody is not listed should not itself be
             ranked for her name. --}}
        <meta name="robots" content="noindex, follow">
    @endif
    <meta property="og:title" content="@yield('title', "Rachels Closet")">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="Rachels Closet">
    <meta property="og:description" content="@yield('description', 'Find a tailor in Nigeria, and watch your clothes being made.')">
    <meta property="og:image" content="{{ url('/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    {{-- SVG where it is understood, .ico everywhere else, and a 180px PNG for
         an iPhone home screen. The manifest matters here rather than being
         decoration: web push only works on iOS once a site has been added to
         the Home Screen, and push is how a customer hears that her clothes
         have moved on. --}}
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    {{-- Preconnect before the first image is discovered, so the TLS handshake
         to the image host is not serialised behind parsing the page. --}}
    <link rel="preconnect" href="https://images.pexels.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    {{-- Zilla Slab for display, Monda for everything else. A slab serif carries
         at display size on a cheap screen, where a high-contrast didone's
         hairlines simply vanish. Tight weight lists: two families, six faces. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Monda:wght@400..700&family=Zilla+Slab:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('css/design-system.css') }}">
    <link rel="stylesheet" href="{{ asset('css/public.css') }}">

    {{-- Stamped before the first paint so there is no flash of the wrong theme.
         Inline and tiny, because a separate request here would defeat it. --}}
    <script>
        try {
            var t = localStorage.getItem('rc-theme');
            if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {}
    </script>
</head>
<body>

<header class="masthead" data-masthead>
    <div class="wrap">
        <a href="/" class="brand">
            <img src="{{ asset('brand/mark.svg') }}" width="34" height="34" alt="" aria-hidden="true">
            <span>Rachels Closet</span>
        </a>

        <nav data-nav>
            <a href="/#how">How it works</a>
            <a href="/#lookbook">Lookbook</a>
            <a href="{{ route('directory') }}">Find a tailor</a>
            <a href="{{ $appUrl }}/join">Join</a>
            {{-- The way into the app. The public site had none: a tailor who
                 already had an account could only reach it by typing the
                 address. --}}
            <a class="nav-signin" href="{{ $appUrl }}/sign-in">Sign in</a>
        </nav>

        <div class="actions">
            <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch light or dark">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8"/>
                </svg>
            </button>

            <button type="button" class="icon-btn nav-toggle" data-nav-toggle aria-expanded="false" aria-label="Menu">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </button>
        </div>
    </div>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="footer">
    <div class="wrap">
        <div class="footer__grid">
            <div>
                <p class="brand">
                    <img src="{{ asset('brand/mark.svg') }}" width="30" height="30" alt="" aria-hidden="true">
                    <span>Rachels Closet</span>
                </p>
                <p style="font-size:.875rem;max-width:34ch">
                    A tailor should not have to be chased for an answer, and a customer
                    should not have to walk across town to get one.
                </p>
            </div>

            <div>
                <h4>Explore</h4>
                <ul>
                    <li><a href="/#lookbook">Lookbook</a></li>
                    <li><a href="{{ route('directory') }}">Find a tailor</a></li>
                    <li><a href="/#how">How it works</a></li>
                    <li><a href="{{ route('about') }}">About us</a></li>
                </ul>
            </div>

            <div>
                <h4>For tailors</h4>
                <ul>
                    <li><a href="{{ $appUrl }}/join?as=tailor">Join the house</a></li>
                    <li><a href="{{ $appUrl }}/sign-in">Sign in</a></li>
                </ul>
            </div>

            <div>
                <h4>Contact</h4>
                {{-- A way to actually reach somebody: the help line from admin
                     Settings, tap to call or to WhatsApp. It used to be a domain
                     name and a country. --}}
                <ul>
                    <li><a href="{{ route('contact') }}">Contact us</a></li>
                    @if ($supportPhone)
                        <li><a href="tel:{{ $supportPhone }}">Call {{ $supportPhone }}</a></li>
                        <li><a href="{{ $supportWhatsapp }}" rel="noopener">WhatsApp us</a></li>
                    @endif
                    <li>Nigeria</li>
                </ul>
            </div>
        </div>

        <div class="footer__base">
            <span>&copy; {{ date('Y') }} Rachels Closet</span>
            <span class="footer__legal">
                <a href="{{ route('privacy') }}">Privacy</a>
                <a href="{{ route('terms') }}">Terms</a>
            </span>
        </div>
    </div>
</footer>

<script src="{{ asset('js/motion.js') }}" defer></script>
</body>
</html>
