<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\Salesman;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 컨사이니 일괄 업로드 양식 → 바이어 + 컨사이니 일괄 import.
 * deep-interview 2026-05-28 결정 사항 구현.
 *
 * 양식 12컬럼 (컨사이니_업로드양식_v2.xlsx):
 *   A:바이어명* B:컨사이니명* C:국가 D:EORI E:TAX F:ID종류 G:ID번호
 *   H:전화 I:이메일 J:영업담당자 K:주소 L:메모
 *
 * 동작:
 *   - 바이어: name 으로 find or create (없으면 신규, name + salesman_id 만 채움)
 *   - 컨사이니: 매 행 신규 create (중복 검사 안 함 — 같은 이름 다른 행 = 다른 컨사이니로 간주)
 *   - 국가: countries.name lookup, 실패 시 경고 (country_id = null)
 *   - 영업담당자: salesmen.name lookup, 실패 시 에러 차단
 *   - 같은 바이어명에 다른 영업담당자 = 에러 차단 (일관성 강제)
 *
 *   php artisan consignees:import "C:/path/to/file.xlsx"
 *   php artisan consignees:import "..." --dry-run
 *   php artisan consignees:import "..." --default-salesman=3   # J 컬럼 비어있을 때 fallback
 */
class ImportConsignees extends Command
{
    protected $signature = 'consignees:import
        {path : 엑셀 파일 경로 (예: C:/Users/User/Desktop/컨사이니_업로드양식_v2.xlsx)}
        {--dry-run : 검증만 수행 (DB 수정 없음)}
        {--default-salesman= : 양식 J(영업담당자) 비어있을 때 적용할 salesmen.id}
        {--force : 검증 통과 후 확인 프롬프트 생략}';

    protected $description = '컨사이니 양식 xlsx → 바이어 + 컨사이니 일괄 import';

    private const EXPECTED_HEADERS = [
        'A' => '바이어명*',
        'B' => '컨사이니명*',
        'C' => '국가',
        'D' => 'EORI NUMBER',
        'E' => 'TAX NUMBER',
        'F' => 'ID종류',
        'G' => 'ID번호',
        'H' => '전화',
        'I' => '이메일',
        'J' => '영업담당자',
        'K' => '주소',
        'L' => '메모',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! file_exists($path)) {
            $this->error("파일을 찾을 수 없습니다: {$path}");

            return self::FAILURE;
        }

        $sheet = IOFactory::load($path)->getActiveSheet();
        $headerWarnings = $this->checkHeaders($sheet);
        if (! empty($headerWarnings)) {
            $this->warn('헤더 검증 경고:');
            foreach ($headerWarnings as $w) {
                $this->line("  - {$w}");
            }
        }

        // 1. 행 파싱 + 검증
        $rows = $this->parseRows($sheet);
        if (empty($rows)) {
            $this->error('데이터 행이 없습니다 (헤더만 있거나 빈 양식).');

            return self::FAILURE;
        }

        // 2. lookup 캐시 (countries, salesmen)
        $countryByName = Country::query()->pluck('id', 'name')->all();
        $salesmanByName = Salesman::query()->pluck('id', 'name')->all();
        $defaultSalesmanId = $this->option('default-salesman')
            ? (int) $this->option('default-salesman') : null;

        // 3. 검증 (errors = 차단, warnings = 진행 가능)
        $errors = [];
        $warnings = [];
        $buyerSalesmen = []; // 바이어명 → salesman_id (일관성 검증용)

        foreach ($rows as $i => $row) {
            $rNum = $i + 2; // 1행이 헤더라 +2

            if (empty($row['buyer_name'])) {
                $errors[] = "R{$rNum}: 바이어명(A) 비어있음";

                continue;
            }
            if (empty($row['consignee_name'])) {
                $errors[] = "R{$rNum}: 컨사이니명(B) 비어있음";

                continue;
            }

            // 국가 lookup
            if (! empty($row['country']) && ! isset($countryByName[$row['country']])) {
                $warnings[] = "R{$rNum}: 국가 '{$row['country']}' 미등록 → country_id=null";
            }

            // 영업담당자 lookup
            $salesmanId = null;
            if (! empty($row['salesman'])) {
                if (! isset($salesmanByName[$row['salesman']])) {
                    $errors[] = "R{$rNum}: 영업담당자 '{$row['salesman']}' 미등록 → salesmen 등록 후 재시도";

                    continue;
                }
                $salesmanId = $salesmanByName[$row['salesman']];
            } elseif ($defaultSalesmanId) {
                $salesmanId = $defaultSalesmanId;
            }

            // 바이어명 일관성 (같은 바이어, 다른 영업담당자 검출)
            $bn = $row['buyer_name'];
            if (isset($buyerSalesmen[$bn])) {
                if ($buyerSalesmen[$bn] !== $salesmanId) {
                    $errors[] = "R{$rNum}: 바이어 '{$bn}' 영업담당자 모순 (이전 행={$buyerSalesmen[$bn]}, 이번 행={$salesmanId})";
                }
            } else {
                $buyerSalesmen[$bn] = $salesmanId;
            }

            // ID종류 검증
            if (! empty($row['id_type']) && ! isset(Consignee::ID_TYPES[$row['id_type']])) {
                $errors[] = "R{$rNum}: ID종류 '{$row['id_type']}' 잘못됨 (rrn/passport/business 중 하나)";
            }

            $rows[$i]['country_id'] = $countryByName[$row['country']] ?? null;
            $rows[$i]['salesman_id'] = $salesmanId;
        }

