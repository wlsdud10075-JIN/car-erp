<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\SavingsStatus;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ssancarerp 「정산완료」 과거분 일회용 적재 (2026-08-28, jin 결정 전량 반영).
 *
 * 대상 = `정산완료-수출현황표_업로드-26.08.27.xlsx` — **원본 수출차량현황표 96열**이다.
 *   `vehicles:import` 가 읽는 47열 적재양식과 배치가 다르므로 **별도 명령**으로 둔다.
 *   🅿️ 임포터 자체를 현황표 형식으로 바꾸는 것은 **별건**(같이 하면 실패 원인이
 *      「데이터」와 「임포터 개편」으로 섞여 못 가린다 — jin 2026-08-28).
 *
 * ── 결정된 규칙 (전부 jin 확정. 상세 = 메모리 project_ssancarerp_settlement_import) ──
 *
 * 🔑 **비고(CO)열이 단일 출처** — `이니셜_YY.MM.DD 구분` 꼴.
 *    `정산완료`=프리랜서 / `직원정산`(+오타 `직원정상`)=사내직원 / `…-취소`=매입취소 /
 *    `헤이맨판매_…`=헤이맨(정산 없음).
 *    🚨 **담당자 타입이 아니라 행별 표시를 따른다** — 사람 단위로 묶으면 4건이 어긋난다.
 *    🗓️ 날짜는 전부 「10일」 = 지급일. `YB_26.07.10` = 6/1~6/30 정산분이 7/10 지급
 *       ⇒ `attributed_month` = **전월 1일**, `paid_at` = 그 날짜.
 *       🚫 완납일에서 유도하지 말 것 — 24%(920건)가 다른 달로 흩어진다.
 *
 * 💰 **미수는 양방향으로 0 으로 만든다** (정산환율 배율을 1.0 으로 고정하는 것이 목적):
 *    과입금 → 차액을 `sale_other_costs` 로 흡수(총판매가엔 포함·정산/면장 기준액엔 미포함이라
 *             미수만 0 이 되고 마진·면장은 안 움직인다 — SKILLS §13 「세 합계」의 비대칭).
 *    미납   → 회수이력 `method='other'`(기타) 로 기록. 🚫 손실처리(write_off) 아님(jin).
 *
 * 🚗 **거래완료** = 선적일을 출고일에 복사 + 미수 0 → `progress_status_rule_version = 5`.
 *    ⚠️ 그 출고일은 **불리언 용도로만** 쓸 것(선적일 복사라 실제보다 늦다 — SKILLS §14).
 *    선적일 없는 45 건은 그대로 둔다(도착일 0·B/L 1 건뿐 = 실제로 선적 안 된 차).
 *
 * 🧾 **매입** = S열(송금내역확인) 을 계약금/잔금/매도비 구조로 파싱(날짜 회수율 99.3%),
 *    차액은 잔금으로 보정 → 미지급 0. S 가 빈 843 건은 잔금 1 건 전액·날짜=매입일.
 *
 * 🚪 **헤이맨 19 대는 정산을 만들지 않는다.** role 로는 안 막힌다(`createSettlementIfComplete`
 *    는 salesmen 기준이고 role 을 안 본다). 🚫 그 차들에 **인코텀즈를 넣지 말 것** —
 *    넣는 순간 `isFreightConfirmedForSettlement()` 가 열려 정산이 생긴다.
 *
 *   php artisan ssancarerp:import-settled "경로.xlsx"            # dry-run (기본) — 쓰기 없음
 *   php artisan ssancarerp:import-settled "경로.xlsx" --apply    # 실제 적재
 */
