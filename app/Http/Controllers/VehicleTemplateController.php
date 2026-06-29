<?php

namespace App\Http\Controllers;

use App\Services\VehicleTemplateExporter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleTemplateController extends Controller
{
    /**
     * 차량 일괄적재 빈 양식(xlsx) 다운로드.
     *
     * GET /erp/vehicles/import-template  (admin 미들웨어 = super+admin)
     *
     * 데이터 없는 빈 양식(PII·회계 0) → 다운로드 자체는 무해. 다만 대량적재용 마이그레이션
     * 도구라 super/admin 으로 한정(라우트 admin 미들웨어). 빌드는 VehicleTemplateExporter 단일 출처.
     */
    public function download(): StreamedResponse
    {
        $spreadsheet = (new VehicleTemplateExporter)->build();

        return response()->streamDownload(
            function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            },
            '차량적재양식.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
