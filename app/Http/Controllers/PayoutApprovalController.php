<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * 월배치 정산지급 — 대표가 카카오 알림톡 버튼으로 바로 승인/반려 (2026-07-08, jin).
 *
 * 인가 = URL 서명(`signed` 미들웨어). 로그인 없음 — 서명 링크가 (배치 id + 승인자 u + 5일 만료)로
 *   바인딩돼 인가를 대신한다. 링크는 그 배치·그 승인자 1건만 처리 가능.
 * 보안 4중: ① show(GET)=내역만 표시(상태 변경 X — 카톡 링크 프리페치가 승인 못 함) ② decide(POST)만
 *   실제 처리, 폼 action 은 페이지 진입 시 새로 발급한 만료 서명 URL(60분) ③ approveBy/rejectBy 의
 *   canDecide(status pending + 계단 일치)가 1회용·재클릭·단계 가드 ④ AuditLog(user_id·IP) 기록.
 */
class PayoutApprovalController extends Controller
{
    /** 승인 페이지 — 배치 내역 표시 + 승인/반려 폼. 상태 변경 없음. */
    public function show(Request $request, SettlementPayoutBatch $batch)
    {
        $user = User::find((int) $request->query('u'));
        $decidable = $user !== null && $batch->canDecide($user);

        $decideUrl = null;
        if ($decidable) {
            $decideUrl = URL::temporarySignedRoute('payout.approve.decide', now()->addMinutes(60), [
                'batch' => $batch->id,
                'u' => $user->id,
            ]);
        }

        return view('payout-approval.show', [
            'batch' => $batch,
            'user' => $user,
            'decidable' => $decidable,
            'decideUrl' => $decideUrl,
            'breakdown' => $this->breakdown($batch),
            'profit' => $this->profitStats($batch),
            'error' => null,
        ]);
    }

    /** 실제 승인/반려 처리 (POST). canDecide 가드가 1회용·계단·상태를 재검증. */
    public function decide(Request $request, SettlementPayoutBatch $batch)
    {
        $user = User::find((int) $request->query('u'));
        $action = (string) $request->input('action');
        $reason = trim((string) $request->input('reason', ''));

        if ($user === null) {
            return view('payout-approval.result', ['batch' => $batch, 'result' => 'error', 'message' => '유효하지 않은 승인자입니다.']);
        }

        // 반려인데 사유 없음 → 승인 페이지로 되돌려 에러 표시(상태 변경 없음).
        if ($action === 'reject' && $reason === '') {
            $decideUrl = URL::temporarySignedRoute('payout.approve.decide', now()->addMinutes(60), [
                'batch' => $batch->id, 'u' => $user->id,
            ]);

            return view('payout-approval.show', [
                'batch' => $batch, 'user' => $user, 'decidable' => $batch->canDecide($user),
                'decideUrl' => $decideUrl, 'breakdown' => $this->breakdown($batch),
                'profit' => $this->profitStats($batch),
                'error' => '반려하려면 사유를 입력해 주세요.',
            ]);
        }

        try {
            if ($action === 'approve') {
                $batch->approveBy($user);
                AuditLog::create([
                    'user_id' => $user->id, 'auditable_type' => $batch::class, 'auditable_id' => $batch->id,
                    'action' => 'payout_approved_via_link', 'ip_address' => $request->ip(),
                ]);

                return view('payout-approval.result', ['batch' => $batch, 'result' => 'approved', 'message' => null]);
            }

            if ($action === 'reject') {
                $batch->rejectBy($user, $reason);
                AuditLog::create([
                    'user_id' => $user->id, 'auditable_type' => $batch::class, 'auditable_id' => $batch->id,
                    'action' => 'payout_rejected_via_link', 'ip_address' => $request->ip(),
                ]);

                return view('payout-approval.result', ['batch' => $batch, 'result' => 'rejected', 'message' => $reason]);
            }

            return view('payout-approval.result', ['batch' => $batch, 'result' => 'error', 'message' => '알 수 없는 동작입니다.']);
        } catch (\DomainException $e) {
            // 이미 처리됐거나(1회용 소진) 계단/권한 불일치 — 안내만.
            return view('payout-approval.result', ['batch' => $batch, 'result' => 'already', 'message' => $e->getMessage()]);
        }
    }

    /** 담당자별 실지급 합계(표시용). computed actual_payout 를 PHP 로 합산. */
    private function breakdown(SettlementPayoutBatch $batch): array
    {
        $rows = [];
        foreach ($batch->settlements()->with('salesman')->get() as $s) {
            $name = $s->salesman?->name ?? '-';
            $rows[$name] = ($rows[$name] ?? 0) + (int) $s->actual_payout;
        }
        arsort($rows);

        return $rows;
    }

    /**
     * 회사이익 요약(표시용) — 대표가 "직원 지급 대비 회사이익"을 한눈에 보게 (jin 2026-07-09).
     * 공식 = 총마진(Σ total_margin) − 지급총액(배치 total_payout, 조정 포함) + 환차(Σ exchange_difference_krw).
     * 관리자 대시보드 companyProfit / 월결산 알림톡과 동일 공식(같은 정산셋 기준). 손실이면 음수.
     */
    private function profitStats(SettlementPayoutBatch $batch): array
    {
        $settlements = $batch->settlements()->get();
        $totalMargin = (int) $settlements->sum(fn (Settlement $s) => (int) $s->total_margin);
        $fx = (int) $settlements->sum(fn (Settlement $s) => (int) ($s->exchange_difference_krw ?? 0));
        $payout = (int) $batch->total_payout;

        return [
            'total_margin' => $totalMargin,
            'payout' => $payout,
            'fx' => $fx,
            'company_profit' => $totalMargin - $payout + $fx,
        ];
    }
}
