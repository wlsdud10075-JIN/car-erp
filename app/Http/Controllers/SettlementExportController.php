<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use App\Models\Settlement;
use App\Services\SettlementExportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 정산 데이터 export (귀속월 기준, 영업담당자별 시트) — jin 2026-08-03.
 *
 * GET /erp/settlements/export  (settlement 미들웨어 = 재무·관리·admin·super, throttle:data-export)
 *
 * - 필터는 정산관리 화면(erp/settlements)과 **정확히 동일**하게 미러한다. 화면에서 본 목록이 그대로 나와야
 *   하므로 "전체(필터 무시)" 범위 옵션은 두지 않는다(차량 export 의 scope=all 함정 회피 — SKILLS §14).
 * - 귀속월 판정은 Settlement::scopeAttributedMonth() 단일출처 — 화면 monthScope()·배치 submitForMonth()
 *   와 동치(SettlementAttributedMonthScopeTest 가 강제).
 * - export_logs 감사 기록(append-only).
 */
class SettlementExportController extends Controller
{
    public function download(Request $request, SettlementExportService $exporter): StreamedResponse
    {
        $user = $request->user();
        abort_unless((bool) $user?->canSeeSettlementScreen(), 403);   // 볼 사람 지정(2026-11-28) — 화면과 같은 판정

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $held = $request->query('held') === '1';
        // 2026-10-06 jin — 2차 필터(2차 가능/비용 대기/대기/마무리)도 화면 그대로. 모르는 값은 scope 가 무시한다.
        $secondary = (string) $request->query('secondary', '');
        $salesmanId = (int) $request->query('salesmanId', 0);
        $month = (string) $request->query('month', '');
        $dateFrom = (string) $request->query('dateFrom', '');
        $dateTo = (string) $request->query('dateTo', '');
        // 🏷️ 화면의 켜기/끄기 필터도 같이 받는다 (jin 2026-11-22) — 안 받으면 「내수」 칩을 켜고 받은 엑셀에 전체가 나온다
        //    («화면에서 본 것과 다른 게 나오면 대조가 무의미»). 게이트 예외 토글은 원래부터 빠져 있었다.
        $domestic = $request->query('domestic') === '1';
        $reanchored = $request->query('reanchored') === '1';
        $override = $request->query('ovr') === '1';

        $settlements = Settlement::query()
            ->with(['vehicle', 'salesman'])
            ->when($search !== '', fn ($q) => $q->searchTerm($search))
            ->when($status !== '', fn ($q) => $q->where('settlement_status', $status))
            ->when($secondary !== '', fn ($q) => $q->secondaryFilter($secondary))
            ->when($held, fn ($q) => $q->payoutHeldByUnpaid())
            ->when($domestic, fn ($q) => $q->where('is_domestic', true))
            ->when($reanchored, fn ($q) => $q->whereNotNull('reanchored_from'))
            ->when($override, fn ($q) => $q->whereNotNull('gate_override_at'))
            ->when($salesmanId > 0, fn ($q) => $q->where('salesman_id', $salesmanId))
            ->when($month !== '', fn ($q) => $q->attributedMonth($month))
            ->when($dateFrom !== '', fn ($q) => $q->whereHas('vehicle',
                fn ($q2) => $q2->where('purchase_date', '>=', $dateFrom)))
            ->when($dateTo !== '', fn ($q) => $q->whereHas('vehicle',
                fn ($q2) => $q2->where('purchase_date', '<=', $dateTo)))
            ->orderBy('salesman_id')
            ->orderBy('id')
            ->get();

        $spreadsheet = $exporter->build($settlements);

        ExportLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'target' => 'settlements',
            'scope' => 'all',
            'row_count' => $settlements->count(),
            'columns' => $exporter->columnLabels(),
            'filters' => array_filter([
                'month' => $month, 'status' => $status, 'held' => $held ? '1' : '',
                'salesmanId' => $salesmanId ?: '', 'q' => $search,
                'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
                'domestic' => $domestic ? '1' : '', 'reanchored' => $reanchored ? '1' : '', 'ovr' => $override ? '1' : '',
            ]),
        ]);

        $filename = '정산_'.($month !== '' ? $month.'_' : '').now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
