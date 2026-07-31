<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\SalesmanResolver;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * board 영업 포털 ①② 서류 다운로드 — 프록시 스트림 (car-erp 동적 생성 xlsx 를 바이트로 반환).
 * 권위 = docs/integration/board-portal-api.md §6.
 *
 * ⚠️ board 허용 서류 = 선적 4종 + 판매계약서·인보이스(2026-07-31 개방).
 *    말소서류(RRN·성명·주소 포함)·위임장·통관SET 은 계속 차단(§29 — RRN).
 * 본인 차만(IDOR) + document_access_logs(source='board_api', actor_email) 감사.
 */
class InternalDocumentController extends Controller
{
    private const BOARD_ALLOWED_TYPES = [
        'roro_invoice_packing', 'roro_contract', 'container_invoice_packing', 'container_contract',
        // 2026-07-31 (jin) — 판매계약서·인보이스 개방. 영업이 board 에서 많이 쓰게 될 서류.
        //   §29 판단: 둘 다 **RRN 없음**(바이어/컨사이니 여권·연락처·주소뿐). 선적 4종 제한의 원래 이유는
        //   말소서류의 주민번호였다. 영업은 ERP 화면에서 본인 차의 같은 서류를 이미 받을 수 있고,
        //   여기도 본인 차 한정 + export only + document_access_logs 감사가 걸려 있다.
        'sales_contract', 'invoice',
    ];

    /** 1바이어·단일통화여야 하는 type — VehicleDocumentController::HOMOGENEOUS_TYPES 와 lockstep 유지. */
    private const HOMOGENEOUS_TYPES = ['sales_contract', 'invoice'];

    private const MAX = 30;

    public function show(string $type, Request $request): StreamedResponse
    {
        $salesman = SalesmanResolver::resolveActiveOrFail((string) $request->query('salesman_email', ''));

        // ⛔ 선적 4종 외 차단 — 말소서류 등 RRN 포함 서류 board 노출 금지
        abort_unless(in_array($type, self::BOARD_ALLOWED_TYPES, true), 403, 'Forbidden document type');

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($x) => (int) trim($x))->filter()->unique()->values();
        abort_if($ids->isEmpty(), 400, 'No vehicles selected');
        abort_if($ids->count() > self::MAX, 422, 'Too many vehicles');

        $byId = Vehicle::whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = $ids->map(fn ($id) => $byId->get($id))->filter()->values();
        abort_if($vehicles->isEmpty(), 404, 'Not found');

        // IDOR — export 채널 + 본인 차만
        abort_unless($vehicles->every(fn (Vehicle $v) => $v->sales_channel === 'export'), 403, 'Export only');
        abort_unless($vehicles->every(fn (Vehicle $v) => $v->salesman_id === $salesman->id), 403, 'Forbidden');

        // 판매계약서·인보이스 = 1바이어·단일통화 (ERP 화면 showMulti 와 같은 가드). 매핑이 바이어블록·환율을
        //   primary 로만 채우므로 혼합 묶음이면 **조용히 틀린 서류**가 나간다 → 422 로 차단.
        //   board 는 422 를 "동일 바이어·단일 통화" 안내로 분기한다(board 인계문서 §3).
        if (in_array($type, self::HOMOGENEOUS_TYPES, true)) {
            abort_if($vehicles->pluck('buyer_id')->unique()->count() > 1, 422, 'Mixed buyers');
            abort_if($vehicles->pluck('currency')->unique()->count() > 1, 422, 'Mixed currencies');
        }

        $filler = new DocumentFiller($vehicles);
        $spreadsheet = $filler->spreadsheet($type);
        $filename = $filler->filename($type);

        foreach ($vehicles as $vehicle) {
            DocumentAccessLog::create([
                'user_id' => null,
                'vehicle_id' => $vehicle->id,
                'document_type' => $type,
                'ip_address' => $request->ip(),
                'source' => 'board_api',
                'actor_email' => $salesman->email,
            ]);
        }

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);   // fullCalcOnLoad=1 — Excel 재계산 위임 (크로스시트 cascade)
                $writer->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
