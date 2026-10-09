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
        .drill { border-bottom:1px dashed #eee; }
        .drill:last-child { border-bottom:0; }
        .drill > summary { display:flex; justify-content:space-between; align-items:center; gap:8px;
            padding:9px 0; font-size:14px; cursor:pointer; list-style:none; }
        .drill > summary::-webkit-details-marker { display:none; }
        .drill > summary::before { content:"▸"; color:#9ca3af; font-size:11px; margin-right:6px; }
        .drill[open] > summary::before { content:"▾"; }
        .drill > summary .k { color:#374151; font-weight:600; flex:1; }
        .drill > summary .v { color:#111827; font-weight:600; white-space:nowrap; }
        .drill .adj { font-size:11px; font-weight:600; margin-left:4px; }
        .drill .adj.minus { color:#dc2626; } .drill .adj.plus { color:#059669; }
        .veh { padding:2px 0 10px 18px; }
        .veh .row { padding:4px 0; border-bottom:0; font-size:13px; }
        .veh .row .k { color:#6b7280; } .veh .row .v { color:#4b5563; font-weight:500; }
        .loss { color:#dc2626; }
        .veh .row .k .meta { display:block; color:#9ca3af; font-weight:400; font-size:11px; margin-top:2px; }
        .drill > summary .k .rate { color:#9ca3af; font-weight:400; font-size:11px; margin-left:6px; }
        .pay { margin:2px 0 6px 18px; border:1px solid #f3f4f6; border-radius:8px; padding:4px 10px; }
        .pay .row { padding:3px 0; border-bottom:0; font-size:13px; }
        .pay .row .k { color:#6b7280; } .pay .row .v { color:#4b5563; font-weight:500; }
        .pay .row.sum { border-top:1px solid #f3f4f6; }
        .pay .row.sum .k, .pay .row.sum .v { color:#111827; font-weight:700; }
        .xlsx { display:block; margin-top:10px; padding:11px 12px; border:1px solid #d1d5db; border-radius:8px;
                text-align:center; color:#374151; font-size:13px; font-weight:600; text-decoration:none; }
        .xlsx:hover { background:#f9fafb; }
        .profit .row.big { font-size:17px; padding-top:10px; }
        .profit .row.big .v { color:#047857; }
        .profit .row.big .v.loss { color:#dc2626; }
        .profit .cap { color:#9ca3af; font-size:12px; margin-top:8px; line-height:1.5; }
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
        {{-- 💰 기본급은 지급 총액 밖이다 — 승인하는 숫자는 위의 「지급 총액」 그대로고,
             아래는 통장에서 나갈 돈을 한 번에 보기 위한 참고치다. --}}
        {{-- 🧾 월정산 v3 — 급여까지 포함한 송금 총액과 급여 차감 후 회사 순이익. 승인하는 숫자는 위 「지급 총액」 그대로. --}}
        @isset($v3)
        <div class="row"><span class="k">송금 총액 (급여 포함)</span><span class="v">{{ number_format($v3['totals']['transfer_total']) }}원</span></div>
        <div class="row"><span class="k">회사 순이익 (급여 차감 후)</span><span class="v {{ $v3['totals']['company_net'] < 0 ? 'loss' : '' }}">{{ number_format($v3['totals']['company_net']) }}원</span></div>
        @endisset
    </div>

    @isset($v3)
    <div class="card">
        <div class="sub" style="margin-bottom:8px;">담당자별 실지급 <span style="color:#9ca3af;font-weight:400;">— 이름을 누르면 계산 단계</span></div>
        {{-- 🧾 월정산 v3 — ERP 월정산 화면과 **같은 카드**(components/payout/person-card). JS 없이 <details> 로 펼친다. --}}
        <div style="display:grid;gap:8px;">
            @foreach($v3['people'] as $person)
            <x-payout.person-card :person="$person" mode="batch" />
            @endforeach
        </div>
        {{-- 전체 항목(25열)이 필요하면 엑셀로 (jin 2026-08-04). 서명 링크라 로그인 없이 받는다. --}}
        @isset($exportUrl)
        <a class="xlsx" href="{{ $exportUrl }}">📄 전체 내역 엑셀 내려받기</a>
        @endisset
    </div>
    @endisset

    @if($batch->adjustments->isNotEmpty())
    <div class="card">
        <div class="sub" style="margin-bottom:8px;">월배치 조정</div>
        @foreach($batch->adjustments as $adj)
        <div class="row bd">
            <span class="k">{{ $adj->salesman?->name ?? '-' }} · {{ $adj->reason }}</span>
            <span class="v {{ $adj->amount < 0 ? 'loss' : '' }}">{{ $adj->amount < 0 ? '−' : '+' }}{{ number_format(abs($adj->amount)) }}원</span>
        </div>
        @endforeach
    </div>
    @endif

    @isset($profit)
    <div class="card profit">
        <div class="sub" style="margin-bottom:8px;">회사이익</div>
        <div class="row"><span class="k">총마진</span><span class="v">{{ number_format($profit['total_margin']) }}원</span></div>
        @isset($profit['margin_rate'])
        <div class="row"><span class="k">마진율</span><span class="v">{{ \App\Models\Settlement::formatMarginRate($profit['margin_rate']) }}</span></div>
        @endisset
        <div class="row"><span class="k">직원 지급총액</span><span class="v">− {{ number_format($profit['payout']) }}원</span></div>
        @if($profit['fx'] !== 0)
        <div class="row"><span class="k">환차</span><span class="v">{{ $profit['fx'] >= 0 ? '+' : '−' }} {{ number_format(abs($profit['fx'])) }}원</span></div>
        @endif
        <div class="row big"><span class="k">회사이익</span><span class="v {{ $profit['company_profit'] < 0 ? 'loss' : '' }}">{{ number_format($profit['company_profit']) }}원</span></div>
        @isset($v3)
        <div class="row"><span class="k">회사 순이익 (급여 차감 후)</span><span class="v {{ $v3['totals']['company_net'] < 0 ? 'loss' : '' }}">{{ number_format($v3['totals']['company_net']) }}원</span></div>
        @endisset
        <div class="cap">총마진에서 직원 실지급{{ $profit['fx'] !== 0 ? '·환차' : '' }}을(를) 뺀 회사 몫입니다.</div>
    </div>
    @endisset

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
