<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        {{-- WayneUI from its documented static path: the stylesheet and the
             theme script. The fonts, marks and icon sprite follow from these
             two tags. The nonce comes from HELMET when its middleware ran;
             without it the tags still load, they just skip the CSP nonce. --}}
        @php($cspNonce = request()->attributes->get('helmet.csp_nonce'))
        <link rel="stylesheet" href="https://wayneui.apps.wayne.edu/v2/wayne-ui.css">
        <script type="module" src="https://wayneui.apps.wayne.edu/v2/wayne-ui-theme.js" @if($cspNonce) nonce="{{ $cspNonce }}" @endif></script>
    </head>
    <body>
        @php($signedInUser = app(\Waynestate\Sesame\Sesame::class)->user())

        <div class="wsu-shell">
            <a class="wsu-skip-link" href="#main">Skip to main content</a>

            <header class="wsu-app-bar wsu-app-bar--compact">
                <div class="wsu-app-bar__inner">
                    <a class="wsu-app-bar__brand" href="{{ route('home') }}">{{ config('app.name', 'Laravel') }}</a>

                    <nav class="wsu-app-bar__nav" aria-label="Primary">
                        <a class="wsu-app-bar__item" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Home</a>
                        <a class="wsu-app-bar__item" href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
                    </nav>

                    <div class="wsu-app-bar__end">
                        @if($signedInUser)
                            <span class="wsu-app-bar__user">{{ $signedInUser->name }}</span>
                        @endif
                    </div>
                </div>
            </header>

            <main id="main" tabindex="-1">
                @yield('content')
            </main>

            <footer class="wsu-footer">
                <div class="wsu-footer__inner">
                    <p class="wsu-footer__meta">{{ config('app.name', 'Laravel') }} &middot; Wayne State University</p>
                    <nav class="wsu-footer__links" aria-label="Footer">
                        <a href="https://wayne.edu/">wayne.edu</a>
                        <a href="https://wayne.edu/accessibility/">Accessibility</a>
                        <a href="https://wayne.edu/policies">Policies</a>
                    </nav>
                </div>
            </footer>
        </div>
    </body>
</html>
