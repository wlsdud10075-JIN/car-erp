@php
    $eok = function ($v) {
        if ($v === null) return '-';
        $sign = $v < 0 ? '-' : '';
        $abs = abs($v);
        return $abs >= 100000000
            ? $sign.number_format($abs / 100000000, 2).'억'
            : $sign.number_format($abs);
    };
    $won = fn ($v) => $v === null ? '-' : number_format((int) $v).'원';
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>자금 보고</title>
    <style>
        body { background:#f3f4f6; margin:0; font-family:system-ui,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif; color:#111827; }
        .wrap { max-width:520px; margin:0 auto; padding:16px 16px 40px; }
        h1 { font-size:19px; margin:0 0 2px; }
        .sub { color:#6b7280; font-size:13px; margin:0 0 14px; }
        .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:12px; }
        .cap { font-size:11px; letter-spacing:.04em; color:#9ca3af; text-transform:uppercase; margin-bottom:10px; }
        .row { display:flex; justify-content:space-between; align-items:baseline; padding:7px 0; font-size:15px; }
        .row .k { color:#6b7280; } .row .v { font-weight:600; }
        .hr { border:0; border-top:1px dashed #e5e7eb; margin:8px 0; }
        .big { font-size:24px; font-weight:800; letter-spacing:-.02em; }
        .big.pos { color:#047857; } .big.neg { color:#dc2626; }
        .lead { font-size:28px; font-weight:800; letter-spacing:-.03em; color:#4c3fb1; }
        details { border-top:1px solid #f3f4f6; }
        details:first-of-type { border-top:0; }
        summary { display:flex; justify-content:space-between; align-items:center; cursor:pointer; padding:11px 0; font-size:15px; list-style:none; }
        summary::-webkit-details-marker { display:none; }
        summary .k { color:#374151; }
        summary .k::before { content:'▸ '; color:#c4b5fd; }
        details[open] summary .k::before { content:'▾ '; }
        summary .v { font-weight:600; }
        .neg { color:#dc2626; }
        .detail { padding:2px 0 12px 14px; }
        .drow { display:flex; justify-content:space-between; padding:5px 0; font-size:13.5px; color:#6b7280; }
        .drow .dv { color:#374151; }
        .note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:8px; padding:10px 12px; font-size:13px; margin-bottom:12px; }
        .empty { background:#fff; border-radius:12px; padding:32px 16px; text-align:center; color:#9ca3af; font-size:14px; }
        .foot { color:#9ca3af; font-size:12px; text-align:center; margin-top:18px; line-height:1.6; }
    </style>
</head>
<body>
<div class="wrap">

    @if (! ($d['has_data'] ?? false))
        <h1>자금 보고</h1>
        <p class="sub">{{ $asOf }} 기준</p>
        <div class="empty">아직 통장 잔액이 입력되지 않았습니다.<br>입력 후 다시 열어주세요.</div>
    @else
        @php
            $principal = $d['principal_krw'];
            $profit = $d['profit_krw'];
            $debt = (int) $d['payable_krw'] + (int) $d['advance_krw'];
        @endphp

        <h1>자금 보고</h1>
        <p class="sub">{{ $d['date']->format('Y년 n월 j일') }} 기준</p>

        @if ($stale)
            <div class="note">
                {{ $asOf }} 에는 통장 잔액 입력이 없어, 가장 최근 입력분({{ $d['date']->format('Y-m-d') }})으로 보여드립니다.
            </div>
        @endif

        {{-- ① 원금 대비 얼마나 벌었나 --}}
        <div class="card">
            <div class="cap">내 돈이 얼마나 불었나</div>
            <div class="row"><span class="k">넣은 돈 (투입원금)</span><span class="v">{{ $won($principal) }}</span></div>
            {{-- 대표가 나중에 넣은 돈(자산성 가수금)도 밑천이라 원금에 합산된다 — 안 보여주면 설정값과 달라 보인다. --}}
            @if ((int) ($d['principal_breakdown']['owner_advance_krw'] ?? 0) > 0)
                <div class="row" style="padding-left:10px;font-size:12.5px;color:#9ca3af;">
                    <span>처음 넣은 돈</span><span class="dv">{{ $won($d['principal_breakdown']['base_krw']) }}</span>
                </div>
                @foreach ($ownerAdvances as $a)
                    <div class="row" style="padding-left:10px;font-size:12.5px;color:#9ca3af;">
                        <span>{{ $a->company_name }} (나중에 넣음)</span><span class="dv">{{ $won($a->amount) }}</span>
                    </div>
                @endforeach
            @endif
            <div class="row"><span class="k">지금 가치 (청산가치)</span><span class="v">{{ $won($d['liquidation_krw']) }}</span></div>
            <hr class="hr">
            <div class="row">
                <span class="k">번 돈</span>
                <span class="big {{ ($profit ?? 0) >= 0 ? 'pos' : 'neg' }}">
                    @if ($profit === null) 원금 미설정 @else {{ $eok($profit) }} @endif
                </span>
            </div>
            @if ($principal !== null && $principal > 0 && $profit !== null)
                <div style="color:#9ca3af;font-size:12px;margin-top:6px;">
                    원금의 {{ number_format($d['liquidation_krw'] / $principal, 1) }}배
                </div>
            @endif
        </div>

        {{-- ② 지금 정리하면 손에 쥐는 돈 --}}
        <div class="card">
            <div class="cap">지금 정리하면 손에 쥐는 돈</div>
            <div class="lead">{{ $eok($d['liquidation_krw']) }}</div>
            <div style="height:8px"></div>

            <details>
                <summary><span class="k">통장 현금</span><span class="v">{{ $eok($d['cash_krw']) }}</span></summary>
                <div class="detail">
                    <div class="drow"><span>원화</span><span class="dv">{{ $won($d['balance_krw']) }}</span></div>
                    <div class="drow"><span>달러 (× {{ number_format((float) ($d['fx_usd'] ?? 0)) }})</span><span class="dv">${{ number_format($d['balance_usd'], 2) }}</span></div>
                    <div class="drow"><span>유로 (× {{ number_format((float) ($d['fx_eur'] ?? 0)) }})</span><span class="dv">€{{ number_format($d['balance_eur'], 2) }}</span></div>
                </div>
            </details>

            <details>
                <summary><span class="k">차에 묶인 돈 (재고)</span><span class="v">{{ $eok($d['inventory_krw']) }}</span></summary>
                <div class="detail">
                    <div class="drow"><span>아직 배를 타지 않아 한국에 있는 차들의 매입가 합계입니다.</span><span></span></div>
                </div>
            </details>

            {{-- 선적 전인데 이미 받은 대금 — 차를 되팔면 돌려줘야 하므로 자산에서 뺀다(2026-07-31). --}}
            @if ((int) ($d['advance_payment_krw'] ?? 0) > 0)
                <details>
                    <summary><span class="k">미리 받은 차값</span><span class="v neg">−{{ $eok($d['advance_payment_krw']) }}</span></summary>
                    <div class="detail">
                        <div class="drow"><span>아직 안 실은 차의 대금을 먼저 받은 금액입니다. 차를 되팔면 돌려줘야 해서 뺍니다.</span><span></span></div>
                    </div>
                </details>
            @endif

            @if ((int) $d['auction_deposit_krw'] > 0)
                <details>
                    <summary><span class="k">경매장에 맡긴 돈</span><span class="v">{{ $eok($d['auction_deposit_krw']) }}</span></summary>
                    <div class="detail">
                        @foreach ($deposits as $dep)
                            <div class="drow"><span>{{ $dep->auction_house }}</span><span class="dv">{{ $won($dep->amount) }}</span></div>
                        @endforeach
                    </div>
                </details>
            @endif

            <details>
                <summary><span class="k">갚아야 할 돈</span><span class="v neg">−{{ $eok($debt) }}</span></summary>
                <div class="detail">
                    <div class="drow"><span>거래처에 줄 돈 (매입·운임·정산)</span><span class="dv">{{ $won($d['payable_krw']) }}</span></div>
                    @if ((int) $d['advance_krw'] > 0)
                        <div class="drow" style="padding-top:8px;"><span>빌린 돈 (가수금)</span><span class="dv">{{ $won($d['advance_krw']) }}</span></div>
                        @foreach ($advances as $a)
                            <div class="drow" style="padding-left:10px;font-size:12.5px;">
                                <span>{{ $a->company_name }}</span><span class="dv">{{ $won($a->amount) }}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </details>
        </div>

        {{-- ③ 아직 못 받은 돈 --}}
        <div class="card">
            <div class="cap">아직 못 받은 돈</div>
            <div class="lead">{{ $eok($d['receivable_krw']) }}</div>
            <div class="row" style="margin-top:6px;">
                <span class="k">다 받으면 굴리는 자금</span>
                <span class="v">{{ $eok($d['working_capital_krw']) }}</span>
            </div>
            <div style="color:#9ca3af;font-size:12px;margin-top:4px;">
                통장 + 아직 안 팔린 차 + 받을 돈 − 갚을 돈
            </div>
        </div>

        <div class="foot">
            통장 잔액을 입력한 시점의 값입니다.<br>
            이 링크는 발급 후 {{ \App\Http\Controllers\CapitalReportController::LINK_TTL_DAYS }}일이 지나면 열리지 않습니다.
        </div>
    @endif

</div>
</body>
</html>
