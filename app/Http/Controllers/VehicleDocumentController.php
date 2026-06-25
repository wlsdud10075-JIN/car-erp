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

    // 다중차량(여러 대 → 1서류) 발급을 지원하는 선적 4종. 30대까지 슬롯 자동 트림.
    private const MULTI_TYPES = [
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract',
    ];

    private const MULTI_MAX = 30;

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

        // Review.md #3 (2026-06-09) — 소유권 스코프 가드. 영업이 타인 차량의 RRN 박힌
        // 서류를 URL 직접 호출로 다운로드하던 IDOR 차단. openEdit 와 동일 기준.
        abort_unless(auth()->user()->canScopeVehicle($vehicle), 403, '접근 권한이 없는 차량입니다.');

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

    /**
     * 다중차량 선적 서류 — 선택 N대를 1서류에 기입 (선적 4종 전용).
     *
     * GET /erp/vehicles/documents/{type}?ids=1,2,3
     *
     * 감사 로그: 차량당 1행(개인정보 감사). 선택 순서대로 슬롯에 채운다.
     */
    public function showMulti(string $type, Request $request): StreamedResponse
    {
        abort_unless(in_array($type, self::MULTI_TYPES, true), 404, '다중차량 발급을 지원하지 않는 서류입니다: '.$type);

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(fn ($x) => (int) trim($x))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 400, '차량을 선택하세요.');
        abort_if($ids->count() > self::MULTI_MAX, 422, '한 서류에 최대 '.self::MULTI_MAX.'대까지 가능합니다.');

        // 선택 순서 보존하여 로드
        $byId = Vehicle::whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = $ids->map(fn (int $id) => $byId->get($id))->filter()->values();

        abort_if($vehicles->isEmpty(), 404, '차량을 찾을 수 없습니다.');
        abort_unless(
            $vehicles->every(fn (Vehicle $v) => $v->sales_channel === 'export'),
            403,
            '수출 채널 차량에서만 발급 가능한 서류입니다.',
        );

        // Review.md #3 (2026-06-09) — 다중 발급도 전 차량 소유권 스코프 검사 (IDOR 차단).
        $user = auth()->user();
        abort_unless(
            $vehicles->every(fn (Vehicle $v) => $user->canScopeVehicle($v)),
            403,
            '접근 권한이 없는 차량이 포함되어 있습니다.',
        );

        $response = $this->stream(new DocumentFiller($vehicles), $type);

        foreach ($vehicles as $vehicle) {
            DocumentAccessLog::create([
                'user_id' => auth()->id(),
                'vehicle_id' => $vehicle->id,
                'document_type' => $type,
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function streamXlsx(Vehicle $vehicle, string $type): StreamedResponse
    {
        return $this->stream(new DocumentFiller($vehicle), $type);
    }

    private function stream(DocumentFiller $filler, string $type): StreamedResponse
    {
        $spreadsheet = $filler->spreadsheet($type);
        $filename = $filler->filename($type);

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                // preCalc=false → fullCalcOnLoad=1: Excel 이 열 때 전체 재계산. 통관SET 은 크로스시트
                // cascade + 고급함수(TEXTJOIN/UNIQUE/SUBSTITUTE) 라 PhpSpreadsheet 계산엔진에 맡기면
                // 문자열 참조·합계 캐시가 비어 빈칸으로 보임 → Excel 네이티브 계산에 위임(jin 실측).
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
