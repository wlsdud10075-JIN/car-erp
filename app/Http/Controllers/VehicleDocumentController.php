<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Services\VehicleCiplGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDocumentController extends Controller
{
    // 영문 4종은 수출 채널 차량만 발급 가능
    private const EXPORT_ONLY_TYPES = ['invoice', 'sales_contract', 'ro_cipl', 'con_cipl'];

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
            'deregistration' => $this->renderPdf($vehicle, 'documents.deregistration', '말소신청서'),
            'registration_application' => $this->renderPdf($vehicle, 'documents.registration-application', '등록증재발급신청서'),
            'transfer_certificate' => $this->renderPdf($vehicle, 'documents.transfer-certificate', '양도증명서'),
            'invoice' => $this->renderPdf($vehicle, 'documents.invoice', 'Invoice'),
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
