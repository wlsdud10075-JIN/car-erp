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
            'deregistration' => $this->deregistration($vehicle),
            default => abort(404, '지원하지 않는 서류 종류입니다: '.$type),
        };
    }

    private function deregistration(Vehicle $vehicle): Response
    {
        $pdf = Pdf::loadView('documents.deregistration', [
            'vehicle' => $vehicle,
            'today' => now()->format('Y년 m월 d일'),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            '말소신청서_%s_%s.pdf',
            $vehicle->vehicle_number ?: $vehicle->id,
            now()->format('Ymd'),
        );

        return $pdf->download($filename);
    }
}
