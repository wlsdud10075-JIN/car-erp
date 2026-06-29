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

        $channel = (string) $request->query('channel', '');
        $progress = (string) $request->query('progress', '');
        $search = trim((string) $request->query('q', ''));

        $vehicles = Vehicle::query()
            ->with(['salesman', 'buyer', 'consignee'])
            ->when($restrictOwn, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictMgr, fn ($q) => $q->whereIn('salesman_id', $subIds))
            ->when($channel !== '', fn ($q) => $q->where('sales_channel', $channel))
            ->when($progress !== '', fn ($q) => $q->where('progress_status_cache', $progress))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('vehicle_number', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('model_type', 'like', "%{$search}%")))
            ->orderBy('id')
            ->get();

        $spreadsheet = $exporter->build($vehicles);

        ExportLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'target' => 'vehicles',
            'scope' => $restrictOwn ? 'own' : ($restrictMgr ? 'team' : 'all'),
            'row_count' => $vehicles->count(),
            'columns' => $exporter->columnKeys(),
            'filters' => array_filter(['channel' => $channel, 'progress' => $progress, 'q' => $search]),
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
