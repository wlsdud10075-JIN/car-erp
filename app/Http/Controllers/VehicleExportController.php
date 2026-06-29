<?php

namespace App\Http\Controllers;

use App\Models\ExportLog;
use App\Models\Vehicle;
use App\Services\VehicleExportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 차량 데이터 export (고정 화이트리스트 + 마스킹) — 2026-06-29 라운드테이블 조건부 GO.
 *
 * GET /erp/vehicles/export  (admin 미들웨어 = super+admin, throttle:data-export)
 *
 * - 화이트리스트·마스킹·formula injection 방어 = VehicleExportService.
 * - canScopeVehicle 기준 쿼리 스코핑(영업 본인/관리 팀/그 외 전체). admin 라우트라 현재는 전체지만
 *   방어적으로 동일 스코프 적용(추후 라우트 개방 대비).
 * - export_logs 감사 기록(append-only).
 */
class VehicleExportController extends Controller
{
    public function download(Request $request, VehicleExportService $exporter): StreamedResponse
    {
        $user = $request->user();

        $restrictOwn = ! $user->isAdmin() && $user->role === '영업' && $user->salesman;
        $restrictMgr = ! $user->isAdmin() && $user->role === '관리';
        $subIds = $restrictMgr ? $user->getSubordinateSalesmanIds() : [];

        // 정산 그룹은 정산 접근 role(재무·관리·admin·super)에게만 — 영업·통관은 마진 export 불가.
        $allowSettlement = $user->canAccessSettlement();

        // 컬럼 선택 — 화이트리스트 교집합만(보안: 알 수 없는 key·권한 밖 정산 컬럼 무시).
        $selected = array_values(array_filter(explode(',', (string) $request->query('cols', ''))));
        $selected = array_values(array_intersect($selected, $exporter->columnKeys($allowSettlement)));

        // 범위 — current(화면 필터 미러) / all(전 기간 전체, 필터 무시).
        $mirror = $request->query('scope', 'current') !== 'all';
        $progress = $mirror ? (string) $request->query('progress', '') : '';
        $search = $mirror ? trim((string) $request->query('q', '')) : '';
        $salesmanId = $mirror ? (string) $request->query('salesmanId', '') : '';
        $dateFrom = $mirror ? (string) $request->query('dateFrom', '') : '';
        $dateTo = $mirror ? (string) $request->query('dateTo', '') : '';
        $dateCol = match ((string) $request->query('dateType', 'purchase')) {
            'sale' => 'sale_date',
            'shipping' => 'shipping_date',
            'bl' => 'bl_issue_date',
            default => 'purchase_date',
        };

        $vehicles = Vehicle::query()
            ->with(['salesman', 'buyer', 'consignee', 'settlements'])
            ->when($restrictOwn, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictMgr, fn ($q) => $q->whereIn('salesman_id', $subIds))
            ->when($salesmanId !== '', fn ($q) => $q->where('salesman_id', $salesmanId))
            ->when($progress !== '', fn ($q) => $q->where('progress_status_cache', $progress))
            ->when($dateFrom !== '', fn ($q) => $q->whereDate($dateCol, '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate($dateCol, '<=', $dateTo))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('vehicle_number', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('model_type', 'like', "%{$search}%")))
            ->orderBy('id')
            ->get();

        $spreadsheet = $exporter->build($vehicles, $selected, $allowSettlement);

        ExportLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'target' => 'vehicles',
            'scope' => $restrictOwn ? 'own' : ($restrictMgr ? 'team' : 'all'),
            'row_count' => $vehicles->count(),
            'columns' => $selected !== [] ? $selected : $exporter->columnKeys($allowSettlement),
            'filters' => array_filter([
                'range' => $mirror ? 'current' : 'all',
                'progress' => $progress, 'q' => $search, 'salesmanId' => $salesmanId,
                'dateFrom' => $dateFrom, 'dateTo' => $dateTo,
            ]),
        ]);

        $filename = '차량목록_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
