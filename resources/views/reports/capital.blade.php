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

        {{-- 🧾 자산 = 자본 + 부채 (대표 2026-11-02 «총 미수금 밑에 총 운영 자금이 있어야 자금 관계가 명확») --}}
        @php
            $delta = $cmp['delta'] ?? [];
            $dl = function ($k) use ($delta, $eok) {
                $v = $delta[$k] ?? null;
                if ($v === null) return '';
                $cls = $v > 0 ? 'dpos' : ($v < 0 ? 'dneg' : 'dzero');
                return '<span class="d '.$cls.'">'.($v > 0 ? '+' : '').$eok($v).'</span>';
            };
            $frozen = $d['receivable_frozen_krw'];
            $equity = (int) $d['working_capital_krw'];
        @endphp
        <style>
            .bs .row { padding:6px 0; font-size:14.5px; }
            .bs .row .v { white-space:nowrap; }
            .bs .d { display:inline-block; min-width:62px; text-align:right; font-size:12px; font-weight:500; margin-left:8px; }
            .bs .dpos { color:#047857; } .bs .dneg { color:#dc2626; } .bs .dzero { color:#9ca3af; }
            .bs .sub { padding-left:12px; font-size:12.5px; color:#9ca3af; }
            .bs .sum { border-top:1px solid #e5e7eb; margin-top:4px; padding-top:8px; font-weight:700; }
            .bs .grp { font-size:11px; color:#9ca3af; letter-spacing:.04em; margin:12px 0 2px; }
            .bs .eq { text-align:center; color:#9ca3af; font-size:13px; margin:10px 0 2px; }
        </style>
        <div class="card bs">
            <div class="cap">자산 = 자본 + 부채</div>
            <div class="row" style="padding-top:0;">
                <span class="k">총자산</span>
                <span class="lead" style="font-size:24px;">{{ $eok($d['total_assets_krw']) }}{!! $dl('total_assets_krw') !!}</span>
            </div>
            @if ($cmp)
                <div style="color:#9ca3af;font-size:12px;margin-bottom:4px;">
                    오른쪽 작은 숫자 = {{ $cmp['prev_date']->format('n월 j일') }} 보고 대비 변동 ({{ $cmp['days'] }}일)
                </div>
            @endif

            <div class="grp">자산 — 돈이 어떤 형태로 있나</div>
            <div class="row"><span class="k">통장 현금</span><span class="v">{{ $eok($d['cash_krw']) }}{!! $dl('cash_krw') !!}</span></div>
            <div class="row"><span class="k">안 팔린 차 (재고)</span><span class="v">{{ $eok($d['unsold_inventory_krw'] ?? $d['inventory_krw']) }}{!! $dl('unsold_inventory_krw') !!}</span></div>
            @if ($frozen !== null)
                <div class="row"><span class="k">받을 돈 (미수)</span><span class="v">{{ $eok($frozen) }}{!! $dl('receivable_frozen_krw') !!}</span></div>
                <div class="row sub"><span>환율 재평가 (오늘 환율 − 판매환율)</span><span class="v">{{ ($d['fx_revaluation_krw'] >= 0 ? '+' : '') }}{{ $eok($d['fx_revaluation_krw']) }}</span></div>
            @else
                <div class="row"><span class="k">받을 돈 (미수)</span><span class="v">{{ $eok($d['receivable_krw']) }}{!! $dl('receivable_krw') !!}</span></div>
            @endif
            @if ((int) $d['auction_deposit_krw'] > 0 || ($delta['auction_deposit_krw'] ?? 0))
                <div class="row"><span class="k">경매장에 맡긴 돈</span><span class="v">{{ $eok($d['auction_deposit_krw']) }}{!! $dl('auction_deposit_krw') !!}</span></div>
            @endif
            <div class="row sum"><span class="k">자산 합계</span><span class="v">{{ $eok($d['total_assets_krw']) }}{!! $dl('total_assets_krw') !!}</span></div>

            <div class="eq">=</div>

            <div class="grp">부채 — 남의 돈</div>
            <div class="row"><span class="k">거래처에 줄 돈 (매입·운임·정산)</span><span class="v">{{ $eok($d['payable_krw']) }}{!! $dl('payable_krw') !!}</span></div>
            @if ((int) $d['advance_krw'] > 0 || ($delta['advance_krw'] ?? 0))
                <div class="row"><span class="k">빌린 돈 (가수금)</span><span class="v">{{ $eok($d['advance_krw']) }}{!! $dl('advance_krw') !!}</span></div>
            @endif
            <div class="row sum"><span class="k">부채 합계</span><span class="v">{{ $eok($d['liabilities_krw']) }}{!! $dl('liabilities_krw') !!}</span></div>

            <div class="grp">자본 — 내 돈 (자산 − 부채)</div>
            @if ($principal !== null)
                <div class="row"><span class="k">넣은 돈 (투입원금)</span><span class="v">{{ $eok($principal) }}{!! $dl('principal_krw') !!}</span></div>
                <div class="row"><span class="k">번 돈 (받을 돈 다 받았을 때)</span><span class="v">{{ $eok($d['net_profit_krw']) }}{!! $dl('net_profit_krw') !!}</span></div>
            @endif
            <div class="row sum"><span class="k">자본 합계</span><span class="v">{{ $eok($equity) }}{!! $dl('working_capital_krw') !!}</span></div>

            @if ($cmp)
                @php $da = (int) ($delta['total_assets_krw'] ?? 0); $dd = (int) ($delta['liabilities_krw'] ?? 0); $de = (int) ($delta['working_capital_krw'] ?? 0); @endphp
                <div style="margin-top:12px;padding:10px 12px;background:#f5f3ff;border-radius:8px;font-size:13px;color:#4c3fb1;">
                    {{ $cmp['prev_date']->format('n월 j일') }} 이후 총자산 {{ ($da >= 0 ? '+' : '') . $eok($da) }}
                    = 부채 {{ ($dd >= 0 ? '+' : '') . $eok($dd) }} + 내 돈 {{ ($de >= 0 ? '+' : '') . $eok($de) }}
                    <div style="color:#6b7280;font-size:12px;margin-top:4px;line-height:1.5;">
                        외부에서 돈을 넣거나 빼지 않았다면 「내 돈」 변동이 이 기간에 번(잃은) 돈입니다.
                        미수를 받으면 받을 돈이 줄고 통장 현금이 같은 만큼 늘어 총자산은 그대로이고,
                        거래처에 갚으면 현금과 부채가 같이 줄어 총자산은 줄어도 내 돈은 그대로입니다.
                    </div>
                </div>
            @else
                <div style="color:#9ca3af;font-size:12px;margin-top:8px;">7일 이상 전 통장 잔액 기록이 생기면 지난 보고 대비 변동이 같이 보입니다.</div>
            @endif
        </div>

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
            <div class="row"><span class="k">받을 돈까지 합친 가치</span><span class="v">{{ $won($d['working_capital_krw']) }}</span></div>
            <hr class="hr">
            {{-- 주 지표 = 받을 돈을 정상 회수했을 때. 청산 기준만 보여주면 "미수 전액 손실" 가정이라
                 실제보다 훨씬 나쁘게 읽힌다(jin 2026-07-31). --}}
            @php $netProfit = $d['net_profit_krw'] ?? $profit; @endphp
            <div class="row">
                <span class="k">번 돈</span>
                <span class="big {{ ($netProfit ?? 0) >= 0 ? 'pos' : 'neg' }}">
                    @if ($netProfit === null) 원금 미설정 @else {{ $eok($netProfit) }} @endif
                </span>
            </div>
            @if ($netProfit !== null)
                <div style="color:#9ca3af;font-size:12px;margin-top:4px;">받을 돈을 다 받았을 때 기준입니다.</div>
            @endif
            @if ($profit !== null)
                <div style="margin-top:10px;padding:8px 10px;background:#f9fafb;border-radius:6px;">
                    <div class="row" style="font-size:13px;">
                        <span class="k" style="color:#9ca3af;">지금 접으면 (받을 돈 못 받는다고 보면)</span>
                        <span class="v {{ $profit >= 0 ? '' : 'neg' }}">{{ $eok($profit) }}</span>
                    </div>
                    <div style="color:#9ca3af;font-size:11.5px;margin-top:3px;">
                        한국에 있는 것만 세고 바이어에게 받을 돈은 0으로 본 값입니다.
                        차이 {{ $eok(abs($netProfit - $profit)) }} 이 바이어에게 걸린 몫입니다.
                    </div>
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
