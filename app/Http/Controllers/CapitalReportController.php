<?php

namespace App\Http\Controllers;

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\AuditLog;
use App\Models\CashSnapshot;
use App\Services\CapitalStatusService;
use Illuminate\Http\Request;

/**
 * 대표 자금 보고 (jin 2026-07-27, 안건4 3단계).
 *
 * 로그인 없이 **서명 링크**로 열람 — 대표가 카톡으로 받은 링크를 눌러 항목을 펼쳐 보는 용도.
 *   기존 주간 알림톡(erp_capital_weekly)은 숫자만 카드로 보내고 "관리자 대시보드에서 확인"으로
 *   끝나 대표가 로그인해야 했다. 이 화면이 그 자리를 대신한다.
 *   **주간·월간 공용** — 기준일만 다르게 링크를 만들면 된다(주간 발송에도 그대로 재사용).
 *
 * ⚠️ 통장잔액·원금·손익·미수까지 회사 재무 전부가 담긴다. 링크를 쥔 사람은 누구나 본다.
 *   그래서 ① 발급 시 만료(기본 7일, LINK_TTL_DAYS) ② 열람 시 감사 로그.
 *   기존 서명 링크 관례(payout 60분·서류 3일)보다 길지만 보고 주기가 그만큼 길다.
 *
 * 기준 = 그 날짜 이하 **가장 최근** 통장잔액 입력분. 통장잔액을 매일 넣지는 않으므로
 *   실제 기준일을 화면에 밝힌다(0 을 그리지 않는다).
 */
class CapitalReportController extends Controller
{
    public const LINK_TTL_DAYS = 7;

    public function show(Request $request, string $date)
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            abort(404);
        }

        $svc = app(CapitalStatusService::class);

        $snap = CashSnapshot::whereDate('snapshot_date', '<=', $date)
            ->orderByDesc('snapshot_date')->first();

        // 열람 감사 — 로그인 없는 링크라 누가 언제 봤는지 흔적을 남긴다(user_id 는 null).
        if ($snap) {
            AuditLog::recordEvent($snap, 'capital_report_viewed');
        }

        $d = $svc->derive($snap);

        // 요청한 날짜와 실제 스냅샷 날짜가 다르면(그 사이 입력 없음) 화면에 밝힌다.
        $stale = $d['has_data'] && $snap->snapshot_date->format('Y-m-d') !== $date;

        return view('reports.capital', [
            'asOf' => $date,
            'd' => $d,
            'stale' => $stale,
            // 갚아야 할 돈만 — 대표 자산성(equity)은 부채가 아니라 투입원금으로 잡힌다(jin 2026-07-31).
            'advances' => $d['has_data']
                ? AdvanceReceipt::where('nature', AdvanceReceipt::NATURE_LIABILITY)->orderByDesc('amount')->get()
                : collect(),
            'ownerAdvances' => $d['has_data']
                ? AdvanceReceipt::where('nature', AdvanceReceipt::NATURE_EQUITY)->orderByDesc('amount')->get()
                : collect(),
            'deposits' => $d['has_data'] ? AuctionDeposit::orderByDesc('amount')->get() : collect(),
        ]);
    }
}
