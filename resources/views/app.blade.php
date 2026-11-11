<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="theme-color" content="#f6f4ef" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
        <link rel="icon" type="image/png" sizes="64x64" href="/images/flyse-icon-64.png" />
        <link rel="apple-touch-icon" href="/images/flyse-icon-192.png" />
        <title>{{ config('app.name') }}</title>
        {{-- Apply the saved or device colour theme before first paint (no light flash in dark mode). --}}
        <script>
            (function () {
                var mode = null;
                try { mode = localStorage.getItem('ftms_theme'); } catch (e) {}
                var dark = mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) document.documentElement.classList.add('dark');
            })();
        </script>
        @viteReactRefresh
        @vite('resources/js/main.tsx')
    </head>
    <body class="antialiased">
        <div id="root"></div>
    </body>
</html>
