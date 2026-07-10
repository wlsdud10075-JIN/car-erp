<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\PdfConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDocumentController extends Controller
{
    // 수출 채널 차량만 발급 (판매 인보이스 + 판매계약서 + 선적 4종)
    private const EXPORT_ONLY_TYPES = [
        'invoice', 'sales_contract',
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract',
    ];

    // 지원 서류 type (전부 system xlsx 자동기입 — DocumentFiller)
    private const SUPPORTED_TYPES = [
        'deregistration', 'deregistration_contract', 'poa',   // 매입 (전 채널)
        'invoice', 'sales_contract',                          // 판매 (export)
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract', // 선적 (export)
        'clearance',                                          // 통관 (8시트 SET)
    ];

    // 다중차량(여러 대 → 1서류) 발급. 선적 4종 + 판매계약서. 30대까지 슬롯 자동 트림.
    private const MULTI_TYPES = [
        'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract',
        'sales_contract',
    ];

    // 1바이어·단일통화 계약서 — 선택 차량이 동일 바이어·통화여야 하는 type (매핑이 primary 로만 채움).
    private const HOMOGENEOUS_TYPES = ['sales_contract'];

    private const MULTI_MAX = 30;

    /**
     * 차량별 서류 자동 생성 — system xlsx 양식의 노란칸 자동기입.
     *
     * GET /erp/vehicles/{id}/documents/{type}
     *
     * 감사 로그(개인정보보호법 §29): 다운로드 성공 시 document_access_logs에 1행 기록.
     */
    public function show(int $id, string $type, Request $request): Response|StreamedResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        // Review.md #3 (2026-06-09) — 소유권 스코프 가드. 영업이 타인 차량의 RRN 박힌
        // 서류를 URL 직접 호출로 다운로드하던 IDOR 차단. openEdit 와 동일 기준.
        abort_unless(auth()->user()->canScopeVehicle($vehicle), 403, '접근 권한이 없는 차량입니다.');

        abort_unless(in_array($type, self::SUPPORTED_TYPES, true), 404, '지원하지 않는 서류 종류입니다: '.$type);

        if (in_array($type, self::EXPORT_ONLY_TYPES, true)) {
            abort_unless($vehicle->sales_channel === 'export', 403, '수출 채널 차량에서만 발급 가능한 서류입니다.');
        }

        $response = $this->stream(new DocumentFiller($vehicle), $type, $this->reqFormat($request), $request->boolean('inline'));

        DocumentAccessLog::create([
            'user_id' => auth()->id(),
            'vehicle_id' => $vehicle->id,
            'document_type' => $type,
            'ip_address' => $request->ip(),
        ]);

        return $response;
    }

    /**
     * 업로드된 말소신청서 원본 파일 개별 다운로드 (선적요청 묶음 → 버튼 1개로 N개 개별 다운로드).
     *
     * 생성 서류(show)와 달리 차량이 업로드한 deregistration_document 파일을 그대로 첨부 다운로드한다.
     * GET /erp/vehicles/{id}/deregistration-file
     */
    public function deregistrationFile(int $id, Request $request): StreamedResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        abort_unless(auth()->user()->canScopeVehicle($vehicle), 403, '접근 권한이 없는 차량입니다.');
        abort_if(blank($vehicle->deregistration_document), 404, '말소신청서가 업로드되지 않았습니다.');

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $path = $vehicle->deregistration_document;
        abort_unless($disk->exists($path), 404, '파일을 찾을 수 없습니다.');

        DocumentAccessLog::create([
            'user_id' => auth()->id(),
            'vehicle_id' => $vehicle->id,
            'document_type' => 'deregistration_upload',
            'ip_address' => $request->ip(),
        ]);

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $filename = '말소신청서_'.$vehicle->vehicle_number.($ext ? '.'.$ext : '');

        return $disk->download($path, $filename);
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

        // 판매계약서 = 1바이어·단일통화. 매핑이 바이어블록·환율을 primary 로만 채우고 통화 합산하므로,
        // 혼합 선택 시 한 바이어 정보가 타 차량 위에 인쇄되거나 통화 다른 FOB 가 무의미하게 합산됨 → 차단.
        if (in_array($type, self::HOMOGENEOUS_TYPES, true)) {
            abort_if(
                $vehicles->pluck('buyer_id')->unique()->count() > 1,
                422,
                '판매계약서는 동일 바이어의 차량만 함께 발급할 수 있습니다.',
            );
            abort_if(
                $vehicles->pluck('currency')->unique()->count() > 1,
                422,
                '판매계약서는 동일 통화의 차량만 함께 발급할 수 있습니다.',
            );
        }

        $response = $this->stream(new DocumentFiller($vehicles), $type, $this->reqFormat($request), $request->boolean('inline'));

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

    /** ?format=pdf 면 pdf, 그 외 xlsx(기본). */
    private function reqFormat(Request $request): string
    {
        return $request->query('format') === 'pdf' ? 'pdf' : 'xlsx';
    }

    /**
     * DocumentFiller 로 채운 서류를 xlsx(기본) 또는 pdf 로 응답.
     *   pdf: PdfConverter(LibreOffice) 변환. inline=true → 브라우저 뷰어(인쇄용) / false → 다운로드.
     *   xlsx: preCalc=false → Excel 이 열 때 재계산(통관SET cascade). PDF 는 soffice 가 재계산(PdfConverter).
     */
    private function stream(DocumentFiller $filler, string $type, string $format = 'xlsx', bool $inline = false): Response|StreamedResponse
    {
        $spreadsheet = $filler->spreadsheet($type);
        $filename = $filler->filename($type);

        if ($format === 'pdf') {
            $pdf = app(PdfConverter::class)->fromSpreadsheet($spreadsheet);
            $pdfName = preg_replace('/\.xlsx$/i', '.pdf', $filename);
            $disposition = HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $pdfName,
                'document.pdf',
            );

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition,
            ]);
        }

        return response()->streamDownload(
            function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->setPreCalculateFormulas(false);
                $writer->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
