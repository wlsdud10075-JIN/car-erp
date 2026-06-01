<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 헤이맨 수출차량현황표(xlsx) → car-erp 차량 일괄 import.
 *
 * 범위 = Option A (2026-06-01 사용자 결정): 차량 마스터 + 핵심 재무 + 당사자 + 진행 관련 입력값.
 *   - 입금 이력(AO~AX)·완료 정산(paid) 재현은 2단계로 분리 (미포함).
 *   - 수식/계산 컬럼(R·AD·AM·BG·BH·BV·BX·BY·CA~CD·CE·CF·CI)은 import 제외 — car-erp 자동 계산.
 *
 * 데이터 정책 (2026-06-01 사용자 결정):
 *   - 담당자(salesman): 자동 생성 안 함. 이름 lookup, 미등록이면 차단(사용자가 type 직접 지정해 먼저 생성).
 *   - 바이어/컨사이니: 이름 기준 lookup-or-create.
 *   - 손상/비정형 값: 못 읽는 날짜→null, 금액칸 텍스트→0, 부분 RRN→그대로. 행은 import, 리포트로 추적.
 *   - 셀 값은 계산된 값(getCalculatedValue)으로 읽음 — 입력열에도 수식이 섞여 있으므로.
 *
 * 적재 방식: 완료·정산된 과거 데이터라 모델 이벤트를 끈 채(withoutEvents) 최종 상태를 기입하고
 *   이후 캐시(progress_status_cache / 미수 / 리스크)를 명시 재계산한다. (의도적 가드 우회 — 마이그레이션)
 *
 * 원본 시트: '수출차량매입-2026', R1=그룹헤더, R2=컬럼명, R3+=데이터. vehicle_number(D) = 멱등 키.
 *
 *   php artisan vehicles:import "C:/Users/User/Desktop/0. 헤이맨 수출차량현황표.xlsx" --dry-run
 *   php artisan vehicles:import "..."            # 실제 import (확인 프롬프트)
 */
class ImportVehicles extends Command
{
    protected $signature = 'vehicles:import
        {path : 엑셀 파일 경로}
        {--sheet= : 시트명 (기본=첫 시트)}
        {--header-row=2 : 컬럼명 행 번호}
        {--data-start=3 : 데이터 시작 행 번호}
        {--dry-run : 검증 + 리포트만 (DB 수정 없음)}
        {--force : 검증 통과 후 확인 프롬프트 생략}
        {--samples=5 : 이슈 유형별 표시 샘플 수}';

    protected $description = '헤이맨 수출차량현황표 xlsx → 차량 일괄 import (Option A: 마스터+핵심재무)';

