<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; }
        .container { max-width: 760px; margin: 0 auto; padding: 48px 24px; }
        header a { color: #16a34a; text-decoration: none; font-weight: 600; }
        h1 { font-size: 28px; margin: 24px 0 16px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; line-height: 1.7; white-space: pre-wrap; }
        nav { margin-top: 32px; font-size: 14px; }
        nav a { color: #475569; margin-inline-end: 16px; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <header><a href="/">{{ config('app.name') }}</a></header>
    <h1>{{ $title }}</h1>
    <div class="card">{{ $content }}</div>
    <nav>
        <a href="{{ url('/legal/privacy') }}">Privacy Policy</a>
        <a href="{{ url('/legal/terms') }}">Terms of Service</a>
        <a href="{{ url('/legal/data-deletion') }}">Data Deletion</a>
        <a href="{{ url('/legal/support') }}">Support</a>
        <a href="{{ url('/legal/company') }}">Company</a>
    </nav>
</div>
</body>
</html>