class ImportSsancarSettled extends Command
{
    protected $signature = 'ssancarerp:import-settled
        {path : 정산완료 수출현황표 xlsx 경로}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 적재 (미지정 시 dry-run — 검증 리포트만)}';

    protected $description = 'ssancarerp 정산완료 과거분 적재 (96열 현황표 전용, 기본 dry-run)';

    /** 데이터 시작 행 — 1 그룹헤더 / 2 헤더 / 3~ 데이터. */
    private const DATA_START = 3;

    /** 프리랜서 서류비 — 엑셀 CJ = CH/2 − 50,000 의 그 5만. */
    private const DOC_FEE = 50_000;

    /**
     * 차량번호 정정 (jin 2026-08-28) — 파일에 `26누5892` 가 두 행 있다.
     * 차대번호로 지목한다(번호판이 겹치므로 그것으로는 못 가린다).
     */
    private const PLATE_FIX = [
        'WBAUZ3100L9C26451' => '129주2661',   // BMW X3 2020 · 담당 존 (다른 행 = VW TIGUAN, 그대로)
    ];

    /**
     * 매입취소로 표시할 차량 (jin 2026-08-28).
     * 4 건은 비고에 `-취소` 가 있고, `56모9118` 은 표시가 없지만 실질 취소로 확정
     * (구입 1,000 만 · 판매금원화를 0 으로 덮음 · 부가세마진 0).
     */
    private const CANCELLED = ['35무5403', '28조8668', '01라2096', '14주1298', '56모9118'];

    /**
     * 프리랜서 수기 보정 — 엑셀이 판매금원화를 손으로 덮어써 계산값과 다른 행.
     * 기타공제로 **지급액을 줄이는 방향만** 가능하다(화면 검증이 `min:0`).
     * `13오0994` 는 파일이 32 만 더 커서 못 맞춘다 → 그대로 둔다(jin).
     */
    private const MANUAL_DEDUCTION = [
        '35더8400' => 1_978_713,
        '56두3877' => 652_050,
    ];

    /**
     * 판매탭 메모 (jin 2026-08-28) — 엑셀이 판매금원화를 손으로 덮어쓴 사연을 사람이 읽게 남긴다.
     * 숫자는 계산값으로 가고(= 덮어쓴 2,000만은 반영 안 됨), 사연만 기록한다.
     */
    private const SALE_MEMO = [
        '157노1965' => '2,300만원에 매입 후 사고 발생 — 보상금 2,000만원 수령, 바이어에게 780만원 판매. '
            .'엑셀은 보상금을 판매금원화에 합산했으나(2,780만) ERP 는 실제 판매금액만 계산한다. '
            .'담당자가 사내직원이라 정산은 건당 기준이라 영향 없음.',
    ];

    /**
     * 담당자 이름 별칭 (jin 2026-08-28) — 파일에 두 사람을 슬래시로 묶은 값이 1건 있다.
     * 서버엔 김바딤·조하가 따로 있으므로 **김바딤**으로 보낸다.
     */
    private const SALESMAN_ALIAS = [
        '김바딤/조하' => '김바딤',
    ];

    /** 열 → 차량 필드. 96열 현황표 기준. */
    private const MAP = [
        'purchase_date' => 'B',
        'vehicle_number' => 'D',
        'brand' => 'E',
        'model_type' => 'F',
        'year' => 'G',
        'mileage' => 'H',
        'nice_reg_vin' => 'L',
        'purchase_from' => 'N',
        'nice_reg_owner_name' => 'O',
        'nice_reg_owner_rrn' => 'P',
        'nice_reg_owner_addr' => 'Q',
        'purchase_remittance_memo' => 'S',
        'purchase_price' => 'T',
        'selling_fee' => 'U',
        'deregistration_date' => 'X',
        'export_declaration_number' => 'Z',
        'bl_number' => 'AA',
        'container_number' => 'AB',
        'shipping_date' => 'AC',
        'eta_date' => 'AD',
        'export_declaration_amount' => 'AE',
        'currency' => 'AI',
        'sale_price' => 'AJ',
        'exchange_rate' => 'AL',
        'commission' => 'AM',
        'auto_loading' => 'AN',
        'tax_dc' => 'AO',
        'transport_fee' => 'AP',
        'memo' => 'CO',
    ];

    /** 비용 9개 — 엑셀엔 주차료가 없다(우리 10개 중 9개만 채운다). */
    private const COST_MAP = [
        'cost_deregistration' => 'BQ', 'cost_license' => 'BR', 'cost_towing' => 'BS',
        'cost_carry' => 'BT', 'cost_shoring' => 'BU', 'cost_insurance' => 'BV',
        'cost_transfer' => 'BW', 'cost_extra1' => 'BX', 'cost_extra2' => 'BY',
    ];

    /** 입금 5슬롯 [금액, 입금일]. */
    private const PAYMENT_SLOTS = [['AU', 'AV'], ['AW', 'AX'], ['AY', 'AZ'], ['BA', 'BB'], ['BC', 'BD']];

    private const COL_SALESMAN = 'M';

    private const COL_BUYER = 'AF';

    private const COL_CONSIGNEE = 'AG';

    private const COL_SAVINGS_EARNED = 'BE';

    private const COL_SAVINGS_EARNED_DATE = 'BF';

    private const COL_SAVINGS_USED = 'BG';

    private const COL_ADVANCE = 'BJ';

    /** 수식 칸 캐시값을 되읽기 위해 보관한다(val() 폴백). */
    private ?Worksheet $sheet = null;

    public function handle(): int
    {
        ini_set('memory_limit', '2G');   // 96열 × 3,839행 워크북
        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error("파일을 읽을 수 없다: {$path}");

            return self::FAILURE;
        }

        $this->line('워크북 로드 중…');
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheetByName((string) $this->option('sheet'));
        if (! $sheet) {
            $this->error('시트를 찾을 수 없다: '.$this->option('sheet'));

            return self::FAILURE;
        }

        [$rows, $issues] = $this->parseAll($sheet);
        $this->report($rows, $issues);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('dry-run 이다 — 아무것도 쓰지 않았다. 실제 적재는 --apply.');

            return self::SUCCESS;
        }

        return $this->import($rows);
    }

    // ────────────────────────────── 파싱 ──────────────────────────────

    /** @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>} */
    private function parseAll(Worksheet $sheet): array
    {
        $this->sheet = $sheet;
        $rows = [];
        $issues = ['no_plate' => 0, 'no_memo' => 0, 'memo_unparsed' => [], 'year_fixed' => 0,
            'plate_fixed' => 0, 'negative_cost' => []];
        $last = $sheet->getHighestDataRow();

        // 행 단위로 한 번에 읽는다 — 3,839행 × 96열을 셀마다 getCell 하면 23만 회라 느리고 메모리를 먹는다.
        $grid = $sheet->rangeToArray('A'.self::DATA_START.':CR'.$last, null, false, false, false);

        foreach ($grid as $offset => $vals) {
            $r = self::DATA_START + $offset;
            $plate = trim($this->val($vals, 'D', $r));
            $vin = trim($this->val($vals, 'L', $r));
            if ($plate === '' && $vin === '') {
                continue;
            }
            if ($plate === '') {
                $issues['no_plate']++;

                continue;
            }

            // 차량번호 정정 — 차대번호로 지목(번호판이 겹쳐서 그것으로는 못 가린다).
            if ($vin !== '' && isset(self::PLATE_FIX[strtoupper($vin)])) {
                $plate = self::PLATE_FIX[strtoupper($vin)];
                $issues['plate_fixed']++;
            }

            $row = ['_excel_row' => $r, 'vehicle_number' => $plate];
            foreach (self::MAP as $field => $col) {
                $raw = $this->val($vals, $col, $r);
                $row[$field] = match (true) {
                    in_array($field, ['purchase_date', 'deregistration_date', 'shipping_date', 'eta_date'], true) => $this->toDate($raw),
                    in_array($field, ['year', 'mileage'], true) => $this->toNum($raw) === null ? null : (int) $this->toNum($raw),
                    in_array($field, ['purchase_price', 'selling_fee', 'sale_price', 'exchange_rate', 'commission',
                        'auto_loading', 'tax_dc', 'transport_fee', 'export_declaration_amount'], true) => $this->toNum($raw) ?? 0,
                    default => $raw,
                };
            }
            $row['vehicle_number'] = $plate;

            // 비용 9개 — 문자값(업체명)이 섞인 칸이 132 건 있다. 엑셀 합계도 0 으로 쳤으므로 0 으로.
            // 🚨 **비용 컬럼은 `unsignedBigInteger`** 라 음수를 넣으면 MySQL 이
            //    `1264 Out of range value` 로 죽는다(실측 `128하3052` 기타1 = −275,680, 1 행).
            //    ⇒ 0 으로 눕히고 **리포트에 드러낸다**(조용히 삼키면 마진이 그만큼 달라진 걸 아무도 모른다).
            foreach (self::COST_MAP as $field => $col) {
                $v = $this->toNum($this->val($vals, $col, $r)) ?? 0;
                if ($v < 0) {
                    $issues['negative_cost'][] = $plate.' '.$col.'='.number_format($v);
                    $v = 0;
                }
                $row[$field] = $v;
            }

            // 통화 — 공란 88 건은 USD (글자색 검정 + 환율대가 USD 구간, 이중 확인 · jin 확정).
            $row['currency'] = strtoupper(trim((string) $row['currency'])) ?: 'USD';

            $sm = trim($this->val($vals, self::COL_SALESMAN, $r));
            $row['_salesman'] = self::SALESMAN_ALIAS[$sm] ?? $sm;
            $row['_buyer'] = trim($this->val($vals, self::COL_BUYER, $r));
            $row['_consignee'] = trim($this->val($vals, self::COL_CONSIGNEE, $r));

            // 입금 5슬롯 + 선수금2. 928 건이 2회 이상 분할이라 **개별 행으로** 넣는다(합치면 입금일이 사라진다).
            $payments = [];
            foreach (self::PAYMENT_SLOTS as $i => [$amtCol, $dateCol]) {
                $amt = $this->toNum($this->val($vals, $amtCol, $r)) ?? 0;
                if (abs($amt) < 0.005) {
                    continue;
                }
                $payments[] = [
                    'type' => $i === 0 ? 'deposit_down' : 'balance',
                    'amount' => $amt,
                    'date' => $this->toDate($this->val($vals, $dateCol, $r)),
                ];
            }
            $row['_payments'] = $payments;
            // 🚫 선수금2(BJ)를 별도 입금으로 만들지 않는다 — **이미 정산1~5 안에 들어 있다**(실측).
            //    넣으면 152 건에서 가짜 과입금이 생긴다. 델타 식 변형 비교:
            //      정산1~5만          델타≠0 701 · 엑셀BL 일치 3,507
            //      정산1~5+적립금사용   델타≠0 398 · 엑셀BL 일치 3,827  ← 채택
            //      +선수금2           델타≠0 530 · 엑셀BL 일치 3,675
            $row['_advance_note'] = $this->toNum($this->val($vals, self::COL_ADVANCE, $r)) ?? 0;

            // 엑셀이 계산해 둔 정산 결과 — **대조 전용**.
            // ⚠️ CH·CJ 는 **수식 칸**이라 rangeToArray(계산 끔)로는 수식 문자열이 온다.
            //    재계산하면 CF→CE→CC→AH 사슬을 3,839 행 타야 해서 느리고 위험 →
            //    엑셀이 저장해 둔 **캐시값**을 읽는다.
            $row['_excel_cj'] = $this->toNum($this->val($vals, 'CJ', $r));
            $row['_excel_ch'] = $this->toNum($this->val($vals, 'CH', $r));

            $row['_savings_earned'] = $this->toNum($this->val($vals, self::COL_SAVINGS_EARNED, $r)) ?? 0;
            $row['_savings_earned_date'] = $this->toDate($this->val($vals, self::COL_SAVINGS_EARNED_DATE, $r));
            // 적립금 사용 — 음수(환급) 2건이 있다. 원장도 컬럼도 음수를 못 받으므로 0 으로 눕힌다.
            // ⚠️ 델타 계산과 저장값이 **같은 값**을 써야 한다 — 한쪽만 눕히면 미수가 0 으로 안 떨어진다.
            $row['_savings_used'] = max(0.0, $this->toNum($this->val($vals, self::COL_SAVINGS_USED, $r)) ?? 0);

            // 비고 — 정산 구분·지급일의 단일 출처.
            $memo = $this->parseMemo((string) $row['memo']);
            $row['_kind'] = $memo['kind'];
            $row['_paid_at'] = $memo['paid_at'];
            $row['_initials'] = $memo['initials'];
            if ($memo['year_fixed']) {
                $issues['year_fixed']++;
            }
            if ($memo['kind'] === null) {
                $issues['no_memo']++;
                if (count($issues['memo_unparsed']) < 6) {
                    $issues['memo_unparsed'][] = $plate.' | '.$row['memo'];
                }
            }

            $row['_cancelled'] = in_array($plate, self::CANCELLED, true) || $memo['cancelled'];
            $row['_remittance'] = $this->parseRemittance((string) $row['purchase_remittance_memo'], $row['purchase_date']);

            $rows[] = $row;
        }

        return [$rows, $issues];
    }

    /**
     * 비고(CO) 파싱 — `이니셜_YY.MM.DD 구분`.
     *
     * 구분: `정산완료`=프리랜서 / `직원정산`·`직원정상`(오타)=사내직원 / `-취소` / 헤이맨.
     * 연도 `36` 은 오타라 2026 으로 고친다(박바딤 8건).
     *
     * @return array{kind:?string,paid_at:?string,initials:?string,cancelled:bool,year_fixed:bool}
     */
    private function parseMemo(string $memo): array
    {
        $out = ['kind' => null, 'paid_at' => null, 'initials' => null, 'cancelled' => false, 'year_fixed' => false];
        $memo = trim($memo);
        if ($memo === '') {
            return $out;
        }
        if (str_contains($memo, '헤이맨')) {
            $out['kind'] = 'heyman';

            return $out;
        }
        $out['cancelled'] = str_contains($memo, '취소');
        // 「직원정상」은 「직원정산」오타(김니키타 5건).
        if (str_contains($memo, '직원정산') || str_contains($memo, '직원정상')) {
            $out['kind'] = 'employee';
        } elseif (str_contains($memo, '정산완료')) {
            $out['kind'] = 'freelance';
        }

        if (preg_match('/^([A-Za-z]+)_(\d{2})\.(\d{2})\.(\d{2})/', $memo, $m)) {
            $out['initials'] = $m[1];
            $year = 2000 + (int) $m[2];
            if ($year > 2030) {          // `36.07.10` → 2026
                $year = 2026;
                $out['year_fixed'] = true;
            }
            $out['paid_at'] = sprintf('%04d-%02d-%02d', $year, (int) $m[3], (int) $m[4]);
        }

        return $out;
    }

    /**
     * 매입 송금내역(S열) 파싱 — `1/17 계약금 500,000원 송금 / 2/3 잔금 35,500,000원 송금 / 완료`.
     *
     * 실측: 매입합계와 정확히 일치 2,840 / 3% 이내 38 / 어긋남 111 / 빈칸 843.
     * 어긋남의 패턴 = **「과입금 N원 빽송금」**(돌려받음 → 뺀다) · 「예약금」(=계약금) · 「계약금 추가」.
     * 연도는 없고 M/D 만 있다 → 매입일의 연도를 쓰되, 매입일보다 이르면 다음 해로 넘긴다.
     *
     * @return array<int,array{type:string,amount:float,date:?string}>
     */
    private function parseRemittance(string $memo, ?string $purchaseDate): array
    {
        $memo = trim($memo);
        if ($memo === '' || str_contains($memo, '취소')) {
            return [];
        }

        $base = $purchaseDate ? Carbon::parse($purchaseDate) : null;
        $out = [];
        $re = '/(?:(\d{1,2})\s*\/\s*(\d{1,2})\s*)?([가-힣]*(?:계약금|예약금|잔금|매도비|차량대금|대금|중도금|입금|과입금))\s*(추가)?\s*[-]?\s*([\d,]+)\s*원?/u';
        if (! preg_match_all($re, $memo, $ms, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($ms as $m) {
            $label = $m[3];
            $amount = (float) str_replace(',', '', $m[5]);
            if ($amount <= 0) {
                continue;
            }
            // 「과입금 N원 빽송금」 = 돌려받은 돈 → 뺀다.
            // ⚠️ 메모 **전체**에 '빽' 이 있는지로 보면 그 행의 조각이 **전부** 음수가 된다(실측 350건 오판).
            //    반드시 **그 조각의 라벨**로만 판정할 것.
            $isRefund = str_contains($label, '과입금');
            $type = match (true) {
                str_contains($label, '매도비') => 'selling_fee',
                str_contains($label, '계약금'), str_contains($label, '예약금') => 'down',
                default => 'balance',
            };
            $date = null;
            if ($m[1] !== '' && $m[2] !== '' && $base) {
                $mo = (int) $m[1];
                $d = (int) $m[2];
                $y = $base->year;
                $try = Carbon::createFromDate($y, $mo, $d)->startOfDay();
                if ($try->lt($base->copy()->startOfDay()->subDays(3))) {
                    $try = $try->addYear();        // 12월 매입 → 1월 잔금
                }
                $date = $try->format('Y-m-d');
            }
            $out[] = ['type' => $type, 'amount' => $isRefund ? -$amount : $amount, 'date' => $date];
        }

        return $out;
    }

    // ────────────────────────────── 리포트 ──────────────────────────────

    /**
     * dry-run 검증 — **프리랜서 정산 재계산이 엑셀 CJ 와 맞는지**가 핵심이다.
     * 사내직원은 엑셀 CJ 가 프리랜서 환산값이라 애초에 안 맞는다(대조 대상 아님).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $issues
     */
    private function report(array $rows, array $issues): void
    {
        $kinds = ['freelance' => 0, 'employee' => 0, 'heyman' => 0, 'none' => 0];
        $pay = ['rows' => 0, 'count' => 0];
        $over = $under = 0;
        $overSum = $underSum = 0.0;
        $ship = $noShip = 0;
        $pbpOk = $pbpGap = $pbpNone = 0;
        $months = [];

        foreach ($rows as $row) {
            $kinds[$row['_kind'] ?? 'none'] = ($kinds[$row['_kind'] ?? 'none'] ?? 0) + 1;
            if ($row['_payments']) {
                $pay['rows']++;
                $pay['count'] += count($row['_payments']);
            }
            $delta = $this->receivableDelta($row);
            if ($delta > 0.005) {
                $over++;
                $overSum += $delta;
            } elseif ($delta < -0.005) {
                $under++;
                $underSum += -$delta;
            }
            $row['shipping_date'] ? $ship++ : $noShip++;

            $purchaseTotal = (float) $row['purchase_price'] + (float) $row['selling_fee'];
            if (! $row['_remittance']) {
                $pbpNone++;
            } elseif (abs(array_sum(array_column($row['_remittance'], 'amount')) - $purchaseTotal) <= max(1, $purchaseTotal * 0.03)) {
                $pbpOk++;
            } else {
                $pbpGap++;
            }

            if ($row['_paid_at']) {
                $months[substr((string) $this->attributedMonth($row['_paid_at']), 0, 7)] = true;
            }
        }

        $this->newLine();
        $this->info('── 파싱 요약 ──');
        $this->line(sprintf('  행 %d  ·  차량번호 없음 %d  ·  번호 정정 %d  ·  연도오타 정정 %d',
            count($rows), $issues['no_plate'], $issues['plate_fixed'], $issues['year_fixed']));
        $this->line(sprintf('  정산구분: 프리랜서 %d · 사내직원 %d · 헤이맨 %d · 미판별 %d',
            $kinds['freelance'], $kinds['employee'], $kinds['heyman'], $kinds['none']));
        if ($issues['memo_unparsed']) {
            foreach ($issues['memo_unparsed'] as $s) {
                $this->warn('    비고 판별 실패: '.$s);
            }
        }
        $this->line(sprintf('  입금: %d행 %d건  ·  귀속월 %d개', $pay['rows'], $pay['count'], count($months)));
        $this->line(sprintf('  미수 보정: 과입금 %d건(%s 흡수) · 미납 %d건(%s 기타처리)',
            $over, number_format($overSum, 2), $under, number_format($underSum, 2)));
        $this->line(sprintf('  거래완료(v5) 대상: 선적일 있음 %d · 없음 %d(그대로 둠)', $ship, $noShip));
        $this->line(sprintf('  매입 송금내역: 합계일치 %d · 차액있음 %d(잔금으로 보정) · 메모없음 %d(전액 잔금)',
            $pbpOk, $pbpGap, $pbpNone));
        if ($issues['negative_cost']) {
            $this->warn('  ⚠️ 음수 비용 → 0 으로 눕힘(컬럼이 unsigned): '.implode(' · ', $issues['negative_cost']));
            $this->warn('     그만큼 cost_total 이 줄어 마진·지급액이 엑셀보다 커진다 — 아래 불일치 목록에 나타난다.');
        }

        $this->newLine();
        $this->info('── 정산 재계산 대조 (프리랜서만 — 엑셀 CJ 와 일치해야 정상) ──');
        $ok = 0;
        $bad = [];
        foreach ($rows as $row) {
            if (($row['_kind'] ?? null) !== 'freelance' || $row['_cancelled']) {
                continue;
            }
            $mine = $this->freelancePayout($row);
            $excel = $this->excelPayout($row);
            if ($excel === null) {
                continue;
            }
            if (abs($mine - $excel) <= 1.5) {
                $ok++;
            } else {
                $bad[] = [$row['vehicle_number'], $row['_salesman'], round($excel), round($mine), round($mine - $excel)];
            }
        }
        $this->line(sprintf('  일치 %d · 불일치 %d', $ok, count($bad)));
        if ($bad) {
            usort($bad, fn ($a, $b) => abs($b[4]) <=> abs($a[4]));
            $this->table(['차량번호', '담당자', '엑셀 CJ', '재계산', '차이'],
                array_map(fn ($r) => [$r[0], $r[1], number_format($r[2]), number_format($r[3]), number_format($r[4])],
                    array_slice($bad, 0, 15)));
            if (count($bad) > 15) {
                $this->line('  … 외 '.(count($bad) - 15).'건');
            }
        }

        // 이니셜 — 담당자별 최빈값. TN 은 티나·최니키타가 공유한다(중복 허용 = jin).
        $ini = $this->initialsByName($rows);
        $this->newLine();
        $this->info('── 이니셜 (비어 있는 담당자에만 기입) ──');
        $this->line('  '.collect($ini)->map(fn ($v, $k) => "{$k}={$v}")->implode(' · '));

        // 담당자 매칭 — 파일에만 있고 서버에 없는 이름은 적재 전에 만들어야 한다.
        $names = array_unique(array_filter(array_column($rows, '_salesman')));
        $known = Salesman::pluck('id', 'name')->all();
        $missing = array_values(array_diff($names, array_keys($known)));
        $this->newLine();
        $this->info('── 담당자 ──');
        $this->line(sprintf('  파일 %d명 · 서버 매칭 %d명', count($names), count($names) - count($missing)));
        if ($missing) {
            $this->error('  서버에 없음: '.implode(', ', $missing).'  ← 적재 전에 등록 필요');
        }
    }

    // ────────────────────────────── 적재 ──────────────────────────────

    /**
     * 담당자별 이니셜 최빈값 — 비고(CO) 앞머리에서 뽑는다.
     * 🚫 이니셜로 담당자를 **매칭하지는 않는다**(3건이 어긋난다 — 담당자 M열이 정답).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,string>
     */
    private function initialsByName(array $rows): array
    {
        $tally = [];
        foreach ($rows as $row) {
            $name = $row['_salesman'] ?? '';
            $ini = $row['_initials'] ?? null;
            if ($name === '' || ! $ini) {
                continue;
            }
            $tally[$name][$ini] = ($tally[$name][$ini] ?? 0) + 1;
        }
        $out = [];
        foreach ($tally as $name => $counts) {
            arsort($counts);
            $out[$name] = (string) array_key_first($counts);
        }
        ksort($out);

        return $out;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function import(array $rows): int
    {
        $salesmen = Salesman::pluck('id', 'name')->all();

        // 이니셜 — **비어 있는 담당자에만** 채운다(운영에서 손으로 넣은 값을 덮지 않는다).
        $iniSet = 0;
        foreach ($this->initialsByName($rows) as $name => $ini) {
            if (! isset($salesmen[$name])) {
                continue;
            }
            $iniSet += Salesman::whereKey($salesmen[$name])
                ->where(fn ($q) => $q->whereNull('initials')->orWhere('initials', ''))
                ->update(['initials' => $ini]);
        }
        if ($iniSet > 0) {
            $this->line("  이니셜 기입 {$iniSet}명");
        }
        $stats = ['vehicle' => 0, 'updated' => 0, 'payment' => 0, 'pbp' => 0, 'receivable' => 0,
            'settlement' => 0, 'cancelled' => 0, 'buyer_new' => 0, 'consignee_new' => 0];
        $touched = [];
        $savingsQueue = [];

        DB::transaction(function () use ($rows, $salesmen, &$stats, &$touched, &$savingsQueue) {
            Model::withoutEvents(function () use ($rows, $salesmen, &$stats, &$touched, &$savingsQueue) {
                $buyerCache = [];
                foreach ($rows as $row) {
                    // ── 바이어 / 컨사이니 ──
                    $buyerId = null;
                    if ($row['_buyer'] !== '') {
                        if (! isset($buyerCache[$row['_buyer']])) {
                            $b = Buyer::where('name', $row['_buyer'])->first();
                            if (! $b) {
                                $b = Buyer::create(['name' => $row['_buyer'],
                                    'salesman_id' => $salesmen[$row['_salesman']] ?? null, 'is_active' => true]);
                                $stats['buyer_new']++;
                            }
                            $buyerCache[$row['_buyer']] = $b;
                        }
                        $buyerId = $buyerCache[$row['_buyer']]->id;
                    }
                    $consigneeId = null;
                    if ($row['_consignee'] !== '' && $buyerId) {
                        $c = Consignee::where('buyer_id', $buyerId)->where('name', $row['_consignee'])->first();
                        if (! $c) {
                            $c = Consignee::create(['buyer_id' => $buyerId, 'name' => $row['_consignee'], 'is_active' => true]);
                            $stats['consignee_new']++;
                        }
                        $consigneeId = $c->id;
                    }

                    // ── 차량 ──
                    $attrs = [
                        'sales_channel' => 'export',
                        // v5 = 소급 정리 전용. 출고일(=선적일 복사) + 미수 0 이면 거래완료.
                        'progress_status_rule_version' => 5,
                        'salesman_id' => $salesmen[$row['_salesman']] ?? null,
                        'buyer_id' => $buyerId,
                        'consignee_id' => $consigneeId,
                        'is_deregistered' => ! empty($row['deregistration_date']),
                        // 선적일을 출고일에 복사 — ⚠️ 불리언 용도로만 쓸 것(SKILLS §14).
                        'warehouse_out_date' => $row['shipping_date'],
                        'sale_other_costs' => 0,
                        'savings_used' => 0,
                        'cancel_status' => $row['_cancelled'] ? Vehicle::CANCEL_ACTIVE : Vehicle::CANCEL_NONE,
                        'memo_sale' => self::SALE_MEMO[$row['vehicle_number']] ?? null,
                    ];
                    foreach (self::MAP as $f => $_) {
                        if ($f === 'vehicle_number') {
                            continue;
                        }
                        $attrs[$f] = $row[$f];
                    }
                    foreach (array_keys(self::COST_MAP) as $f) {
                        $attrs[$f] = $row[$f];
                    }
                    // chk_sale_required — 판매가>0 이면 sale_date·환율>0 필수(운영 MySQL CHECK).
                    if ((float) $row['sale_price'] > 0) {
                        $attrs['sale_date'] = $row['shipping_date'] ?: $row['purchase_date'];
                        if ((float) $attrs['exchange_rate'] <= 0) {
                            $attrs['exchange_rate'] = $row['currency'] === 'KRW' ? 1 : 0;
                        }
                    } else {
                        $attrs['sale_date'] = null;
                    }

                    $vin = trim((string) $row['nice_reg_vin']);
                    $vehicle = $vin !== ''
                        ? Vehicle::withTrashed()->where('nice_reg_vin', $vin)->first()
                        : Vehicle::withTrashed()->where('vehicle_number', $row['vehicle_number'])->first();
                    if ($vehicle) {
                        if ($vehicle->trashed()) {
                            $vehicle->deleted_at = null;
                        }
                        $vehicle->forceFill($attrs)->save();
                        $stats['updated']++;
                    } else {
                        $vehicle = new Vehicle;
                        $vehicle->forceFill(array_merge(['vehicle_number' => $row['vehicle_number']], $attrs))->save();
                        $stats['vehicle']++;
                    }
                    $touched[] = $vehicle->id;
                    if ($row['_cancelled']) {
                        $stats['cancelled']++;
                    }

                    // 재실행 멱등 — 이 명령이 만든 것만 지우고 다시 만든다.
                    FinalPayment::where('vehicle_id', $vehicle->id)->where('note', 'like', '과거적재%')->forceDelete();
                    PurchaseBalancePayment::where('vehicle_id', $vehicle->id)->where('note', 'like', '과거적재%')->forceDelete();
                    ReceivableHistory::where('vehicle_id', $vehicle->id)->where('note', 'like', '과거데이터 임포트%')->forceDelete();
                    Settlement::where('vehicle_id', $vehicle->id)->where('note', 'like', '과거적재%')->forceDelete();

                    // ── 판매 입금 (개별 행 — 928건이 분할이라 합치면 입금일이 사라진다) ──
                    $rate = (float) $vehicle->exchange_rate ?: 1.0;
                    foreach ($row['_payments'] as $p) {
                        (new FinalPayment)->forceFill([
                            'vehicle_id' => $vehicle->id,
                            'type' => $p['type'],
                            'amount' => $p['amount'],
                            'exchange_rate' => $rate,
                            'amount_krw' => (int) round($p['amount'] * $rate),
                            'payment_date' => $p['date'] ?: $vehicle->sale_date,
                            'confirmed_at' => now(),
                            'note' => '과거적재 입금',
                        ])->save();
                        $stats['payment']++;
                    }

                    // ── 적립금 (사용일이 없어 그 차의 마지막 입금일로 — jin) ──
                    if ($buyerId) {
                        $lastPay = collect($row['_payments'])->pluck('date')->filter()->max();
                        if ((float) $row['_savings_earned'] > 0) {
                            $savingsQueue[] = ['vehicle' => $vehicle, 'kind' => 'earned',
                                'amount' => (float) $row['_savings_earned'], 'date' => $row['_savings_earned_date']];
                        }
                        if ((float) $row['_savings_used'] > 0) {
                            $savingsQueue[] = ['vehicle' => $vehicle, 'kind' => 'used',
                                'amount' => (float) $row['_savings_used'], 'date' => $lastPay];
                        }
                    }

                    // ── 미수를 0 으로 (정산환율 배율을 1.0 으로 고정하는 것이 목적) ──
                    $delta = $this->receivableDelta($row);
                    if ($delta > 0.005) {
                        // 과입금 → 총판매가에만 들어가는 칸으로 흡수(마진·면장 불변).
                        $vehicle->forceFill(['sale_other_costs' => round($delta, 2)])->save();
                    } elseif ($delta < -0.005) {
                        (new ReceivableHistory)->forceFill([
                            'vehicle_id' => $vehicle->id,
                            'collected_at' => $row['_paid_at'] ?: $vehicle->sale_date,
                            'method' => 'other',
                            'amount' => round(-$delta, 2),
                            'exchange_rate' => $rate,
                            'note' => '과거데이터 임포트 — 정산 종결분 미수 정리',
                        ])->save();
                        $stats['receivable']++;
                    }
                    if ((float) $row['_savings_used'] > 0) {
                        $vehicle->forceFill(['savings_used' => (float) $row['_savings_used']])->save();
                    }

                    // ── 매입 지급 (S열 구조 + 차액은 잔금으로 보정 → 미지급 0) ──
                    $stats['pbp'] += $this->applyPurchase($vehicle, $row);

                    // ── 정산 ──
                    if ($this->createSettlement($vehicle, $row)) {
                        $stats['settlement']++;
                    }
                }
            });

            $stats['savings'] = $this->applySavings($savingsQueue);
        });

        // 캐시 재계산 — withoutEvents 로 훅을 우회했으므로 명시 호출.
        // ⚠️ 3,839 대를 한 번에 `get()` 하면 모델을 통째로 메모리에 올린다 → 청크로 돈다.
        $this->line('캐시 재계산 중…');
        $bar = $this->output->createProgressBar(count($touched));
        Vehicle::whereIn('id', $touched)->chunkById(200, function ($chunk) use ($bar) {
            $chunk->each(fn (Vehicle $v) => $v->refreshCaches());
            $bar->advance($chunk->count());
        });
        $bar->finish();
        $this->newLine();

        $this->newLine();
        $this->info('✅ 적재 완료');
        $this->line("  차량 신규 {$stats['vehicle']} / 갱신 {$stats['updated']} (매입취소 표시 {$stats['cancelled']})");
        $this->line("  바이어 신규 {$stats['buyer_new']} / 컨사이니 신규 {$stats['consignee_new']}");
        $this->line("  판매입금 {$stats['payment']} / 매입지급 {$stats['pbp']} / 미수정리(기타) {$stats['receivable']}");
        $this->line("  정산(paid+2차closed) {$stats['settlement']} / 적립금원장 ".($stats['savings'] ?? 0));
        $this->newLine();
        $this->warn('  ⚠️ 헤이맨 차량엔 인코텀즈를 넣지 말 것 — 넣는 순간 정산이 생긴다.');

        return self::SUCCESS;
    }

    /** 매입 지급 — 파싱분 입력 후 차액을 잔금으로 채워 미지급 0 으로 만든다. */
    private function applyPurchase(Vehicle $vehicle, array $row): int
    {
        if ($row['_cancelled']) {
            return 0;   // 취소 차량은 매입 지급을 만들지 않는다.
        }
        $total = (float) $vehicle->purchase_price + (float) $vehicle->selling_fee;
        if ($total <= 0) {
            return 0;
        }

        $made = 0;
        $sum = 0.0;
        $lastDate = null;
        foreach ($row['_remittance'] as $p) {
            if ($p['amount'] <= 0) {          // 「빽송금」(환불)은 합계에서만 빼고 행은 안 만든다.
                $sum += $p['amount'];

                continue;
            }
            (new PurchaseBalancePayment)->forceFill([
                'vehicle_id' => $vehicle->id,
                'type' => $p['type'],
                'amount' => $p['amount'],
                'payment_date' => $p['date'] ?: $vehicle->purchase_date,
                'confirmed_at' => now(),
                'note' => '과거적재 매입지급',
            ])->save();
            $sum += $p['amount'];
            $lastDate = $p['date'] ?: $lastDate;
            $made++;
        }

        // 남은 차액 = 잔금 1건. (메모가 없던 843건은 여기서 전액이 들어간다.)
        $gap = round($total - $sum, 2);
        if ($gap > 0.005) {
            (new PurchaseBalancePayment)->forceFill([
                'vehicle_id' => $vehicle->id,
                'type' => 'balance',
                'amount' => $gap,
                'payment_date' => $lastDate ?: $vehicle->purchase_date,
                'confirmed_at' => now(),
                'note' => '과거적재 매입지급 — 차액 보정',
            ])->save();
            $made++;
        }

        return $made;
    }

    /**
     * 정산 — 비고(CO)가 정한 구분·지급일 그대로. 헤이맨·취소·미판별은 만들지 않는다.
     *
     * 사내직원은 `per_unit_amount = null` 로 둬서 tier 가 자동 산정되게 한다(무사백만 tier ON).
     * 프리랜서는 50% + 서류비 5만이 `actual_payout` 에서 자동 차감된다.
     */
    private function createSettlement(Vehicle $vehicle, array $row): bool
    {
        $kind = $row['_kind'] ?? null;
        if (! in_array($kind, ['freelance', 'employee'], true) || $row['_cancelled'] || ! $vehicle->salesman_id) {
            return false;
        }
        $paidAt = $row['_paid_at'];
        if (! $paidAt) {
            return false;
        }

        (new Settlement)->forceFill([
            'vehicle_id' => $vehicle->id,
            'salesman_id' => $vehicle->salesman_id,
            'settlement_type' => $kind === 'freelance' ? 'ratio' : 'per_unit',
            'settlement_ratio' => $kind === 'freelance' ? 50 : null,
            'per_unit_amount' => null,     // 사내직원 = tier 자동(무사백만 차등)
            'other_deduction' => self::MANUAL_DEDUCTION[$row['vehicle_number']] ?? 0,
            'settlement_status' => 'paid',
            'secondary_status' => 'closed',
            'attributed_month' => $this->attributedMonth($paidAt),
            'confirmed_at' => $paidAt,
            'paid_at' => $paidAt,
            'secondary_closed_at' => $paidAt,
            'note' => '과거적재 — '.$row['memo'],
        ])->save();

        return true;
    }

    /**
     * 적립금 원장 — 시간순 1패스. 날짜 없는 항목은 맨 뒤.
     * 멱등: 이미 이 명령이 만든 행이 있으면 건너뛴다(balance 가 러닝 스냅샷이라 지우면 뒤가 어긋난다).
     */
    private function applySavings(array $queue): int
    {
        usort($queue, fn ($a, $b) => [$a['date'] === null, $a['date'] ?? ''] <=> [$b['date'] === null, $b['date'] ?? '']);
        $done = 0;
        foreach ($queue as $item) {
            $v = $item['vehicle'];
            $type = $item['kind'] === 'earned' ? 'EARNED' : 'USED';
            if (SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', $type)
                ->where('note', 'like', '과거적재 적립금%')->exists()) {
                continue;
            }
            try {
                $item['kind'] === 'earned'
                    ? $v->syncSavingsDeposit($item['amount'])
                    : $v->syncSavingsUsage($item['amount']);
                SavingsStatus::where('vehicle_id', $v->id)->where('transaction_type', $type)
                    ->whereNull('original_transaction_id')->orderByDesc('id')->limit(1)
                    ->update(['note' => "과거적재 적립금 {$type} ".($item['date'] ?? '일자없음')]);
                $done++;
            } catch (\Throwable $e) {
                $this->warn("  적립금 {$type} 실패({$v->vehicle_number}, {$item['amount']}): ".$e->getMessage());
            }
        }

        return $done;
    }

    // ────────────────────────────── 계산 헬퍼 ──────────────────────────────

    /**
     * 입금합계 − 총판매가(기타판매비용 제외). 양수 = 과입금, 음수 = 미납.
     * 이 값을 0 으로 만들어야 `settlement_exchange_rate` 배율이 1.0 이 되어 엑셀과 같아진다.
     */
    private function receivableDelta(array $row): float
    {
        $base = (float) $row['sale_price'] + (float) $row['transport_fee']
            + (float) $row['commission'] + (float) $row['auto_loading'] - (float) $row['tax_dc'];
        if ($base <= 0) {
            return 0.0;
        }
        // 입금 = 정산1~5 + 적립금사용. 🚫 선수금2 는 이미 정산1~5 에 들어 있어 더하지 않는다.
        $paid = array_sum(array_column($row['_payments'], 'amount')) + (float) $row['_savings_used'];

        return round($paid - $base, 2);
    }

    /** 귀속월 = 지급일의 전월 1일 (`26.07.10` 지급 = 6/1~6/30 정산분 — jin). */
    private function attributedMonth(string $paidAt): string
    {
        return Carbon::parse($paidAt)->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
    }

    /**
     * 프리랜서 실지급액 재계산 — 엑셀 CJ 와 대조용.
     *
     * 미수를 0 으로 만들었으므로 `settlement_exchange_rate` 배율이 1.0 = 판매환율이다.
     * ⚠️ **캐스팅 위치까지 `Settlement` 의 accessor 와 같게 유지할 것** — `(int)` 를 한 단계만
     *    옮겨도 반올림이 달라져 「1원 차이」가 무더기로 뜬다.
     */
    private function freelancePayout(array $row): float
    {
        $base = (float) $row['sale_price'] + (float) $row['commission'] + (float) $row['auto_loading'] - (float) $row['tax_dc'];
        $salesKrw = (int) ($base * (float) $row['exchange_rate']);          // sales_amount_krw
        $costs = 0;
        foreach (array_keys(self::COST_MAP) as $f) {
            $costs += (int) $row[$f];
        }
        $purchaseTotal = (int) $row['purchase_price'] + (int) $row['selling_fee'];
        $salesMargin = ($salesKrw - $costs) - $purchaseTotal;               // sales_margin
        $vatMargin = (int) ((int) $row['purchase_price'] * 0.09);           // vat_margin
        $totalMargin = (int) (($salesMargin + $vatMargin) * 0.9);           // total_margin
        $settlementAmount = (int) ($totalMargin * (50 / 100));              // settlement_amount

        return $settlementAmount - self::DOC_FEE - (self::MANUAL_DEDUCTION[$row['vehicle_number']] ?? 0);
    }

    /** 엑셀 CJ(지급액) — 재계산 대조 기준. */
    private function excelPayout(array $row): ?float
    {
        return $row['_excel_cj'] ?? null;
    }

    // ────────────────────────────── 셀 유틸 ──────────────────────────────

    /** 열 문자 → 0-based 인덱스 (A=0 · AA=26). */
    private static function idx(string $col): int
    {
        $n = 0;
        foreach (str_split(strtoupper($col)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * 행 배열에서 한 칸. 날짜 객체는 Y-m-d 로 눕힌다.
     *
     * 🚨 **수식 칸 폴백** — `rangeToArray(계산 끔)` 은 수식 셀에 **수식 문자열**을 준다.
     *    이 파일은 데이터 칸에도 수식이 섞여 있다(`AJ='=179000-1960'` · `T='=226000000-440000'`).
     *    그냥 두면 숫자 변환이 실패해 **0 으로 읽히고**, 마진이 억 단위로 튄다(실측 234행).
     *    ⇒ `=` 로 시작하면 엑셀이 저장해 둔 **캐시값**으로 대체한다.
     *    🚫 재계산(`calculateFormulas=true`)으로 풀지 말 것 — CF→CE→CC→AH 사슬을
     *      3,839 행 타야 해서 느리고, 엔진이 못 푸는 수식에서 통째로 죽는다.
     */
    private function val(array $vals, string $col, int $r): string
    {
        $v = $vals[self::idx($col)] ?? null;
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '' || $s[0] !== '=' || ! $this->sheet) {
            return $s;
        }
        $cached = $this->sheet->getCell($col.$r)->getOldCalculatedValue();
        if ($cached instanceof \DateTimeInterface) {
            return $cached->format('Y-m-d');
        }

        return trim((string) $cached);
    }

    private function toNum(string $raw): ?float
    {
        $raw = trim(str_replace([',', ' ', '원'], '', $raw));
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function toDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {   // 엑셀 시리얼
            try {
                return Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