    /**
     * Option A 컬럼맵. type = str|int|num|date|rrn. 수식/계산 컬럼은 제외.
     *
     * @var array<string, array{col:string, type:string, label:string}>
     */
    private const MAP = [
        'vehicle_number' => ['col' => 'D', 'type' => 'str', 'label' => '차량번호'],
        'brand' => ['col' => 'E', 'type' => 'str', 'label' => '브랜드'],
        'model_type' => ['col' => 'F', 'type' => 'str', 'label' => '차명'],
        'year' => ['col' => 'G', 'type' => 'int', 'label' => '년식'],
        'mileage' => ['col' => 'H', 'type' => 'int', 'label' => '주행거리'],
        'nice_reg_vin' => ['col' => 'I', 'type' => 'str', 'label' => '차대번호'],
        'purchase_date' => ['col' => 'B', 'type' => 'date', 'label' => '구입일자'],
        'salesman' => ['col' => 'J', 'type' => 'str', 'label' => '담당자'],
        'purchase_from' => ['col' => 'K', 'type' => 'str', 'label' => '구입처'],
        'nice_reg_owner_name' => ['col' => 'L', 'type' => 'str', 'label' => '소유자'],
        'nice_reg_owner_rrn' => ['col' => 'M', 'type' => 'rrn', 'label' => '주민(법인)등록번호'],
        'nice_reg_owner_addr' => ['col' => 'N', 'type' => 'str', 'label' => '사용본거지'],
        'purchase_remittance_memo' => ['col' => 'O', 'type' => 'str', 'label' => '송금내역확인'],
        'purchase_price' => ['col' => 'P', 'type' => 'num', 'label' => '구입금액'],
        'selling_fee' => ['col' => 'Q', 'type' => 'num', 'label' => '매도비'],
        'deregistration_date' => ['col' => 'T', 'type' => 'date', 'label' => '말소일자'],
        'cost_deregistration' => ['col' => 'BM', 'type' => 'num', 'label' => '말소비'],
        'cost_license' => ['col' => 'BN', 'type' => 'num', 'label' => '면허비'],
        'cost_towing' => ['col' => 'BO', 'type' => 'num', 'label' => '탁송비'],
        'cost_carry' => ['col' => 'BP', 'type' => 'num', 'label' => '캐리비'],
        'cost_shoring' => ['col' => 'BQ', 'type' => 'num', 'label' => '쇼링비'],
        'cost_insurance' => ['col' => 'BR', 'type' => 'num', 'label' => '보험료'],
        'cost_transfer' => ['col' => 'BS', 'type' => 'num', 'label' => '이전비'],
        'cost_extra1' => ['col' => 'BT', 'type' => 'num', 'label' => '기타1'],
        'cost_extra2' => ['col' => 'BU', 'type' => 'num', 'label' => '기타2'],
        'currency' => ['col' => 'AE', 'type' => 'str', 'label' => '통화'],
        'sale_price' => ['col' => 'AF', 'type' => 'num', 'label' => '판매금액'],
        'exchange_rate' => ['col' => 'AG', 'type' => 'num', 'label' => '환율'],
        'commission' => ['col' => 'AH', 'type' => 'num', 'label' => '커미션'],
        'auto_loading' => ['col' => 'AI', 'type' => 'num', 'label' => 'Auto Loading'],
        'tax_dc' => ['col' => 'AJ', 'type' => 'num', 'label' => 'TAX/D.C'],
        'transport_fee' => ['col' => 'AK', 'type' => 'num', 'label' => '운임비'],
        'export_declaration_amount' => ['col' => 'AA', 'type' => 'num', 'label' => '면장금액'],
        'buyer' => ['col' => 'AB', 'type' => 'str', 'label' => '바이어'],
        'consignee' => ['col' => 'AC', 'type' => 'str', 'label' => '컨사이니'],
        'shipping_date' => ['col' => 'W', 'type' => 'date', 'label' => '선적일자ETD'],
        'eta_date' => ['col' => 'Y', 'type' => 'date', 'label' => '도착일자ETA'],
        'bl_number' => ['col' => 'X', 'type' => 'str', 'label' => '비엘'],
        'vessel_name' => ['col' => 'Z', 'type' => 'str', 'label' => '컨테이너/VSL'],
        'memo' => ['col' => 'CK', 'type' => 'str', 'label' => '비고'],
    ];

    private const VALID_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    /** 차량 컬럼으로 직접 들어가는 필드 (salesman/buyer/consignee 는 별도 resolve) */
    private const VEHICLE_FIELDS = [
        'vehicle_number', 'brand', 'model_type', 'year', 'mileage', 'nice_reg_vin',
        'purchase_date', 'purchase_from', 'nice_reg_owner_name', 'nice_reg_owner_rrn',
        'nice_reg_owner_addr', 'purchase_remittance_memo', 'purchase_price', 'selling_fee',
        'deregistration_date', 'cost_deregistration', 'cost_license', 'cost_towing',
        'cost_carry', 'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1',
        'cost_extra2', 'currency', 'sale_price', 'exchange_rate', 'commission', 'auto_loading',
        'tax_dc', 'transport_fee', 'export_declaration_amount', 'shipping_date', 'eta_date',
        'bl_number', 'vessel_name', 'memo',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! file_exists($path)) {
            $this->error("파일을 찾을 수 없습니다: {$path}");

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);   // 계산된 값을 얻기 위해 수식 로드
        $book = $reader->load($path);
        $sheet = $this->option('sheet') ? $book->getSheetByName($this->option('sheet')) : $book->getSheet(0);
        if (! $sheet) {
            $this->error('시트를 찾을 수 없습니다.');

            return self::FAILURE;
        }

        $dataStart = (int) $this->option('data-start');
        $this->line("시트=[{$sheet->getTitle()}] dataStart={$dataStart} highestRow={$sheet->getHighestDataRow()}");

        // 파싱 (계산된 값 + 정제)
        [$rows, $report] = $this->parseAll($sheet, $dataStart);
        $this->printReport($report);

