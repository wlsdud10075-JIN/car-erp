<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>정산 지급 승인</title>
    @vite(['resources/css/app.css'])
    <style>
        body { background:#f3f4f6; margin:0; font-family:system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:12px; }
        .row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #eee; font-size:15px; }
        .row:last-child { border-bottom:0; }
        .row .k { color:#6b7280; } .row .v { font-weight:600; color:#111827; }
        .total { font-size:18px; }
        .total .v { color:#4c3fb1; }
        h1 { font-size:18px; margin:0 0 4px; color:#111827; }
        .sub { color:#6b7280; font-size:13px; margin:0 0 14px; }
        textarea { width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-size:15px; min-height:72px; }
        .btn { display:block; width:100%; box-sizing:border-box; border:0; border-radius:10px; padding:14px; font-size:16px; font-weight:700; cursor:pointer; margin-top:10px; }
        .approve { background:#4c3fb1; color:#fff; }
        .reject { background:#fff; color:#dc2626; border:1px solid #fecaca; }
        .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:8px; padding:10px; font-size:14px; margin-bottom:12px; }
        .notice { background:#f9fafb; color:#6b7280; border-radius:8px; padding:14px; font-size:14px; text-align:center; }
        .bd { font-size:14px; color:#374151; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>정산 지급 승인 요청</h1>
        <p class="sub">{{ $batch->month }} 귀속 · 제출: {{ $batch->submitter?->name ?? '-' }}</p>
        <div class="row"><span class="k">귀속월</span><span class="v">{{ $batch->month }}</span></div>
        <div class="row"><span class="k">건수</span><span class="v">{{ number_format($batch->settlement_count) }}건</span></div>
        <div class="row total"><span class="k">지급 총액</span><span class="v">{{ number_format($batch->total_payout) }}원</span></div>
    </div>

    @if(!empty($breakdown))
    <div class="card">
        <div class="sub" style="margin-bottom:8px;">담당자별 실지급</div>
        @foreach($breakdown as $name => $amt)
        <div class="row bd"><span class="k">{{ $name }}</span><span class="v">{{ number_format($amt) }}원</span></div>
        @endforeach
    </div>
    @endif

    @if($decidable && $decideUrl)
    <div class="card">
        @if($error)<div class="err">{{ $error }}</div>@endif
        <form method="POST" action="{{ $decideUrl }}">
            <button type="submit" name="action" value="approve" class="btn approve"
                    onclick="return confirm('이 배치({{ number_format($batch->total_payout) }}원)를 승인하고 지급 처리할까요?')">
                승인하고 지급 처리
            </button>
            <div style="margin-top:16px;">
                <textarea name="reason" placeholder="반려 사유 (반려 시 필수)"></textarea>
                <button type="submit" name="action" value="reject" class="btn reject"
                        onclick="return confirm('이 배치를 반려할까요? 제출자에게 사유가 전달됩니다.')">
                    반려
                </button>
            </div>
        </form>
    </div>
    @else
    <div class="card">
        <div class="notice">
            @php
                $label = match($batch->status) {
                    'approved' => '이미 승인 완료된 배치입니다.',
                    'rejected' => '이미 반려된 배치입니다.',
                    default => '현재 이 링크로 처리할 수 있는 단계가 아닙니다(다른 승인자 차례이거나 이미 처리됨).',
                };
            @endphp
            {{ $label }}
        </div>
    </div>
    @endif
</div>
</body>
</html>
