<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RO_CIPL / con_CIPL Excel 생성기.
 *
 * 원본 시트 디자인(병합·테두리·색)을 유지하기 위해 xlsm에서 추출한
 * resources/templates/{ro,con}_cipl_template.xlsx를 로드해 VLOOKUP 자리에
 * 차량 데이터를 직접 채워 .xlsx 다운로드 응답으로 반환.
 */
class VehicleCiplGenerator
{
    /** Container 분기 시 차량 1대당 차지하는 행 수 (정보/연료/공백). */
    private const CON_ROWS_PER_VEHICLE = 3;

    public function __construct(private Vehicle $vehicle) {}

    public function downloadRoCipl(): StreamedResponse
    {
        return $this->stream('ro_cipl_template.xlsx', fn (Spreadsheet $book) => $this->fillRoCipl($book));
    }

    public function downloadConCipl(): StreamedResponse
    {
        return $this->stream('con_cipl_template.xlsx', fn (Spreadsheet $book) => $this->fillConCipl($book));
    }

    private function stream(string $template, callable $filler): StreamedResponse
    {
        $path = resource_path('templates/'.$template);
        $book = IOFactory::load($path);

        $filler($book);

        $kind = $template === 'ro_cipl_template.xlsx' ? 'RO_CIPL' : 'con_CIPL';
        $filename = sprintf(
            '%s_%s_%s.xlsx',
            $kind,
            $this->vehicle->vehicle_number ?: $this->vehicle->id,
            now()->format('Ymd'),
        );

        return Response::streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function fillRoCipl(Spreadsheet $book): void
    {
        $ws = $book->getActiveSheet();
        $v = $this->vehicle;
        $buyer = $v->exportBuyer ?: $v->buyer;
        $company = config('company');

        // ── Header (공통) ───────────────────────────────────────────
        $ws->setCellValue('B3', $this->shipperBlock($company));
        $ws->setCellValue('B9', $this->buyerBlock($buyer));
        $ws->setCellValue('J3', now()->format('Y-m-d'));
        $ws->setCellValue('I15', $v->bl_loading_location ?: '');
        // 2026-05-21 CIPL 이식 — port_of_loading / discharge_port / incoterms 값 사용 (NULL fallback)
        $ws->setCellValue('B16', $v->port_of_loading ?: 'INCHEON, KOREA');
        $ws->setCellValue('E16', $v->dischargePort?->name ?? ($buyer?->country?->name ?? ''));
        $ws->setCellValue('B18', $v->vessel_name ?: '');
        $ws->setCellValue('E18', $v->shipping_date?->format('Y-m-d') ?? '');
        $ws->setCellValue('C32', $v->incoterms ?: 'FOB');  // 인코텀즈 (FOB/CFR)

        // ── 차량 1대 (row 21) ─────────────────────────────────────
        $row = 21;
        $ws->setCellValue("C{$row}", $v->brand ?: '');
        $ws->setCellValue("D{$row}", $v->nice_spec_model ?: $v->model_type);
        $ws->setCellValue("E{$row}", $v->year ?: '');
        $ws->setCellValue("F{$row}", $v->nice_reg_vin ?: '');
        $ws->setCellValue("G{$row}", 1);
        $ws->setCellValue("H{$row}", (float) ($v->sale_price ?? 0));
        $ws->setCellValueExplicit("I{$row}", "=H{$row}", DataType::TYPE_FORMULA);
        $ws->setCellValue("J{$row}", (float) ($v->weight_kg ?? 0));
        $ws->setCellValue("L{$row}", (float) ($v->transport_fee ?? 0));
        // M21 (=SUM(I21,L21))는 템플릿 원본 수식 유지

        // ── 빈 차량 자리(row 22~30) Q'TY=1 hardcoded 제거 ───────────
        for ($r = 22; $r <= 30; $r++) {
            foreach (['B', 'G'] as $col) {
                $ws->setCellValue("{$col}{$r}", null);
            }
        }
    }

    private function fillConCipl(Spreadsheet $book): void
    {
        $ws = $book->getActiveSheet();
        $v = $this->vehicle;
        $buyer = $v->exportBuyer ?: $v->buyer;
        $company = config('company');

        // ── Header (공통) ───────────────────────────────────────────
        $ws->setCellValue('B3', $this->shipperBlock($company));
        $ws->setCellValue('B9', $this->buyerBlock($buyer));
        $ws->setCellValue('J3', now()->format('Y-m-d'));
        $ws->setCellValue('I15', $v->bl_loading_location ?: '');
        $ws->setCellValue('I17', $v->container_number ?: '');
        // 2026-05-21 CIPL 이식 — port_of_loading / discharge_port / incoterms 값 사용 (NULL fallback)
        $ws->setCellValue('B16', $v->port_of_loading ?: 'INCHEON, KOREA');
        $ws->setCellValue('E16', $v->dischargePort?->name ?? ($buyer?->country?->name ?? ''));
        $ws->setCellValue('B18', $v->vessel_name ?: '');
        $ws->setCellValue('E18', $v->shipping_date?->format('Y-m-d') ?? '');
        $ws->setCellValue('C37', $v->incoterms ?: 'FOB');  // 인코텀즈 (FOB/CFR — con_CIPL은 C37 셀)

        // ── 차량 1대 (row 21 + 22 fuel/cc) ─────────────────────────
        $row = 21;
        $ws->setCellValue("C{$row}", $v->brand ?: '');
        $ws->setCellValue("D{$row}", $v->nice_spec_model ?: $v->model_type);
        $ws->setCellValue("E{$row}", $v->year ?: '');
        $ws->setCellValue("F{$row}", $v->nice_reg_vin ?: '');
        $ws->setCellValue("G{$row}", 1);
        $ws->setCellValue("H{$row}", (float) ($v->sale_price ?? 0));
        $ws->setCellValueExplicit("I{$row}", "=H{$row}", DataType::TYPE_FORMULA);
        $ws->setCellValue("J{$row}", (float) ($v->weight_kg ?? 0));
        $ws->setCellValue("L{$row}", (float) ($v->transport_fee ?? 0));

        // 연료/배기량 행 (row 22)
        $fuelRow = $row + 1;
        $ws->setCellValue("E{$fuelRow}", $v->nice_reg_fuel_type ?: '');
        $ws->setCellValue("G{$fuelRow}", (int) ($v->cc ?? $v->nice_spec_displacement ?? 0));

        // ── 빈 차량 자리(row 24, 27, 30, 33 — 2~5번째 차량) hardcoded 제거 ──
        // con_CIPL은 차량당 self::CON_ROWS_PER_VEHICLE(=3)행. row 24부터 4대 빈 자리.
        for ($i = 1; $i <= 4; $i++) {
            $r = 21 + $i * self::CON_ROWS_PER_VEHICLE;          // 24, 27, 30, 33
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'L'] as $col) {
                $ws->setCellValue("{$col}{$r}", null);
            }
            // row+1 (TYPE OF FUEL 라벨) — Description 텍스트는 유지, 값(E,G)만 비움
            $fr = $r + 1;
            $ws->setCellValue("E{$fr}", null);
            $ws->setCellValue("G{$fr}", null);
        }
    }

    private function shipperBlock(array $company): string
    {
        return implode("\n", [
            $company['name_en'].',',
            $company['address_en'],
            'TEL: '.$company['tel'].'  FAX: '.$company['fax'],
            'EMAIL: '.$company['email'],
        ]);
    }

    private function buyerBlock(?Buyer $buyer): string
    {
        if (! $buyer) {
            return '';
        }

        return implode("\n", array_filter([
            $buyer->name,
            $buyer->contact_name,
            $buyer->address,
            $buyer->contact_phone ? 'TEL: '.$buyer->contact_phone : null,
        ]));
    }
}
