<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\BuyerCashReceipt;
use App\Models\ExportLog;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\BuyerAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 바이어 정산현황 엑셀 — GET /erp/buyer-account/export?buyer=&axis=
 * 기획 = docs/design/buyer-cash-ledger.md
 *
 * 🚨 **화면과 같은 `BuyerAccountService` 를 쓴다.** 조건을 여기 옮겨 적으면
 *    「화면엔 3대인데 엑셀엔 300대」가 된다 — 에러 없이 조용히(SKILLS §9).
 *
 * 시트 2장: ①미수 차량 ②묶음별(화면에서 고른 축).
 */
class BuyerAccountExportController extends Controller
{
    public function download(Request $request, BuyerAccountService $service): StreamedResponse
    {
        abort_unless(Setting::buyerCashEnabled(), 404);

        $user = $request->user();
        $buyer = Buyer::findOrFail((int) $request->query('buyer'));

        $axis = (string) $request->query('axis', 'container');
        $axis = array_key_exists($axis, BuyerAccountService::AXES) ? $axis : 'container';

        // 🚨 화면 필터를 그대로 받는다 — 안 받으면 「화면엔 3대인데 엑셀엔 300대」가 된다(SKILLS §9).
        $search = (string) $request->query('q', '');
        $vin = (string) $request->query('vin', '');

        $vehicles = $service->unpaidVehicles($buyer, $search, $vin);
        $groups = $service->groupsBy($axis, $vehicles);
        $cash = $service->cashByCurrency($buyer);

        $ss = new Spreadsheet;
        $this->buildVehicleSheet($ss->getActiveSheet(), $buyer, $vehicles, $cash);
        $this->buildGroupSheet($ss->createSheet(), $axis, $groups);
        // 「이 입금이 어느 차에 얼마」 — 이 기능의 원래 요구. 검색과 무관하게 전부 넣는다
        //   (현금 원장이라 일부만 넣으면 남은 현금과 안 맞는 표가 된다).
        $this->buildUsageSheet($ss->createSheet(), $service->cashUsage($buyer));
        $ss->setActiveSheetIndex(0);

        ExportLog::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'target' => 'buyer_account',
            'scope' => 'buyer',
            'row_count' => $vehicles->count(),
            'columns' => array_values($this->columns()),
            'filters' => array_filter(['buyer' => $buyer->name, 'axis' => $axis, 'q' => $search, 'vin' => $vin]),
        ]);

        $filename = '바이어정산현황_'.$buyer->name.'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(
            function () use ($ss) {
                (new Xlsx($ss))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array<string,string> */
    private function columns(): array
    {
        return [
            'vehicle_number' => '차량번호',
            'chassis_number' => '차대번호',
            'progress' => '진행상태',
            'currency' => '통화',
            'total' => '총판매가',
            'received' => '받은 돈',
            'unpaid' => '남은 금액',
            'container_number' => '컨테이너번호',
            'export_declaration_number' => '수출신고번호',
            'bl_number' => 'B/L번호',
            'vessel_name' => '선박명',
        ];
    }

    /** @param  Collection<int, Vehicle>  $vehicles */
    private function buildVehicleSheet(Worksheet $sheet, Buyer $buyer, $vehicles, array $cash): void
    {
        $sheet->setTitle('미수 차량');

        // 머리말 — 이 파일이 누구의 어느 시점 현황인지가 파일 안에 남아야 한다.
        $this->text($sheet, 'A1', '바이어');
        $this->text($sheet, 'B1', $buyer->name);
        $this->text($sheet, 'A2', '기준 시각');
        $this->text($sheet, 'B2', now()->format('Y-m-d H:i'));
        $row = 3;
        foreach ($cash as $currency => $c) {
            $this->text($sheet, 'A'.$row, '남은 현금 '.$currency);
            $sheet->setCellValue('B'.$row, $c['remaining']);
            $row++;
        }
        $row++;

        $head = array_values($this->columns());
        foreach ($head as $i => $label) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).$row, $label);
        }
        $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($head)).$row)
            ->getFont()->setBold(true);
        $row++;

        foreach ($vehicles as $v) {
            $total = (float) $v->sale_total_amount;
            $unpaid = (float) $v->sale_unpaid_amount;
            $this->text($sheet, 'A'.$row, (string) $v->vehicle_number);
            $this->text($sheet, 'B'.$row, (string) $v->nice_reg_vin);
            $this->text($sheet, 'C'.$row, (string) $v->progress_status_cache);
            $this->text($sheet, 'D'.$row, (string) $v->currency);
            $sheet->setCellValue('E'.$row, $total);
            $sheet->setCellValue('F'.$row, round($total - $unpaid, 2));
            $sheet->setCellValue('G'.$row, $unpaid);
            $this->text($sheet, 'H'.$row, (string) $v->container_number);
            $this->text($sheet, 'I'.$row, (string) $v->export_declaration_number);
            $this->text($sheet, 'J'.$row, (string) $v->bl_number);
            $this->text($sheet, 'K'.$row, (string) $v->vessel_name);
            $row++;
        }

        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function buildGroupSheet(Worksheet $sheet, string $axis, array $groups): void
    {
        $sheet->setTitle('묶음별');
        $axisLabel = __('buyer_account.axis.'.$axis);

        foreach ([$axisLabel, '통화', '대수', '남은 금액', '차량'] as $i => $label) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).'1', $label);
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);

        $row = 2;
        foreach ($groups as $g) {
            $this->text($sheet, 'A'.$row, $g['key'] === '' ? __('buyer_account.unassigned') : $g['key']);
            $this->text($sheet, 'B'.$row, $g['currency']);
            $sheet->setCellValue('C'.$row, $g['count']);
            $sheet->setCellValue('D'.$row, $g['unpaid']);
            $this->text($sheet, 'E'.$row, implode(', ', $g['vehicles']));
            $row++;
        }

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** @param  Collection<int, BuyerCashReceipt>  $receipts */
    private function buildUsageSheet(Worksheet $sheet, $receipts): void
    {
        $sheet->setTitle('현금 사용');

        foreach (['입금일', '입금액', '통화', '입금 메모', '차량번호', '차대번호', '수금일', '배분액'] as $i => $label) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($i + 1).'1', $label);
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $row = 2;
        foreach ($receipts as $r) {
            // 아직 안 쓰인 입금도 한 줄 남긴다 — 안 남기면 「받은 돈」이 엑셀에서 사라진다.
            $allocations = $r->allocations->all() ?: [null];
            foreach ($allocations as $a) {
                $this->text($sheet, 'A'.$row, $r->received_date->format('Y-m-d'));
                $sheet->setCellValue('B'.$row, (float) $r->amount);
                $this->text($sheet, 'C'.$row, (string) $r->currency);
                $this->text($sheet, 'D'.$row, (string) ($r->note ?? ''));
                $this->text($sheet, 'E'.$row, (string) ($a?->vehicle?->vehicle_number ?? ''));
                $this->text($sheet, 'F'.$row, (string) ($a?->vehicle?->nice_reg_vin ?? ''));
                $this->text($sheet, 'G'.$row, (string) ($a?->finalPayment?->payment_date?->format('Y-m-d') ?? ''));
                if ($a) {
                    $sheet->setCellValue('H'.$row, (float) $a->amount);
                }
                $row++;
            }
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * 문자열 셀 — 반드시 TYPE_STRING 으로 쓴다.
     * `=`·`+`·`-`·`@` 로 시작하는 값이 수식으로 실행되는 것(formula injection)을 막고,
     * 컨테이너번호·B/L 처럼 숫자로 보이는 코드가 수로 변해 앞자리 0 이 날아가는 것도 막는다.
     */
    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }
}