        // 담당자 미등록 차단 (사용자가 type 직접 지정해 먼저 생성)
        $salesmanByName = Salesman::query()->pluck('id', 'name')->all();
        $missingSalesmen = array_values(array_unique(array_filter(
            array_map(fn ($r) => $r['salesman'] ?? '', $rows),
            fn ($n) => $n !== '' && ! isset($salesmanByName[$n])
        )));
        if (! empty($missingSalesmen)) {
            $this->newLine();
            $this->error('미등록 담당자 — 담당자 관리에서 원하는 정산 type(프리/사내)으로 먼저 생성 후 재시도:');
            foreach ($missingSalesmen as $n) {
                $this->line("  • {$n}");
            }
            if (! $this->option('dry-run')) {
                return self::FAILURE;
            }
        }

        if ($report['blockers'] > 0) {
            $this->error('🚫 차단 이슈 존재 — 해결 후 재시도.');
            if (! $this->option('dry-run')) {
                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('--dry-run — DB 수정 없이 종료');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("위 {$report['rowCount']}행을 import(신규/갱신) 할까요?", true)) {
            $this->info('취소됨');

            return self::SUCCESS;
        }

        return $this->import($rows, $salesmanByName);
    }

    /**
     * 모델 이벤트를 끈 채 최종 상태 기입 → 이후 캐시 재계산.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,int>  $salesmanByName
     */
    private function import(array $rows, array $salesmanByName): int
    {
        $stats = ['new' => 0, 'updated' => 0, 'buyer_new' => 0, 'consignee_new' => 0, 'sale_held' => 0];
        $touchedIds = [];

        DB::transaction(function () use ($rows, $salesmanByName, &$stats, &$touchedIds) {
            Model::withoutEvents(function () use ($rows, $salesmanByName, &$stats, &$touchedIds) {
                $buyerCache = [];
                foreach ($rows as $row) {
                    if (($row['vehicle_number'] ?? '') === '') {
                        continue;
                    }

                    // 바이어 lookup-or-create
                    $buyerId = null;
                    $bn = $row['buyer'] ?? '';
                    if ($bn !== '') {
                        if (! isset($buyerCache[$bn])) {
                            $buyer = Buyer::where('name', $bn)->first();
                            if (! $buyer) {
                                $buyer = Buyer::create([
                                    'name' => $bn,
                                    'salesman_id' => $salesmanByName[$row['salesman'] ?? ''] ?? null,
                                    'is_active' => true,
                                ]);
                                $stats['buyer_new']++;
                            }
                            $buyerCache[$bn] = $buyer;
                        }
                        $buyerId = $buyerCache[$bn]->id;
                    }

                    // 컨사이니 lookup-or-create (바이어 종속)
                    $consigneeId = null;
                    $cn = $row['consignee'] ?? '';
                    if ($cn !== '' && $buyerId) {
                        $consignee = Consignee::where('buyer_id', $buyerId)->where('name', $cn)->first();
                        if (! $consignee) {
                            $consignee = Consignee::create([
                                'buyer_id' => $buyerId,
                                'name' => $cn,
                                'is_active' => true,
                            ]);
                            $stats['consignee_new']++;
                        }
                        $consigneeId = $consignee->id;
                    }

                    // 차량 속성
                    $attrs = [
                        'sales_channel' => 'export',
                        'progress_status_rule_version' => 4,
                        'salesman_id' => $salesmanByName[$row['salesman'] ?? ''] ?? null,
                        'buyer_id' => $buyerId,
                        'consignee_id' => $consigneeId,
                        'is_deregistered' => ! empty($row['deregistration_date']),
                    ];
                    foreach (self::VEHICLE_FIELDS as $f) {
                        if ($f === 'vehicle_number') {
                            continue;
                        }
                        $attrs[$f] = $row[$f] ?? null;
                    }
                    if (empty($attrs['currency'])) {
                        $attrs['currency'] = 'USD';
                    }

                    // chk_sale_required: sale_price>0 → sale_date + buyer_id + exchange_rate>0 필수.
                    // 판매일자 컬럼이 엑셀에 없음 → 선적일(W) 없으면 구입일(B). KRW 환율 미입력 시 1.
                    if ((float) ($attrs['sale_price'] ?? 0) > 0) {
                        $attrs['sale_date'] = $attrs['shipping_date'] ?: ($attrs['purchase_date'] ?: null);
                        if (empty($attrs['exchange_rate']) && $attrs['currency'] === 'KRW') {
                            $attrs['exchange_rate'] = 1;
                        }
                        $complete = $attrs['sale_date'] && $buyerId && (float) ($attrs['exchange_rate'] ?? 0) > 0;
                        if (! $complete) {
                            // 판매 필수값 불완전 → 판매 보류(매입만 import). 추후 보정.
                            $attrs['sale_price'] = 0;
                            $attrs['sale_date'] = null;
                            $stats['sale_held']++;
                            $this->warn("  판매정보 불완전 → 판매가 보류(매입만): {$row['vehicle_number']}");
                        }
                    } else {
                        $attrs['sale_date'] = null;
                    }

                    $existing = Vehicle::withTrashed()->where('vehicle_number', $row['vehicle_number'])->first();
                    if ($existing) {
                        $existing->forceFill($attrs)->save();
                        $vehicle = $existing;
                        $stats['updated']++;
                    } else {
                        $vehicle = new Vehicle;
                        $vehicle->forceFill(array_merge(['vehicle_number' => $row['vehicle_number']], $attrs));
                        $vehicle->save();
                        $stats['new']++;
                    }
                    $touchedIds[] = $vehicle->id;
                }
            });
        });

        // 캐시 재계산 (saving 훅을 우회했으므로 명시 호출)
        $this->line('캐시 재계산 중...');
        Vehicle::whereIn('id', $touchedIds)->get()->each(fn (Vehicle $v) => $v->refreshCaches());

        $this->newLine();
        $this->info('✅ import 완료');
        $this->line("  차량 신규 {$stats['new']} / 갱신 {$stats['updated']}");
        $this->line("  바이어 신규 {$stats['buyer_new']} / 컨사이니 신규 {$stats['consignee_new']}");
        $this->warn('  ⚠️ 입금이력·완료정산(paid)은 미포함(2단계) — 진행상태/미수는 입금 전 상태로 표시됨.');

        return self::SUCCESS;
    }

