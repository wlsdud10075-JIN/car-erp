<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Signed · {{ $contract->contract_no }}</title>
    <style>
        body { background:#f3f4f6; margin:0; font-family:system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif; color:#111827; }
        .wrap { max-width:520px; margin:0 auto; padding:32px 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px; text-align:center; }
        .tick { width:56px; height:56px; border-radius:50%; background:#dcfce7; color:#16a34a; font-size:30px; line-height:56px; margin:0 auto 12px; }
        h1 { font-size:19px; margin:0 0 6px; }
        p { color:#6b7280; font-size:14px; line-height:1.6; margin:6px 0; }
        b { color:#111827; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="tick">✓</div>
        <h1>Signed · 서명 완료</h1>
        <p>Sales contract <b>{{ $contract->contract_no }}</b> has been electronically signed.</p>
        <p>판매계약서 <b>{{ $contract->contract_no }}</b> 전자서명이 완료되었습니다.</p>
        @if($contract->recipient_email)
            <p>A signed copy has been sent to <b>{{ $contract->recipient_email }}</b>.<br>서명본이 이메일로 전송되었습니다.</p>
        @endif
    </div>
</div>
</body>
</html>
