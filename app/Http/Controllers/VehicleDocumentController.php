<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\VehicleCiplGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDocumentController extends Controller
{
    // 수출 채널 차량만 발급 가능 (인보이스 + 선적 4종 + 구 CIPL/계약서)
    private const EXPORT_ONLY_TYPES = [
        'invoice', 'sales_contract', 'ro_cipl', 'con_cipl',
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract',
    ];

    /**
     * 차량별 서류 자동 생성.
     *
     * GET /erp/vehicles/{id}/documents/{type}
     *
     * 지원 type:
     * - deregistration / registration_application / transfer_certificate (PDF, 전 채널)
     * - invoice / sales_contract (PDF, export 채널만)
     * - ro_cipl / con_cipl (Excel, export 채널만)
     *
     * 감사 로그(개인정보보호법 §29): 다운로드 성공 시 document_access_logs에 1행 기록.
     */
    public function show(int $id, string $type, Request $request): Response|HttpResponse|StreamedResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        if (in_array($type, self::EXPORT_ONLY_TYPES, true)) {
            abort_unless($vehicle->sales_channel === 'export', 403, '수출 채널 차량에서만 발급 가능한 서류입니다.');
        }

        $response = match ($type) {
            // Phase 1 (2026-05-24) — 매입 3종 system xlsx 자동기입 (기존 blade PDF 폐기 예정)
            'deregistration' => $this->streamXlsx($vehicle, 'deregistration'),
            'deregistration_contract' => $this->streamXlsx($vehicle, 'deregistration_contract'),
            'poa' => $this->streamXlsx($vehicle, 'poa'),
            'registration_application' => $this->renderPdf($vehicle, 'documents.registration-application', '등록증재발급신청서'),
            'transfer_certificate' => $this->renderPdf($vehicle, 'documents.transfer-certificate', '양도증명서'),
            // Phase 2 (2026-05-24) — 판매 인보이스 system xlsx 자동기입
            'invoice' => $this->streamXlsx($vehicle, 'invoice'),
            // Phase 3 (2026-05-24) — 통관 SET (구매리스트 마스터 + 6시트 자동연동)
            'clearance' => $this->streamXlsx($vehicle, 'clearance'),
            // Phase 4 (2026-05-24) — 선적 4종 (우선 1대=1행)
            'container_invoice_packing' => $this->streamXlsx($vehicle, 'container_invoice_packing'),
            'container_contract' => $this->streamXlsx($vehicle, 'container_contract'),
            'roro_invoice_packing' => $this->streamXlsx($vehicle, 'roro_invoice_packing'),
            'roro_contract' => $this->streamXlsx($vehicle, 'roro_contract'),
            'sales_contract' => $this->renderPdf($vehicle, 'documents.sales-contract', 'SalesContract'),
            'ro_cipl' => (new VehicleCiplGenerator($vehicle))->downloadRoCipl(),
            'con_cipl' => (new VehicleCiplGenerator($vehicle))->downloadConCipl(),
            default => abort(404, '지원하지 않는 서류 종류입니다: '.$type),
        };

        DocumentAccessLog::create([
            'user_id' => auth()->id(),
            'vehicle_id' => $vehicle->id,
            'document_type' => $type,
            'ip_address' => $request->ip(),
        ]);

        return $response;
    }

    /**
     * system xlsx 양식의 노란칸을 차량 데이터로 자동기입해 다운로드 (DocumentFiller).
     */
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

    private function renderPdf(Vehicle $vehicle, string $view, string $kindLabel): Response
    {
        $pdf = Pdf::loadView($view, [
            'vehicle' => $vehicle,
            'today' => now()->format('Y년 m월 d일'),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            '%s_%s_%s.pdf',
            $kindLabel,
            $vehicle->vehicle_number ?: $vehicle->id,
            now()->format('Ymd'),
        );

        return $pdf->download($filename);
    }
}