    /**
     * 전체 행 파싱 (계산된 값) + 품질 이슈 수집.
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>}
     */
    private function parseAll(Worksheet $sheet, int $dataStart): array
    {
        $highest = $sheet->getHighestDataRow();
        $samples = (int) $this->option('samples');

        $rows = [];
        $rowCount = 0;
        $blankRows = 0;
        $issues = [];
        $addIssue = function (string $type, string $detail) use (&$issues, $samples) {
            $issues[$type] ??= ['count' => 0, 'samples' => []];
            $issues[$type]['count']++;
            if (count($issues[$type]['samples']) < $samples) {
                $issues[$type]['samples'][] = $detail;
            }
        };

        $existingPlates = Vehicle::withTrashed()->pluck('vehicle_number')->flip()->all();
        $salesmanByName = Salesman::query()->pluck('id', 'name')->all();
        $buyerByName = Buyer::query()->pluck('id', 'name')->all();

        $seenPlates = [];
        $newSalesmen = [];
        $newBuyers = [];
        $currencies = [];

        for ($r = $dataStart; $r <= $highest; $r++) {
            $rawPlate = $this->cell($sheet, self::MAP['vehicle_number']['col'], $r);
            $rawPrice = $this->cell($sheet, self::MAP['purchase_price']['col'], $r);
            // 차량번호 없는 행 = 빈 행 또는 합계/footer 행 → import 불가(키 부재)라 스킵.
            if ($rawPlate === '') {
                if ($rawPrice !== '') {
                    $addIssue('차량번호 없는 행(스킵 — 합계/footer 추정)', "R{$r}");
                }
                $blankRows++;

                continue;
            }
            $rowCount++;

            $row = ['_r' => $r];

            if (isset($seenPlates[$rawPlate])) {
                $addIssue('파일 내 차량번호 중복(차단)', "R{$r}: {$rawPlate} (이전 R{$seenPlates[$rawPlate]})");
            } else {
                $seenPlates[$rawPlate] = $r;
                if (isset($existingPlates[$rawPlate])) {
                    $addIssue('DB 기존 차량번호(=갱신)', "R{$r}: {$rawPlate}");
                }
            }

            foreach (self::MAP as $field => $def) {
                $raw = $this->cell($sheet, $def['col'], $r);
                if ($raw === '') {
                    $row[$field] = $def['type'] === 'num' ? 0 : null;
                    if ($field === 'vehicle_number' || $field === 'currency' || $def['type'] === 'str') {
                        $row[$field] = $def['type'] === 'num' ? 0 : ($raw === '' ? '' : $raw);
                    }

                    continue;
                }
                switch ($def['type']) {
                    case 'date':
                        [$v, $w] = $this->parseDate($raw);
                        if ($w !== null) {
                            $addIssue("날짜 파싱 실패→null: {$def['label']}", "R{$r}{$def['col']}: '{$raw}'");
                        }
                        $row[$field] = $v;
                        break;
                    case 'num':
                    case 'int':
                        [$v, $w] = $this->parseNum($raw);
                        if ($w !== null) {
                            $addIssue("숫자 파싱 실패→0: {$def['label']}", "R{$r}{$def['col']}: '{$raw}'");
                            $v = 0;
                        }
                        $row[$field] = $def['type'] === 'int' ? (int) $v : $v;
                        break;
                    case 'rrn':
                        if (! preg_match('/^\d{6}-\d{7}$/', $raw)) {
                            $addIssue('RRN 형식 비표준(그대로 저장)', "R{$r}: '{$raw}'");
                        }
                        $row[$field] = $raw;
                        break;
                    default:
                        $row[$field] = $raw;
                }
            }

            // 통화
            $cur = $row['currency'] ?? '';
            if ($cur !== '' && $cur !== null) {
                $currencies[$cur] = ($currencies[$cur] ?? 0) + 1;
                if (! in_array($cur, self::VALID_CURRENCIES, true)) {
                    $addIssue('통화 enum 외→USD', "R{$r}: '{$cur}'");
                    $row['currency'] = null;   // import 시 USD fallback
                }
            }

            // 담당자/바이어 신규 집계
            if (($row['salesman'] ?? '') !== '' && ! isset($salesmanByName[$row['salesman']])) {
                $newSalesmen[$row['salesman']] = true;
            }
            if (($row['buyer'] ?? '') !== '' && ! isset($buyerByName[$row['buyer']])) {
                $newBuyers[$row['buyer']] = true;
            }

            $rows[] = $row;
        }

        $blockers = ($issues['파일 내 차량번호 중복(차단)']['count'] ?? 0);

        return [$rows, compact('rowCount', 'blankRows', 'issues', 'newSalesmen', 'newBuyers', 'currencies', 'blockers')];
    }

