<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class VehicleDocumentController extends Controller
{
    /**
     * 차량별 서류 자동 생성.
     *
     * GET /erp/vehicles/{id}/documents/{type}
     *
     * 지원 type:
     * - deregistration: 차량말소신청서 (PDF)
     */
    public function show(int $id, string $type): Response|HttpResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        return match ($type) {
            'deregistration' => $this->renderPdf($vehicle, 'documents.deregistration', '말소신청서'),
            'registration_application' => $this->renderPdf($vehicle, 'documents.registration-application', '등록증재발급신청서'),
            'transfer_certificate' => $this->renderPdf($vehicle, 'documents.transfer-certificate', '양도증명서'),
            'invoice' => $this->renderPdf($vehicle, 'documents.invoice', 'Invoice'),
            'sales_contract' => $this->renderPdf($vehicle, 'documents.sales-contract', 'SalesContract'),
            default => abort(404, '지원하지 않는 서류 종류입니다: '.$type),
        };
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
