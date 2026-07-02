<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · SSANCAR ERP</title>
    <style>
        :root { --primary: #7c6fcd; --primary-text: #4c3fb1; --ink: #1f2937; --muted: #6b7280; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Pretendard', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Apple SD Gothic Neo', 'Malgun Gothic', sans-serif;
            background: #f5f4fb; color: var(--ink);
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .wrap {
            background: #fff; border: 1px solid #ece9f8; border-radius: 16px;
            padding: 40px 32px; max-width: 440px; width: 100%; text-align: center;
            box-shadow: 0 10px 30px rgba(76, 63, 177, 0.08);
        }
        .code { font-size: 64px; font-weight: 800; line-height: 1; color: var(--primary); letter-spacing: -2px; }
        h1 { font-size: 20px; font-weight: 700; margin: 16px 0 8px; }
        p { font-size: 14px; color: var(--muted); margin: 0; line-height: 1.6; }
        p.detail { margin-top: 12px; font-size: 13px; color: #9ca3af; }
        .btn {
            display: inline-block; margin-top: 24px; padding: 10px 24px;
            background: var(--primary); color: #fff; text-decoration: none;
            border-radius: 10px; font-size: 14px; font-weight: 600; transition: background .15s;
        }
        .btn:hover { background: #6b5dbd; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        @hasSection('detail')
            <p class="detail">@yield('detail')</p>
        @endif
        <a href="{{ url('/') }}" class="btn">{{ __('errors.home') }}</a>
    </div>
</body>
</html>
