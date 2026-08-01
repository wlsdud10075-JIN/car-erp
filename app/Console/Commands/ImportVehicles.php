<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 차량 적재양식(xlsx) → car-erp 차량 일괄 import.
 *
 * 레이아웃 단일 출처 = MAP + PAYMENT_SLOTS + HEADER_ONLY_COLUMNS.
 * VehicleTemplateExporter 가 같은 상수로 빈 양식을 내므로, 내려받아 채운 양식을 무수정으로 읽는다.
 * ⚠️ 2026-08-01 (jin) 부터 기준 레이아웃은 **ssancar 적재양식**이다. 구 헤이맨 수출차량현황표는
 *    선적 4열(W~Z)과 입금열이 달라 그대로는 못 읽는다 — 필요하면 그 파일을 새 레이아웃으로 옮겨서 올린다.
 *
 * 범위 = Option A (2026-06-01 사용자 결정): 차량 마스터 + 핵심 재무 + 당사자 + 진행 관련 입력값.
 *   - 입금 이력(PAYMENT_SLOTS)·완료 정산(paid) 재현은 2단계로 분리 (미포함).
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
 * 시트 구조: R1=형식 힌트, R2=컬럼명, R3+=데이터. 차대번호(I) 우선, 없으면 vehicle_number(D) 가 멱등 키.
 *
 *   php artisan vehicles:export-template   # 빈 양식 받기
 *   php artisan vehicles:import "차량적재양식.xlsx" --dry-run
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
        {--with-payments : 2단계 — 입금이력(PAYMENT_SLOTS) confirmed + 완료정산(paid, 프리50%) 재현 + B/L번호→거래완료}
        {--only= : 특정 차량번호만 import (쉼표구분). 신규 추가용 — 미지정 시 전체(기존 갱신 포함)}
        {--samples=5 : 이슈 유형별 표시 샘플 수}';

    protected $description = '차량 적재양식 xlsx → 차량 일괄 import (마스터+핵심재무). 레이아웃 = MAP·PAYMENT_SLOTS';

    /**
     * Option A 컬럼맵. type = str|int|num|date|rrn. 수식/계산 컬럼은 제외.
     *
     * @var array<string, array{col:string, type:string, label:string}>
     */
    public const MAP = [
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
        // 2026-08-01 (jin) — 선적 4열 재배정. 구 배정(W=ETD·X=비엘·Y=ETA·Z=VSL)은 헤이맨 수출차량현황표
        //   기준이었고, 앞으로 올릴 파일은 전부 아래 배정(ssancar 적재양식)을 쓴다.
        'bl_number' => ['col' => 'W', 'type' => 'str', 'label' => '비엘'],
        'vessel_name' => ['col' => 'X', 'type' => 'str', 'label' => '컨테이너/VSL'],
        'shipping_date' => ['col' => 'Y', 'type' => 'date', 'label' => '선적일자ETD'],
        'eta_date' => ['col' => 'Z', 'type' => 'date', 'label' => '도착일자ETA'],
        'export_declaration_number' => ['col' => 'V', 'type' => 'str', 'label' => '면장'],
        // 적립금 사용액 — 잔금을 적립금으로 결제한 분. 미수 계산에 직접 반영된다(SKILLS §13).
        //   원장(SavingsStatus USED)은 H6 가 아니라 import 가 직접 만든다 — withoutEvents 안이라 훅이 안 뜬다.
        'savings_used' => ['col' => 'AT', 'type' => 'num', 'label' => 'USE DEPOSIT(적립금)'],
        'memo' => ['col' => 'CK', 'type' => 'str', 'label' => '비고'],
    ];

    /**
     * 확정 입금 슬롯 — [금액열, 날짜열]. `--with-payments` 에서 FinalPayment(confirmed) 로 적재.
     * exporter 도 이 상수로 헤더·서식을 낸다(레이아웃 단일 출처).
     *
     * 2026-08-01 (jin) — 구 헤이맨 배정(정산1~5 = AO/AP·AQ/AR·AS/AT·AU/AV·AW/AX)에서
     * ssancar 적재양식(입금 = AP/AQ)으로 교체. 한 칸 밀려 있어 그대로 두면 **입금일이 금액으로,
     * deposit 금액이 날짜로** 조용히 들어갔다.
     */
    public const PAYMENT_SLOTS = [
        ['amount' => 'AP', 'date' => 'AQ', 'amount_label' => '입금', 'date_label' => '입금일'],
    ];

    /**
     * 적립금 **발생**(EARNED) 슬롯 — 잔금에서 남은 돈이 바이어 크레딧으로 적립된다 (jin 2026-08-01:
     * "deposit 은 적립금, use deposit 은 적립금 사용한 것").
     *
     * 실측 검산: 3·4·5행 적립 254×3 = 762 = 6행의 사용액. 그래서 **적립이 사용보다 먼저** 기록돼야
     * 잔액이 음수가 되지 않는다(DB CHECK) → import 는 차량 순서가 아니라 2패스로 처리한다.
     */
    public const SAVINGS_EARNED_SLOT = ['amount' => 'AR', 'date' => 'AS', 'amount_label' => 'deposit', 'date_label' => '입금일'];

    /**
     * 양식에는 있으나 적재하지 않는 열 — exporter 가 헤더만 낸다.
     * AU 사용일 : AT(적립금 사용)의 사용일. vehicles 에 대응 컬럼이 없어 보관만.
     */
    public const HEADER_ONLY_COLUMNS = [
        'AU' => '사용일',
    ];

    public const VALID_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    /** 차량 컬럼으로 직접 들어가는 필드 (salesman/buyer/consignee 는 별도 resolve) */
    private const VEHICLE_FIELDS = [
        'vehicle_number', 'brand', 'model_type', 'year', 'mileage', 'nice_reg_vin',
        'purchase_date', 'purchase_from', 'nice_reg_owner_name', 'nice_reg_owner_rrn',
        'nice_reg_owner_addr', 'purchase_remittance_memo', 'purchase_price', 'selling_fee',
        'deregistration_date', 'cost_deregistration', 'cost_license', 'cost_towing',
        'cost_carry', 'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1',
        'cost_extra2', 'currency', 'sale_price', 'exchange_rate', 'commission', 'auto_loading',
        'tax_dc', 'transport_fee', 'export_declaration_amount', 'shipping_date', 'eta_date',
        'bl_number', 'vessel_name', 'export_declaration_number', 'savings_used', 'memo',
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

        // --only: 지정 차량번호만 처리 (신규 추가용 — 기존 차량 갱신 방지)
        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        if ($only) {
            $onlySet = array_flip($only);
            $before = count($rows);
            $rows = array_values(array_filter($rows, fn ($r) => isset($onlySet[$r['vehicle_number'] ?? ''])));
            $report['rowCount'] = count($rows);
            $this->warn('--only 적용: '.count($rows)."행만 처리 (전체 {$before}행 중, 지정 ".count($only).'개). 기존 차량 무수정.');
            $found = array_column($rows, 'vehicle_number');
            $miss = array_values(array_diff($only, $found));
            if ($miss) {
                $this->warn('  파일에서 못 찾은 지정 차량번호: '.implode(', ', $miss));
            }
            // 필터 전 전체 기준 이슈는 오해 소지 → --only 에선 제거(실제 처리는 위 12행만).
            unset($report['issues']['DB 기존 차량번호(=갱신)'], $report['issues']['DB 기존 차대번호(=갱신)']);
        }

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
        $stats = ['new' => 0, 'updated' => 0, 'restored' => 0, 'buyer_new' => 0, 'consignee_new' => 0, 'sale_held' => 0, 'payments' => 0, 'settlements' => 0, 'savings' => 0];
        $touchedIds = [];
        $savingsQueue = [];
        $withPay = (bool) $this->option('with-payments');

        DB::transaction(function () use ($rows, $salesmanByName, $withPay, &$stats, &$touchedIds, &$savingsQueue) {
            Model::withoutEvents(function () use ($rows, $salesmanByName, $withPay, &$stats, &$touchedIds, &$savingsQueue) {
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

                    // 2단계 — B/L번호 마커로 거래완료 진입 (B/L 문서 파일이 엑셀에 없음).
                    if ($withPay && ! empty($row['bl_number']) && (float) ($attrs['sale_price'] ?? 0) > 0) {
                        $attrs['bl_document'] = $row['bl_number'];
                    }

                    // 멱등 매칭: 차대번호(VIN) 우선 — 물리 차량 영구 고유키(번호판 재발급 충돌 방지).
                    // VIN 없는 행만 차량번호로 매칭. VIN 있고 매칭 없으면 신규(번호판 같아도 다른 차).
                    $vin = trim((string) ($row['nice_reg_vin'] ?? ''));
                    $existing = $vin !== ''
                        ? Vehicle::withTrashed()->where('nice_reg_vin', $vin)->first()
                        : Vehicle::withTrashed()->where('vehicle_number', $row['vehicle_number'])->first();
                    if ($existing) {
                        // 소프트삭제 차량이 매칭되면 부활(restore) — 엑셀=현재 활성 fleet 원본이므로
                        // 갱신만 하고 trashed로 두면 차량이 live에서 묻힘(실측 2026-06-11). deleted_at 직접 해제(withoutEvents 내).
                        if ($existing->trashed()) {
                            $existing->deleted_at = null;
                            $stats['restored']++;
                        }
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

                    // 적립금 원장 — 루프에서 만들지 않고 큐에 쌓는다. **적립(EARNED)이 사용(USED)보다
                    // 먼저** 들어가야 잔액이 음수가 안 된다(DB CHECK). 실측: 3·4·5행의 적립 254×3 을
                    // 6행이 762 로 사용한다 → 차량 순서대로 처리하면 6행에서 잔액 음수로 터진다.
                    if ($vehicle->buyer_id) {
                        $savingsQueue[] = [
                            'vehicle' => $vehicle,
                            'earned' => (float) ($row['_savings_earned'] ?? 0),
                            'used' => (float) ($vehicle->savings_used ?? 0),
                        ];
                    }

                    // 2단계 — 입금이력(PAYMENT_SLOTS) confirmed + 완료정산(paid, 엑셀 프리50% 재현).
                    if ($withPay && (float) ($vehicle->sale_price ?? 0) > 0) {
                        // 재실행 멱등: 기존 import 입금/정산 제거 후 재생성.
                        FinalPayment::where('vehicle_id', $vehicle->id)->where('note', 'import 입금')->forceDelete();
                        Settlement::where('vehicle_id', $vehicle->id)->where('note', 'like', 'import — %')->forceDelete();

                        $rate = (float) ($vehicle->exchange_rate ?? 0);
                        if ($rate <= 0) {
                            $rate = 1;
                        }

                        // 이중계상 방지 — 이미 수동(비-import) 확정 입금이 있으면 import 2단계(입금+정산) 생략.
                        // (2026-06-11: 6/8 수동입금 차량에 6/11 import 입금이 또 들어가 이중계상된 사고 방지.)
                        $hasManual = FinalPayment::where('vehicle_id', $vehicle->id)
                            ->whereNotNull('confirmed_at')
                            ->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', '%import%'))
                            ->exists();

                        if ($hasManual) {
                            $this->warn("  수동 입금 존재 → import 입금/정산 생략(이중 방지): {$row['vehicle_number']}");
                        } else {
                            foreach (($row['_payments'] ?? []) as $p) {
                                // 외화차인데 입금액이 100만↑ = 엑셀이 원화로 기재한 것 → 외화 환산(amount÷환율).
                                // (중고차 외화결제는 보통 <20만. 100만↑ 외화는 사실상 원화 → ×환율 폭발 방지.)
                                $amt = (float) $p['amount'];
                                if ($vehicle->currency !== 'KRW' && $amt >= 1_000_000) {
                                    $amt = round($amt / $rate, 2);
                                }
                                $fp = new FinalPayment;
                                $fp->forceFill([
                                    'vehicle_id' => $vehicle->id,
                                    'type' => 'balance',
                                    'amount' => $amt,
                                    'exchange_rate' => $rate,
                                    'amount_krw' => (int) round($amt * $rate),
                                    'payment_date' => $p['date'] ?: $vehicle->sale_date,
                                    'confirmed_at' => now(),
                                    'note' => 'import 입금',
                                ])->save();
                                $stats['payments']++;
                            }
                            // 엑셀은 전 행 프리랜서 50%·서류비5만으로 정산 — 담당자 현재 type 무관(과거 재현).
                            $st = new Settlement;
                            $st->forceFill([
                                'vehicle_id' => $vehicle->id,
                                'salesman_id' => $vehicle->salesman_id,
                                'settlement_type' => 'ratio',
                                'settlement_ratio' => 50,
                                'per_unit_amount' => null,
                                'settlement_status' => 'paid',
                                'secondary_status' => 'closed',
                                'confirmed_at' => now(),
                                'paid_at' => now(),
                                'secondary_closed_at' => now(),
                                'note' => 'import — 엑셀 과거 정산 재현(프리50%)',
                            ])->save();
                            $stats['settlements']++;
                        }
                    }
                }
            });

            // 적립금 원장 — **2패스**. 전 차량의 적립(EARNED)을 먼저 다 넣고, 그다음 사용(USED).
            //   실측: 3·4·5행이 254 씩 적립하고 6행이 762 를 쓴다. 차량 순서대로 처리하면 6행에서
            //   잔액이 음수가 되어 DB CHECK 에 걸린다.
            //   withoutEvents 밖에서 돌린다 — SavingsStatus 는 Vehicle 훅이 아니라 명시 호출이라 정상 경로.
            $stats['savings'] += $this->applySavings($savingsQueue, 'earned');
            $stats['savings'] += $this->applySavings($savingsQueue, 'used');
        });

        // 캐시 재계산 (saving 훅을 우회했으므로 명시 호출)
        $this->line('캐시 재계산 중...');
        Vehicle::whereIn('id', $touchedIds)->get()->each(fn (Vehicle $v) => $v->refreshCaches());

        $this->newLine();
        $this->info('✅ import 완료');
        $this->line("  차량 신규 {$stats['new']} / 갱신 {$stats['updated']}".($stats['restored'] > 0 ? " (그중 소프트삭제 복구 {$stats['restored']})" : ''));
        $this->line("  바이어 신규 {$stats['buyer_new']} / 컨사이니 신규 {$stats['consignee_new']}");
        if ($stats['savings'] > 0) {
            $this->line("  적립금 원장 {$stats['savings']}건 (적립 EARNED → 사용 USED 순)");
        }
        if ($withPay) {
            $this->line("  입금 {$stats['payments']}건 / 완료정산(paid) {$stats['settlements']}건 (엑셀 프리50% 재현)");
            $this->warn('  ⚠️ 선수금/예치금(BA·BD·BE·BF)은 1차 제외 — 해당 행은 미수 일부 차이 가능.');
        } else {
            $this->warn('  ⚠️ 입금이력·완료정산(paid)은 미포함 — 진행상태/미수는 입금 전 상태. (2단계: --with-payments)');
        }

        return self::SUCCESS;
    }

    /**
     * 적립금 원장 1패스 기록. `$phase` = 'earned'(AR) | 'used'(AT).
     *
     * 멱등: 같은 차량·같은 종류의 import 행이 이미 있으면 건너뛴다. **삭제 후 재생성하지 않는다** —
     * SavingsStatus.balance 는 러닝 스냅샷이라 중간 행을 지우면 뒤 행들의 잔액이 통째로 어긋난다.
     *
     * @param  array<int,array{vehicle:Vehicle,earned:float,used:float}>  $queue
     */
    private function applySavings(array $queue, string $phase): int
    {
        $type = $phase === 'earned' ? 'EARNED' : 'USED';
        $note = "import 적립금 {$type}";
        $done = 0;

        foreach ($queue as $item) {
            $vehicle = $item['vehicle'];
            $amount = (float) $item[$phase];
            if ($amount <= 0) {
                continue;
            }

            $already = SavingsStatus::where('vehicle_id', $vehicle->id)
                ->where('transaction_type', $type)
                ->where('note', 'like', 'import 적립금%')
                ->exists();
            if ($already) {
                continue;
            }

            try {
                if ($phase === 'earned') {
                    $vehicle->syncSavingsDeposit($amount);
                } else {
                    $vehicle->syncSavingsUsage($amount);   // delta>0 → USED(잔액 차감)
                }
                SavingsStatus::where('vehicle_id', $vehicle->id)
                    ->where('transaction_type', $type)
                    ->whereNull('original_transaction_id')
                    ->orderByDesc('id')->limit(1)
                    ->update(['note' => $note]);
                $done++;
            } catch (\Throwable $e) {
                // 잔액 부족 등 — 그 차량만 건너뛰고 나머지는 계속. 사람이 보고 판단한다.
                $this->warn("  적립금 {$type} 기록 실패({$vehicle->vehicle_number}, {$amount}): ".$e->getMessage());
            }
        }

        return $done;
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
        $existingVins = Vehicle::withTrashed()->whereNotNull('nice_reg_vin')->pluck('nice_reg_vin')->flip()->all();
        $salesmanByName = Salesman::query()->pluck('id', 'name')->all();
        $buyerByName = Buyer::query()->pluck('id', 'name')->all();

        $seenPlates = [];
        $seenVin = [];
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

            // 연도없는 날짜('05-10') 추정용 — 같은 행 구입연도 (jin 2026-06-29). 구입일 자체가
            // 연도없으면 추정 불가(null 유지). 시트가 연차표(수출차량매입-YYYY)라 형제날짜가 같은 해.
            [$pd] = $this->parseDate($this->cell($sheet, self::MAP['purchase_date']['col'], $r));
            $inferYear = $pd ? (int) substr($pd, 0, 4) : null;

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
                        if ($w === '연도없음' && $inferYear && preg_match('/^(\d{1,2})\s*[.\-\/월]\s*(\d{1,2})/u', trim($raw), $mmdd)
                            && checkdate((int) $mmdd[1], (int) $mmdd[2], $inferYear)) {
                            // 같은 행 구입연도로 추정 (jin 2026-06-29). 12월→1월 경계 오차 가능 → 추정행 로그.
                            $v = sprintf('%04d-%02d-%02d', $inferYear, (int) $mmdd[1], (int) $mmdd[2]);
                            $addIssue("연도추정(구입연도 {$inferYear}): {$def['label']}", "R{$r}{$def['col']}: '{$raw}' → {$v}");
                        } elseif ($w === '미발생') {
                            $addIssue("미발생(예정/진행상태)→빈날짜: {$def['label']}", "R{$r}{$def['col']}: '{$raw}'");
                        } elseif ($w === '연도없음') {
                            $addIssue("연도없는 날짜→null(구입연도 없어 추정불가): {$def['label']}", "R{$r}{$def['col']}: '{$raw}'");
                        } elseif ($w !== null) {
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

            // 차대번호(VIN) — 물리 차량 영구 고유키(번호판 재발급 충돌 방지). VIN 우선 매칭.
            $vinVal = trim((string) ($row['nice_reg_vin'] ?? ''));
            if ($vinVal === '') {
                $addIssue('차대번호 없음(번호판으로 매칭)', "R{$r}: {$rawPlate}");
            } elseif (isset($seenVin[$vinVal])) {
                $addIssue('파일 내 차대번호 중복(차단)', "R{$r}: {$vinVal} (이전 R{$seenVin[$vinVal]})");
            } else {
                $seenVin[$vinVal] = $r;
                if (isset($existingVins[$vinVal])) {
                    $addIssue('DB 기존 차대번호(=갱신)', "R{$r}: {$vinVal}");
                }
            }

            // 입금 슬롯 (2단계용) — PAYMENT_SLOTS 단일 출처. '취소'/비숫자/0 은 제외.
            $pays = [];
            foreach (self::PAYMENT_SLOTS as ['amount' => $ac, 'date' => $dc]) {
                $amtRaw = $this->cell($sheet, $ac, $r);
                if ($amtRaw === '') {
                    continue;
                }
                [$amt, $w] = $this->parseNum($amtRaw);
                if ($w !== null || $amt === null || $amt <= 0) {
                    continue;
                }
                [$dt] = $this->parseDate($this->cell($sheet, $dc, $r));
                $pays[] = ['amount' => $amt, 'date' => $dt];
            }
            $row['_payments'] = $pays;

            // 적립금 발생(EARNED) — 금액만 쓴다(일자는 원장 note 용도 외 저장처 없음).
            [$earned, $ew] = $this->parseNum($this->cell($sheet, self::SAVINGS_EARNED_SLOT['amount'], $r));
            $row['_savings_earned'] = ($ew === null && $earned !== null && $earned > 0) ? $earned : 0.0;

            // 담당자/바이어 신규 집계
            if (($row['salesman'] ?? '') !== '' && ! isset($salesmanByName[$row['salesman']])) {
                $newSalesmen[$row['salesman']] = true;
            }
            if (($row['buyer'] ?? '') !== '' && ! isset($buyerByName[$row['buyer']])) {
                $newBuyers[$row['buyer']] = true;
            }

            $rows[] = $row;
        }

        $blockers = ($issues['파일 내 차량번호 중복(차단)']['count'] ?? 0)
            + ($issues['파일 내 차대번호 중복(차단)']['count'] ?? 0);

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

    /**
     * 날짜 셀 파싱. ssancar 데이터는 서식이 제각각(시리얼·텍스트·예정·혼합) → 관대하게 정규화.
     *
     * 두 번째 반환값(경고):
     *   null        = 정상
     *   '미발생'    = 예정/진행상태 텍스트('5월 예정'·'선적중') → 날짜 미발생이 정상. 에러 아님(빈 날짜).
     *   '연도없음'  = 월-일만('05-10') → 연도 불명, 보류
     *   그 외        = 실제 파싱 실패('형식 불명'·'유효하지 않은 날짜'·'시리얼 변환 실패')
     *
     * @return array{0:?string,1:?string}
     */
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
        // 날짜 프리픽스 추출 — 뒤에 텍스트가 붙어도 앞 날짜만 ('26.05.10정산', '26.1.6 예정').
        if (preg_match('/^(\d{2,4})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})/', $s, $m)) {
            $y = (int) $m[1];
            $y = $y < 100 ? 2000 + $y : $y;
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                return [sprintf('%04d-%02d-%02d', $y, $mo, $d), null];
            }

            return [null, '유효하지 않은 날짜'];
        }
        // 한국식 'YYYY년 M월 D일'
        if (preg_match('/^(\d{2,4})\s*년\s*(\d{1,2})\s*월\s*(\d{1,2})\s*일?/u', $s, $m)) {
            $y = (int) $m[1];
            $y = $y < 100 ? 2000 + $y : $y;
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if (checkdate($mo, $d, $y)) {
                return [sprintf('%04d-%02d-%02d', $y, $mo, $d), null];
            }

            return [null, '유효하지 않은 날짜'];
        }
        // 미발생 — 예정/미정/진행상태 텍스트('5월 예정'·'선적중'·'매입예정'). 날짜 미발생이 정상.
        if (preg_match('/(예정|미정|진행|대기|보류|확인|매입|판매|선적|통관|말소|중$|완료)/u', $s)) {
            return [null, '미발생'];
        }
        // 연도 없는 월-일 ('05-10'·'5/10'·'5월10일') → 연도 불명.
        if (preg_match('/^(\d{1,2})\s*[.\-\/월]\s*(\d{1,2})/u', $s)) {
            return [null, '연도없음'];
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