        // 4. 리포트
        $this->newLine();
        $this->info("총 {$this->countNonEmpty($rows)} 행 파싱");
        $this->info('바이어 신규 후보: '.count($buyerSalesmen).'명');
        if (! empty($errors)) {
            $this->error('차단 에러 '.count($errors).'건:');
            foreach ($errors as $e) {
                $this->line("  ❌ {$e}");
            }

            return self::FAILURE;
        }
        if (! empty($warnings)) {
            $this->warn('경고 '.count($warnings).'건 (진행 가능):');
            foreach ($warnings as $w) {
                $this->line("  ⚠️ {$w}");
            }
        }
        if ($this->option('dry-run')) {
            $this->info('--dry-run 모드 — DB 수정 없이 종료');

            return self::SUCCESS;
        }

        // 5. 확인
        if (! $this->option('force') && ! $this->confirm('위 내용으로 import 실행할까요?', true)) {
            $this->info('취소됨');

            return self::SUCCESS;
        }

        // 6. import
        $stats = ['buyer_new' => 0, 'buyer_reuse' => 0, 'consignee_new' => 0];
        DB::transaction(function () use ($rows, &$stats) {
            $buyerCache = [];
            foreach ($rows as $row) {
                if (empty($row['buyer_name']) || empty($row['consignee_name'])) {
                    continue;
                }

                $bn = $row['buyer_name'];
                if (! isset($buyerCache[$bn])) {
                    $buyer = Buyer::where('name', $bn)->first();
                    if ($buyer) {
                        $stats['buyer_reuse']++;
                        if (! $buyer->salesman_id && $row['salesman_id']) {
                            $buyer->update(['salesman_id' => $row['salesman_id']]);
                        }
                    } else {
                        $buyer = Buyer::create([
                            'name' => $bn,
                            'salesman_id' => $row['salesman_id'],
                            'is_active' => true,
                        ]);
                        $stats['buyer_new']++;
                    }
                    $buyerCache[$bn] = $buyer;
                }

                Consignee::create([
                    'name' => $row['consignee_name'],
                    'buyer_id' => $buyerCache[$bn]->id,
                    'country_id' => $row['country_id'],
                    'id_type' => $row['id_type'] ?: null,
                    'id_value' => $row['id_value'] ?: null,
                    'eori_number' => $row['eori'] ?: null,
                    'tax_number' => $row['tax'] ?: null,
                    'contact_phone' => $row['phone'] ?: null,
                    'contact_email' => $row['email'] ?: null,
                    'address' => $row['address'] ?: null,
                    'memo' => $row['memo'] ?: null,
                    'is_active' => true,
                ]);
                $stats['consignee_new']++;
            }
        });

        $this->newLine();
        $this->info('✅ import 완료');
        $this->line("  바이어 신규: {$stats['buyer_new']}건");
        $this->line("  바이어 재사용: {$stats['buyer_reuse']}건");
        $this->line("  컨사이니 신규: {$stats['consignee_new']}건");

        return self::SUCCESS;
    }

    /** @return array<string> */
    private function checkHeaders(Worksheet $sheet): array
    {
        $warnings = [];
        foreach (self::EXPECTED_HEADERS as $col => $expected) {
            $actual = trim((string) $sheet->getCell($col.'1')->getValue());
            if ($actual !== $expected) {
                $warnings[] = "{$col}1: 기대='{$expected}' 실제='{$actual}'";
            }
        }

        return $warnings;
    }

    /** @return array<int, array<string, ?string>> */
    private function parseRows(Worksheet $sheet): array
    {
        $rows = [];
        $highest = $sheet->getHighestDataRow();
        for ($r = 2; $r <= $highest; $r++) {
            $row = [
                'buyer_name' => $this->cellStr($sheet, 'A', $r),
                'consignee_name' => $this->cellStr($sheet, 'B', $r),
                'country' => $this->cellStr($sheet, 'C', $r),
                'eori' => $this->cellStr($sheet, 'D', $r),
                'tax' => $this->cellStr($sheet, 'E', $r),
                'id_type' => $this->cellStr($sheet, 'F', $r),
                'id_value' => $this->cellStr($sheet, 'G', $r),
                'phone' => $this->cellStr($sheet, 'H', $r),
                'email' => $this->cellStr($sheet, 'I', $r),
                'salesman' => $this->cellStr($sheet, 'J', $r),
                'address' => $this->cellStr($sheet, 'K', $r),
                'memo' => $this->cellStr($sheet, 'L', $r),
            ];
            // 완전 빈 행은 스킵
            if (count(array_filter($row, fn ($v) => $v !== '' && $v !== null)) === 0) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function cellStr(Worksheet $sheet, string $col, int $row): string
    {
        $v = $sheet->getCell($col.$row)->getValue();

        return $v === null ? '' : trim((string) $v);
    }

    /** @param array<int, array<string, ?string>> $rows */
    private function countNonEmpty(array $rows): int
    {
        return count(array_filter($rows, fn ($r) => ! empty($r['buyer_name']) || ! empty($r['consignee_name'])));
    }
}
