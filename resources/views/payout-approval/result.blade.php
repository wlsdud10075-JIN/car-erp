<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>정산 지급 승인 결과</title>
    <style>
        body { background:#f3f4f6; margin:0; font-family:system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif; }
        .wrap { max-width:520px; margin:0 auto; padding:32px 16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:28px 20px; text-align:center; }
        .ico { font-size:44px; margin-bottom:10px; }
        h1 { font-size:20px; margin:0 0 8px; color:#111827; }
        p { color:#6b7280; font-size:15px; margin:4px 0; line-height:1.5; }
        .reason { background:#f9fafb; border-radius:8px; padding:12px; margin-top:12px; font-size:14px; color:#374151; text-align:left; }
        .ok { color:#059669; } .no { color:#dc2626; } .muted { color:#6b7280; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        @switch($result)
            @case('approved')
                <div class="ico ok">✅</div>
                <h1 class="ok">승인 완료</h1>
                <p>{{ $batch->month }} 귀속 정산({{ number_format($batch->total_payout) }}원)이 승인되어 지급 처리되었습니다.</p>
                @break
            @case('rejected')
                <div class="ico no">↩️</div>
                <h1 class="no">반려 완료</h1>
                <p>{{ $batch->month }} 귀속 정산을 반려했습니다. 제출자에게 사유가 전달됩니다.</p>
                @if($message)<div class="reason">사유: {{ $message }}</div>@endif
                @break
            @case('already')
                <div class="ico muted">ℹ️</div>
                <h1 class="muted">처리 불가</h1>
                <p>{{ $message ?: '이미 처리되었거나 승인 권한이 없는 배치입니다.' }}</p>
                @break
            @default
                <div class="ico no">⚠️</div>
                <h1 class="no">오류</h1>
                <p>{{ $message ?: '요청을 처리할 수 없습니다.' }}</p>
        @endswitch
        <p class="muted" style="margin-top:16px; font-size:13px;">이 창은 닫으셔도 됩니다.</p>
    </div>
</div>
</body>
</html>
