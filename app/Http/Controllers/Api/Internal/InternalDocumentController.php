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
 * ⚠️ board 허용 서류 = 선적 4종만. 말소서류(RRN·성명·주소 포함)·위임장·인보이스·통관SET 차단(§29).
 * 본인 차만(IDOR) + document_access_logs(source='board_api', actor_email) 감사.
 */
class InternalDocumentController extends Controller
{
    private const BOARD_ALLOWED_TYPES = [
        'roro_invoice_packing', 'roro_contract', 'container_invoice_packing', 'container_contract',
    ];

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
