<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SettlementPayoutBatch;
use App\Services\Payout\PayoutBatchExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 월정산 화면 [엑셀] (jin 2026-11-04 (나)). 승인 링크 엑셀과 같은 파일(PayoutBatchExportService).
 *
 * 받을 수 있는 사람 = 그 화면을 볼 수 있는 사람과 같게 —
 *   사내직원 월정산·퇴사 정산 = `approve` 미들웨어와 같은 canApprove() (월정산 메뉴 라우트 그룹)
 *   내수직원 월정산 = 회사 토글 ON + 지정자(canSeeDomesticPayout) 또는 지금 자기 차례 결재자 — 화면 mount 와 같은 판정.
 */
class PayoutBatchExportController extends Controller
{
    public function __invoke(Request $request, SettlementPayoutBatch $batch, PayoutBatchExportService $exporter): StreamedResponse
    {
        $user = $request->user();
        if ($batch->isDomesticBatch()) {
            abort_unless(Setting::domesticPayoutEnabled()
                && ($user->canSeeDomesticPayout() || SettlementPayoutBatch::query()->domestic()->awaitingDecisionBy($user)->exists()), 403);
        } else {
            // 🧾 2026-11-05 — 화면과 같은 판정(지정자 + 자기 차례 결재자)
            abort_unless($user->canSeeStaffPayout() || SettlementPayoutBatch::query()->notDomestic()->awaitingDecisionBy($user)->exists(), 403);
        }

        return $exporter->download($batch, $user, $request->ip());
    }
}
