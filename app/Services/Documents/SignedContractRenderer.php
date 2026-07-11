<?php

namespace App\Services\Documents;

use App\Models\SignedContract;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 서명본 렌더 (2026-07-10 풀회의 — 옵션 A, self-contained 단일 PDF).
 *
 * 동결된 스냅샷 xlsx 를 그대로 로드해 **Certificate of Completion 시트**를 append 하고,
 * 바이어 canvas 서명 이미지를 그 시트에 삽입한 뒤 soffice 1패스로 PDF 렌더한다.
 * → 계약 원본 페이지(불변) + 서명·증거 페이지가 한 PDF 로 봉인(DocuSign 축소판).
 *
 * 계약 셀은 건드리지 않는다(원본 픽셀 보존, 오염 위험 0). 서명·증거는 전부 CoC 시트에.
 * source_hash(계약 xlsx 바이트)를 CoC 에 인쇄해 "무엇에 서명했나"를 self-contained 로 고정.
 */
class SignedContractRenderer
{
    /** @return string 서명본 PDF 바이트 */
    public function render(SignedContract $contract, string $signaturePng): string
    {
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $xlsxBytes = $disk->get($contract->snapshot_path);

        // 스냅샷 xlsx 를 임시파일로 로드(IOFactory 는 경로 필요)
        $tmpXlsx = tempnam(sys_get_temp_dir(), 'sc_').'.xlsx';
        file_put_contents($tmpXlsx, $xlsxBytes);

        try {
            $spreadsheet = IOFactory::load($tmpXlsx);
            $this->appendCertificate($spreadsheet->createSheet(), $contract, $signaturePng);

            return app(PdfConverter::class)->fromSpreadsheet($spreadsheet);
        } finally {
            @unlink($tmpXlsx);
        }
    }

    private function appendCertificate(Worksheet $sheet, SignedContract $contract, string $signaturePng): void
    {
        $sheet->setTitle('Certificate');
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(70);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        $data = $contract->snapshot_data ?? [];
        $vehicles = collect($data['vehicles'] ?? [])
            ->map(fn ($v) => trim(($v['plate'] ?? '').'  '.($v['brand'] ?? '').' '.($v['model'] ?? '').'  '.($v['vin'] ?? '')))
            ->implode("\n");

        $sheet->setCellValue('A1', 'CERTIFICATE OF COMPLETION');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', '전자서명 확인 · Electronically signed via SSANCAR ERP');
        $sheet->mergeCells('A2:B2');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rows = [
            ['Contract No.', (string) ($data['contract_no'] ?? $contract->contract_no)],
            ['Buyer', (string) ($data['buyer_name'] ?? '')],
            ['Currency', (string) ($data['currency'] ?? $contract->currency)],
            ['Vehicles ('.(int) ($data['vehicle_count'] ?? count($data['vehicles'] ?? [])).')', $vehicles],
            ['Signer', (string) $contract->signer_name],
            ['Recipient email', (string) $contract->recipient_email],
            ['Signed at (KST)', optional($contract->signed_at)->format('Y-m-d H:i:s') ?? ''],
            ['Signer IP', (string) $contract->signer_ip],
            ['Signer device', (string) $contract->signer_ua],
            ['Document SHA-256', (string) $contract->source_hash],
        ];

        $r = 4;
        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:B{$r}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $sheet->getStyle("A{$r}:B{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR);
            $r++;
        }

        // 바이어 전자서명 — 라벨 행 위, 서명 이미지 아래(높은 행)
        $labelRow = $r + 1;
        $sheet->setCellValue("A{$labelRow}", "Buyer's signature");
        $sheet->getStyle("A{$labelRow}")->getFont()->setBold(true);

        $sigRow = $labelRow + 1;
        $sheet->getRowDimension($sigRow)->setRowHeight(90);

        $tmpPng = tempnam(sys_get_temp_dir(), 'sig_').'.png';
        file_put_contents($tmpPng, $signaturePng);
        register_shutdown_function(static fn () => @unlink($tmpPng));

        $drawing = new Drawing;
        $drawing->setPath($tmpPng);
        $drawing->setCoordinates("A{$sigRow}");
        $drawing->setOffsetX(6);
        $drawing->setOffsetY(4);
        $drawing->setResizeProportional(true);
        $drawing->setHeight(80);
        $drawing->setWorksheet($sheet);

        $note = $sigRow + 2;
        $sheet->setCellValue("A{$note}", 'This certificate binds the electronic signature to the document above by its SHA-256 hash. '
            .'A copy is emailed to the signer as delivery evidence.');
        $sheet->mergeCells("A{$note}:B{$note}");
        $sheet->getStyle("A{$note}")->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle("A{$note}")->getAlignment()->setWrapText(true);
    }
}
