<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDocumentController extends Controller
{
    // 수출 채널 차량만 발급 (판매 인보이스 + 선적 4종)
    private const EXPORT_ONLY_TYPES = [
        'invoice',
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract',
    ];

    // 지원 서류 type (전부 system xlsx 자동기입 — DocumentFiller)
    private const SUPPORTED_TYPES = [
        'deregistration', 'deregistration_contract', 'poa',   // 매입 (전 채널)
        'invoice',                                            // 판매 (export)
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract', // 선적 (export)
        'clearance',                                          // 통관 (8시트 SET)
    ];

    /**
     * 차량별 서류 자동 생성 — system xlsx 양식의 노란칸 자동기입.
     *
     * GET /erp/vehicles/{id}/documents/{type}
     *
     * 감사 로그(개인정보보호법 §29): 다운로드 성공 시 document_access_logs에 1행 기록.
     */
    public function show(int $id, string $type, Request $request): StreamedResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        abort_unless(in_array($type, self::SUPPORTED_TYPES, true), 404, '지원하지 않는 서류 종류입니다: '.$type);

        if (in_array($type, self::EXPORT_ONLY_TYPES, true)) {
            abort_unless($vehicle->sales_channel === 'export', 403, '수출 채널 차량에서만 발급 가능한 서류입니다.');
        }

        $response = $this->streamXlsx($vehicle, $type);

        DocumentAccessLog::create([
            'user_id' => auth()->id(),
            'vehicle_id' => $vehicle->id,
            'document_type' => $type,
            'ip_address' => $request->ip(),
        ]);

        return $response;
    }

    private function streamXlsx(Vehicle $vehicle, string $type): StreamedResponse
    {
        $filler = new DocumentFiller($vehicle);
        $spreadsheet = $filler->spreadsheet($type);
        $filename = $filler->filename($type);

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