    /** @param array<string,mixed> $report */
    private function printReport(array $report): void
    {
        $this->newLine();
        $this->info("📊 데이터 품질 — 데이터 {$report['rowCount']}행 (빈 행 {$report['blankRows']} 스킵)");
        $this->line('통화: '.($report['currencies'] ? json_encode($report['currencies'], JSON_UNESCAPED_UNICODE) : '없음'));
        $this->line('신규 담당자 '.count($report['newSalesmen']).'종: '.implode(', ', array_keys($report['newSalesmen'])));
        $this->line('신규 바이어 '.count($report['newBuyers']).'종: '.implode(', ', array_slice(array_keys($report['newBuyers']), 0, 15)));

        $this->newLine();
        if (empty($report['issues'])) {
            $this->info('✅ 파싱 이슈 없음');
        } else {
            $this->warn('이슈 유형별:');
            foreach ($report['issues'] as $type => $info) {
                $this->line("  • {$type}: {$info['count']}건");
                foreach ($info['samples'] as $s) {
                    $this->line("      - {$s}");
                }
            }
        }
    }

    /** @return array{0:?string,1:?string} */
    private function parseDate(string $raw): array
    {
        $s = trim($raw);
        if ($s === '' || $s === '-') {
            return [null, null];
        }
        if (is_numeric($s)) {
            try {
                return [ExcelDate::excelToDateTimeObject((float) $s)->format('Y-m-d'), null];
            } catch (\Throwable $e) {
                return [null, '시리얼 변환 실패'];
            }
        }
        if (preg_match('/^(\d{2,4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})\.?$/', $s, $m)) {
            $y = (int) $m[1];
            $y = $y < 100 ? 2000 + $y : $y;
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                return [sprintf('%04d-%02d-%02d', $y, $mo, $d), null];
            }

            return [null, '유효하지 않은 날짜'];
        }

        return [null, '형식 불명'];
    }

    /** @return array{0:int|float|null,1:?string} */
    private function parseNum(string $raw): array
    {
        $s = trim($raw);
        if ($s === '' || $s === '-') {
            return [0, null];
        }
        $clean = str_replace([',', ' ', '원'], '', $s);
        if (! is_numeric($clean)) {
            return [null, '숫자 아님'];
        }

        return [$clean + 0, null];
    }

    private function cell(Worksheet $sheet, string $col, int $row): string
    {
        try {
            $v = $sheet->getCell($col.$row)->getCalculatedValue();
        } catch (\Throwable $e) {
            $v = $sheet->getCell($col.$row)->getValue();
        }

        return $v === null ? '' : trim((string) $v);
    }
}
