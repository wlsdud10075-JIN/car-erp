<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExportLog;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Services\SettlementExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'profit' => $batch->profitStats(),
            'exportUrl' => $this->exportUrl($batch, $user),
            'error' => null,
        ]);
    }

    /**
     * 이 배치의 정산 엑셀 (jin 2026-08-04) — 카톡에서 연 승인 페이지에서 바로 받는다.
     *
     * 알림톡 카드엔 규격상(제목 6자·설명 20자) 상세를 못 싣는다. 페이지엔 승인 판단용 3항목만 두고,
     * 25열 전체가 필요하면 여기서 받는다. 인가 = show 와 동일한 서명 URL(로그인 없음).
     * ⚠️ 이 배치의 정산만 — 화면 export 처럼 필터를 받지 않는다(서명 링크에 필터를 실으면 범위가 새어난다).
     */
    public function export(Request $request, SettlementPayoutBatch $batch, SettlementExportService $exporter): StreamedResponse
    {
        $user = User::find((int) $request->query('u'));

        $settlements = $batch->settlements()
            ->with(['vehicle', 'salesman'])
            ->orderBy('salesman_id')->orderBy('id')
            ->get();

        $spreadsheet = $exporter->build($settlements);

        ExportLog::create([
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
            'target' => 'settlements',
            'scope' => 'payout_batch',
            'row_count' => $settlements->count(),
            'columns' => $exporter->columnLabels(),
            'filters' => ['batch' => (string) $batch->id, 'month' => (string) $batch->month],
        ]);

        $filename = '정산_'.$batch->month.'_배치'.$batch->id.'.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** 승인 페이지 진입 시 새로 발급하는 엑셀 다운로드 링크(60분) — decideUrl 과 같은 수명. */
    private function exportUrl(SettlementPayoutBatch $batch, ?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return URL::temporarySignedRoute('payout.approve.export', now()->addMinutes(60), [
            'batch' => $batch->id,
            'u' => $user->id,
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
                'profit' => $batch->profitStats(),
                'exportUrl' => $this->exportUrl($batch, $user),
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

    /**
     * 담당자별 드릴다운(표시용) — ERP 정산지급 승인큐 화면과 같은 구조 (jin 2026-08-03).
     *   [담당자 => ['count','payout','adjust','net','vehicles'=>[['number','amount'], ...]]]
     *
     * ⚠️ 조정(월배치 +/−)을 반드시 함께 반영한다. `total_payout` 은 recomputeTotal 이 조정을 더한 값이라,
     *    정산 합만 보여주면 **담당자별을 다 더해도 지급 총액과 안 맞는다**(2026-06 배치에 −729,250 선례).
     *    조정만 있고 정산이 없는 담당자도 행으로 남겨야 합계가 닫힌다.
     * computed actual_payout 이 vehicle 을 참조하므로 vehicle 까지 eager load(N+1 방지).
     */
    private function breakdown(SettlementPayoutBatch $batch): array
    {
        $blank = ['count' => 0, 'payout' => 0, 'adjust' => 0, 'net' => 0, 'vehicles' => []];
        $rows = [];

        foreach ($batch->settlements()->with(['salesman', 'vehicle'])->get() as $s) {
            $name = $s->salesman?->name ?? __('payout_batch.no_salesman');
            $rows[$name] ??= $blank;
            $amount = (int) $s->actual_payout;
            $rows[$name]['payout'] += $amount;
            $rows[$name]['vehicles'][] = [
                'number' => $s->vehicle?->vehicle_number ?: '#'.$s->vehicle_id,
                'amount' => $amount,
                // 승인 판단에 실제로 쓰는 3개만 (jin 2026-08-04) — 엑셀 25열을 폰에 다 띄울 순 없다.
                'margin' => (int) $s->total_margin,
                'type' => $s->settlement_type === 'ratio'
                    ? __('payout_batch.type_ratio', ['ratio' => $s->effective_ratio])
                    : __('payout_batch.type_per_unit'),
            ];
        }

        foreach ($batch->adjustments()->with('salesman')->get() as $adj) {
            $name = $adj->salesman?->name ?? __('payout_batch.no_salesman');
            $rows[$name] ??= $blank;
            $rows[$name]['adjust'] += (int) $adj->amount;
        }

        foreach ($rows as $name => $row) {
            $rows[$name]['count'] = count($row['vehicles']);
            $rows[$name]['net'] = $row['payout'] + $row['adjust'];
        }
        uasort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

        return $rows;
    }
}
