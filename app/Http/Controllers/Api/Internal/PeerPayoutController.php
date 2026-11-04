<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Salesman;
use App\Models\SettlementPayoutBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🔗 이 회사의 **승인된** 사내직원 월정산 — 사람별 실지급액 (jin 2026-11-04 «헤이맨 정산을 싼카로 가져오기»).
 *
 * ssancarerp 가 heymanerp 의 이 엔드포인트를 읽어 「헤이맨 정산금」(ExternalSettlementPay) 칸을 채운다.
 * 숫자 = 승인 배치 박제(breakdown_snapshot)의 사람별 `payout` — 실측 9월 무사백 2,800,000 = ssancarerp 수기값과 같았다.
 * 승인된 kind=month 만(결재 중·반려·퇴사 정산·내수직원 월정산 제외). 한 달에 승인 배치가 둘이면 사람별로 더한다.
 */
class PeerPayoutController extends Controller
{
    public function payouts(Request $request): JsonResponse
    {
        $month = (string) $request->query('month', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return response()->json(['error' => 'invalid_month'], 422);
        }

        $batches = SettlementPayoutBatch::monthly()->where('month', $month)
            ->where('status', SettlementPayoutBatch::STATUS_APPROVED)->orderBy('id')->get();

        $people = [];
        foreach ($batches as $b) {
            foreach ($b->breakdownForDisplay()['people'] ?? [] as $p) {
                $sid = (int) $p['salesman_id'];
                $people[$sid] ??= ['salesman_id' => $sid, 'name' => (string) $p['name'], 'type' => (string) $p['type'], 'payout' => 0];
                $people[$sid]['payout'] += (int) $p['payout'];
            }
        }
        $phones = Salesman::withTrashed()->whereIn('id', array_keys($people))->pluck('phone', 'id');
        foreach ($people as $sid => &$row) {
            $row['phone'] = $phones[$sid] ?? null;
        }
        unset($row);

        // ⏳ 아직 결재 중인 배치 — 가져오는 쪽이 「없음」과 「결재 중」을 갈라 경고한다(jin 2026-11-04 «눌러보는 사람이 꼭 있을 것»).
        $pending = SettlementPayoutBatch::monthly()->where('month', $month)
            ->where('status', SettlementPayoutBatch::STATUS_PENDING)->orderBy('id')->get()
            ->map(fn ($b) => ['id' => $b->id, 'submitted_at' => $b->submitted_at?->toDateTimeString(), 'waiting_for' => $b->isStepsMode() ? $b->currentStepLabel() : null])
            ->values();

        return response()->json([
            'month' => $month,
            'pending' => $pending,
            'batches' => $batches->map(fn ($b) => ['id' => $b->id, 'decided_at' => $b->decided_at?->toDateTimeString(), 'total_payout' => (int) $b->total_payout])->values(),
            'people' => array_values($people),
        ]);
    }
}
