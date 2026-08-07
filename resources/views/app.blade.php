<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#16a34a">
    <title>{{ config('app.name', 'WhatsFlow') }}</title>
    <script>
        // Locale + theme live in localStorage — apply before paint to avoid FOUC.
        (function () {
            try {
                var locale = localStorage.getItem('locale') === 'ar' ? 'ar' : 'en';
                document.documentElement.lang = locale;
                document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';

                var stored = localStorage.getItem('theme');
                var theme = stored === 'dark' || stored === 'light'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', theme === 'dark');
                document.documentElement.style.colorScheme = theme;
            } catch (e) {
                document.documentElement.dir = 'ltr';
            }
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <style>
        #root:empty + #splash { display: flex; }
        #splash {
            display: none;
            position: fixed;
            inset: 0;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
        }
        html.dark #splash { background: #101412; }
        #splash .mark {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #16a34a;
            color: #fff;
            font: 700 20px/44px ui-sans-serif, system-ui, sans-serif;
            text-align: center;
            animation: splash-pulse 1.1s ease-in-out infinite;
        }
        @keyframes splash-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.55; transform: scale(0.94); }
        }
    </style>
</head>
<body class="antialiased font-sans bg-canvas">
    <div id="root"></div>
    <div id="splash" aria-hidden="true"><div class="mark">W</div></div>
</body>
</html>
