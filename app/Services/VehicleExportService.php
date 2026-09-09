<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * 차량 데이터 export(xlsx) — 2026-06-29 라운드테이블 조건부 GO(고정 화이트리스트).
 *
 * 안전 설계:
 *  - 고정 화이트리스트(opt-in): WHITELIST 에 없는 컬럼은 절대 안 나감. 마진·snapshot 등 회계 민감 제외.
 *  - 마진/미수/진행상태/cost_total 은 accessor 경유(raw SQL 금지 — §13/§5 단일출처).
 *  - PII 마스킹(jin 2026-06-29): RRN=880717-*******, 주소=시/군/구까지, 성명=김*희. encrypted cast 라
 *    원본 컬럼($v->nice_reg_owner_rrn)을 읽으면 평문 전체 복호화 → 마스킹은 **이 클래스 한 곳에서만**,
 *    화이트리스트 fn 은 mask*() 를 거쳐서만 원본을 만진다(다른 코드가 원본 컬럼을 export 에 직접 안 씀).
 *  - formula injection: 모든 문자열 셀은 setCellValueExplicit(TYPE_STRING) → '='/'+' 시작값도 수식 실행 안 됨,
 *    값 변형 없음(앞에 ' 붙이는 방식과 달리 원본 보존).
 */
class VehicleExportService
{
    /**
     * 식별 열 — 어떤 컬럼을 골라도 항상 포함(jin 2026-08-03).
     * 정산 열만 남기고 나머지를 끄면 "어느 차량의 정산인지" 알 수 없어 엑셀 대조가 불가능했다.
     * 차량번호는 재발급으로 바뀌므로 차대번호(VIN)까지 둘 다 고정한다.
     */
    public const IDENTITY_COLUMNS = ['vehicle_number', 'chassis_number'];

    /**
     * 고정 화이트리스트. [label, type(str|num|date), fn(Vehicle), group].
     * fn 은 반드시 accessor/마스킹 경유. 원본 PII 컬럼 직접 노출 금지.
     *
     * @return array<string, array{0:string,1:string,2:callable,3:string}>
     */
    private function whitelist(): array
    {
        return [
            'vehicle_number' => ['차량번호', 'str', fn (Vehicle $v) => $v->vehicle_number, '기본'],
            // 차대번호(VIN) — 물리 차량 영구 고유키(번호판은 재발급으로 바뀐다). 정산 엑셀 대조의 매칭 키라
            //   식별 열로 고정된다(IDENTITY_COLUMNS). PII 아님(평문 컬럼, 마스킹 대상 아님).
            'chassis_number' => ['차대번호', 'str', fn (Vehicle $v) => $v->nice_reg_vin, '기본'],
            'brand' => ['브랜드', 'str', fn (Vehicle $v) => $v->brand, '기본'],
            'model_type' => ['차명', 'str', fn (Vehicle $v) => $v->model_type, '기본'],
            'year' => ['년식', 'num', fn (Vehicle $v) => $v->year, '기본'],
            'mileage' => ['주행거리', 'num', fn (Vehicle $v) => $v->mileage, '기본'],
            'sales_channel' => ['판매채널', 'str', fn (Vehicle $v) => $v->sales_channel, '기본'],
            'progress_status' => ['진행상태', 'str', fn (Vehicle $v) => $v->progress_status, '기본'],   // accessor
            'salesman' => ['담당자', 'str', fn (Vehicle $v) => $v->salesman?->name, '기본'],
            // 매입
            'purchase_date' => ['구입일자', 'date', fn (Vehicle $v) => $v->purchase_date, '매입'],
            'purchase_from' => ['구입처', 'str', fn (Vehicle $v) => $v->purchase_from, '매입'],
            'owner_name' => ['소유자(마스킹)', 'str', fn (Vehicle $v) => $this->maskName($v->nice_reg_owner_name), '매입'],
            'owner_rrn' => ['주민/법인번호(마스킹)', 'str', fn (Vehicle $v) => $this->maskRrn($v->nice_reg_owner_rrn), '매입'],
            'owner_addr' => ['사용본거지(마스킹)', 'str', fn (Vehicle $v) => $this->maskAddr($v->nice_reg_owner_addr), '매입'],
            'purchase_price' => ['구입금액', 'num', fn (Vehicle $v) => $v->purchase_price, '매입'],
            'selling_fee' => ['매도비', 'num', fn (Vehicle $v) => $v->selling_fee, '매입'],
            'cost_total' => ['비용합계', 'num', fn (Vehicle $v) => $v->cost_total, '매입'],   // accessor
            // 판매
            'buyer' => ['바이어', 'str', fn (Vehicle $v) => $v->buyer?->name, '판매'],
            // 컨사이니는 선적 탭에서 입력(당사자 축소 2026-07-09) — 화면 목록과 같은 폴백을 쓴다.
            'consignee' => ['컨사이니', 'str', fn (Vehicle $v) => $v->effective_consignee?->name, '판매'],
            'sale_date' => ['판매일자', 'date', fn (Vehicle $v) => $v->sale_date, '판매'],
            'currency' => ['통화', 'str', fn (Vehicle $v) => $v->currency, '판매'],
            'exchange_rate' => ['환율', 'num', fn (Vehicle $v) => $v->exchange_rate, '판매'],
            'sale_price' => ['판매금액', 'num', fn (Vehicle $v) => $v->sale_price, '판매'],
            'commission' => ['커미션', 'num', fn (Vehicle $v) => $v->commission, '판매'],
            'auto_loading' => ['Auto Loading', 'num', fn (Vehicle $v) => $v->auto_loading, '판매'],
            'tax_dc' => ['TAX/D.C', 'num', fn (Vehicle $v) => $v->tax_dc, '판매'],
            'transport_fee' => ['운임비', 'num', fn (Vehicle $v) => $v->transport_fee, '판매'],
            // 운임비(USD) — 판매탭 기록칸(jin 2026-08-05). 위 '운임비'(판매통화, 판매총액·미수에 반영)와 달리
            //   어떤 계산에도 안 들어가는 참고값이라 판매총액과 안 맞아도 정상이다.
            'transport_fee_usd' => ['운임비(USD)', 'num', fn (Vehicle $v) => $v->transport_fee_usd, '판매'],
            'sale_total_amount' => ['판매총액', 'num', fn (Vehicle $v) => $v->sale_total_amount, '판매'],     // accessor
            'sale_unpaid_amount' => ['미입금액', 'num', fn (Vehicle $v) => $v->sale_unpaid_amount, '판매'],   // accessor
            // 적립금 사용 (jin 2026-07-29) — 이 차량 잔금을 바이어 크레딧으로 결제한 금액(판매통화 기준).
            //   미입금액 계산에 이미 반영돼 있어(SKILLS §13) 별도 컬럼이 없으면 "왜 미수가 줄었지?" 가 안 보였다.
            'savings_used' => ['적립금사용', 'num', fn (Vehicle $v) => $v->savings_used, '판매'],
            // 선적/통관
            'shipping_date' => ['선적일ETD', 'date', fn (Vehicle $v) => $v->shipping_date, '선적'],
            'eta_date' => ['도착일ETA', 'date', fn (Vehicle $v) => $v->eta_date, '선적'],
            'bl_number' => ['B/L번호', 'str', fn (Vehicle $v) => $v->bl_number, '선적'],
            // 서류 발송(EMS·DHL) — 원본은 vehicle_shipments 행이고 여긴 캐시 컬럼을 읽는다(join 불필요).
            'ems_tracking_no' => ['EMS등기번호', 'str', fn (Vehicle $v) => $v->ems_tracking_no_cache, '발송'],
            'dhl_tracking_no' => ['DHL운송장번호', 'str', fn (Vehicle $v) => $v->dhl_tracking_no_cache, '발송'],
            'shipping_sent_date' => ['발송일', 'date', fn (Vehicle $v) => $v->shipping_sent_date_cache, '발송'],
            'ems_fee' => ['우편요금(EMS)', 'num', fn (Vehicle $v) => $v->ems_fee_total_cache, '발송'],
            'dhl_fee' => ['청구금액(DHL)', 'num', fn (Vehicle $v) => $v->dhl_fee_total_cache, '발송'],
            'shipping_fee' => ['발송비합', 'num', fn (Vehicle $v) => $v->shipping_fee_total_cache, '발송'],
            // 정산 (admin 전용 export 라우트라 허용 — 회의 2026-06-18/29 'admin 전용 마진'). 전부 accessor 경유,
            // snapshot 아닌 현재 DB 값(F슬롯). 정산 row 없으면 빈칸.
            'settlement_status' => ['정산상태', 'str', fn (Vehicle $v) => $this->settlementOf($v)?->settlement_status, '정산'],
            'sales_margin' => ['판매마진', 'num', fn (Vehicle $v) => $this->settlementOf($v)?->sales_margin, '정산'],
            'vat_margin' => ['부가세마진', 'num', fn (Vehicle $v) => $this->settlementOf($v)?->vat_margin, '정산'],
            'total_margin' => ['총마진', 'num', fn (Vehicle $v) => $this->settlementOf($v)?->total_margin, '정산'],
            'settlement_amount' => ['정산액', 'num', fn (Vehicle $v) => $this->settlementOf($v)?->settlement_amount, '정산'],
            'actual_payout' => ['실지급액', 'num', fn (Vehicle $v) => $this->settlementOf($v)?->actual_payout, '정산'],
        ];
    }

    /** 차량의 대표 정산 row(최신). accessor 가 $this->vehicle 을 참조하므로 관계 주입(N+1 방지). */
    private function settlementOf(Vehicle $v): ?Settlement
    {
        $s = $v->settlements->sortByDesc('id')->first();
        if ($s) {
            $s->setRelation('vehicle', $v);
        }

        return $s;
    }

    /**
     * export 가능 컬럼 key. 정산 그룹은 정산 접근 role(canAccessSettlement)에게만.
     *
     * @return list<string>
     */
    public function columnKeys(bool $allowSettlement = true): array
    {
        return array_keys(array_filter(
            $this->whitelist(),
            fn ($def) => $allowSettlement || $def[3] !== '정산',
        ));
    }

    /**
     * 선택 컬럼에 식별 열을 강제 포함 — 서버측 단일 관문(클라이언트 cols 파라미터를 신뢰하지 않음).
     * 빈 배열은 "전체 export" 를 뜻하므로 그대로 돌려준다(호출부가 columnKeys 로 대체).
     * 반환 순서는 화이트리스트 정의 순서 — 사용자가 고른 순서와 무관하게 열 순서를 일정하게 유지.
     *
     * @param  list<string>  $selected
     * @return list<string>
     */
    public function pinIdentityColumns(array $selected, bool $allowSettlement = true): array
    {
        if ($selected === []) {
            return [];
        }

        return array_values(array_intersect(
            $this->columnKeys($allowSettlement),
            array_unique(array_merge(self::IDENTITY_COLUMNS, $selected)),
        ));
    }

    /**
     * 팝오버 UI 용 그룹별 컬럼 목록. [그룹라벨 => [key => 컬럼라벨]].
     * 정산 그룹은 allowSettlement 일 때만 노출.
     *
     * @return array<string, array<string,string>>
     */
    public function columnsForUi(bool $allowSettlement = true): array
    {
        $grouped = [];
        foreach ($this->whitelist() as $key => $def) {
            if (! $allowSettlement && $def[3] === '정산') {
                continue;
            }
            $grouped[$def[3]][$key] = $def[0];
        }

        return $grouped;
    }

    /**
     * @param  Collection<int,Vehicle>  $vehicles
     * @param  list<string>|null  $selectedKeys  선택 컬럼(화이트리스트 key). null/빈 배열이면 전체.
     * @param  bool  $allowSettlement  false 면 정산 그룹 컬럼 강제 제외(권한 게이팅 — 클라이언트 우회 불가).
     */
    public function build(Collection $vehicles, ?array $selectedKeys = null, bool $allowSettlement = true): Spreadsheet
    {
        $cols = $this->whitelist();
        if (! $allowSettlement) {
            $cols = array_filter($cols, fn ($def) => $def[3] !== '정산');
        }
        if ($selectedKeys) {
            // 화이트리스트 교집합만(보안: 알 수 없는 key 무시). 원본 정의 순서 보존. 빈 결과면 전체로 폴백.
            $filtered = array_filter($cols, fn ($k) => in_array($k, $selectedKeys, true), ARRAY_FILTER_USE_KEY);
            $cols = $filtered !== [] ? $filtered : $cols;
        }
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('차량목록');

        // 헤더(1행)
        $i = 1;
        foreach ($cols as $def) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i).'1', $def[0], DataType::TYPE_STRING);
            $i++;
        }
        $lastCol = Coordinate::stringFromColumnIndex(count($cols));
        $sheet->getStyle('A1:'.$lastCol.'1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E5F5']],
        ]);
        $sheet->freezePane('A2');

        // 데이터(2행~)
        $row = 2;
        foreach ($vehicles as $v) {
            $c = 1;
            foreach ($cols as $def) {
                $coord = Coordinate::stringFromColumnIndex($c).$row;
                $val = $def[2]($v);
                $this->writeCell($sheet, $coord, $def[1], $val);
                $c++;
            }
            $row++;
        }

        foreach (range(1, count($cols)) as $ci) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }

        // 💰 회계실사용 상세 2시트 (jin 2026-09-09) — 차량 시트는 1대 1행이라 «무슨 돈이 언제»가 안 담긴다.
        //    한 차량에 잔금·회수이력이 여러 행이라 구조상 같은 시트에 못 넣는다.
        $this->appendPaymentSheet($ss, $vehicles);
        $this->appendReceivableSheet($ss, $vehicles);
        $ss->setActiveSheetIndex(0);   // 열었을 때 차량목록이 먼저 보이게

        return $ss;
    }

    /**
     * 「입금내역」 시트 — 확정·미확정 판매입금 **개별 행**.
     *
     * 🧭 차량 시트의 `판매총액`·`미입금액`은 합계라 실사에서 「이 돈이 언제 어느 명목으로 들어왔나」를
     *    못 짚는다. 그 매칭이 이 시트의 목적이다.
     *
     * ⚠️ **미확정(재무 확정 전) 행도 담는다** — 미수 계산에는 안 들어가지만 «입금은 됐는데 확정이 안 된»
     *    구간이 실사에서 가장 자주 질문받는 자리다. 「재무확정」 열로 구분한다.
     */
    private function appendPaymentSheet(Spreadsheet $ss, Collection $vehicles): void
    {
        // ⚠️ Eloquent 컬렉션일 때만 — Support\Collection 이 들어와도 죽지 않게(테스트·다른 호출부).
        //    each()->loadMissing 으로 대신하면 모델마다 쿼리라 N+1 이 된다.
        if ($vehicles instanceof \Illuminate\Database\Eloquent\Collection) {
            $vehicles->loadMissing(['finalPayments.financeConfirmer', 'buyer']);
        }

        $sheet = $ss->createSheet();
        $sheet->setTitle('입금내역');
        $headers = ['차량번호', '차대번호', '바이어', '통화', '구분', '금액', '입금 시점 환율',
            'KRW 환산', '입금일', '재무확정', '확정자', '차량간 이체', '비고'];
        $this->writeSheetHeader($sheet, $headers);

        $typeLabel = fn (?string $t) => match ($t) {
            'deposit_down' => __('vehicle.field.deposit_down'),
            'interim' => __('vehicle.field.interim'),
            'advance_1' => __('vehicle.field.advance1'),
            'fee' => __('vehicle.field.fee'),
            default => __('vehicle.field.balance'),
        };

        $row = 2;
        foreach ($vehicles as $v) {
            foreach ($v->finalPayments->sortBy([['payment_date', 'asc'], ['id', 'asc']]) as $fp) {
                $vals = [
                    ['str', $v->vehicle_number],
                    ['str', $v->nice_reg_vin],
                    ['str', $v->buyer?->name],
                    ['str', $v->currency],
                    ['str', $typeLabel($fp->type)],
                    ['num', $fp->amount],
                    ['num', $fp->exchange_rate],
                    ['num', $fp->amount_krw],
                    ['date', $fp->payment_date],
                    ['str', $fp->confirmed_at ? __('common.yes') : __('common.no')],
                    ['str', $fp->financeConfirmer?->name],
                    ['str', $fp->transfer_id ? __('common.yes') : ''],
                    ['str', $fp->note],
                ];
                $this->writeSheetRow($sheet, $row++, $vals);
            }
        }
        $this->autoSize($sheet, count($headers));
    }

    /**
     * 「회수이력」 시트 — 입금·현금·상계·기타·손실·적립금사용·**잡손실** 개별 행.
     *
     * 🚨 **「미수반영」 열이 이 시트의 핵심이다.** `입금`·`적립금 사용`·`잡손실`은 다른 기록의 미러라
     *    미수 계산에서 빠진다(`Vehicle::MIRRORED_RECEIVABLE_METHODS`). 그 표시가 없으면 실사자가
     *    행을 그냥 더해서 «장부가 안 맞는다»고 읽는다 — 실제로는 중복 계상을 피한 결과다.
     */
    private function appendReceivableSheet(Spreadsheet $ss, Collection $vehicles): void
    {
        // ⚠️ Eloquent 컬렉션일 때만 — Support\Collection 이 들어와도 죽지 않게(테스트·다른 호출부).
        //    each()->loadMissing 으로 대신하면 모델마다 쿼리라 N+1 이 된다.
        if ($vehicles instanceof \Illuminate\Database\Eloquent\Collection) {
            $vehicles->loadMissing(['receivableHistories.collector', 'buyer']);
        }

        $sheet = $ss->createSheet();
        $sheet->setTitle('회수이력');
        $headers = ['차량번호', '차대번호', '바이어', '통화', '방식', '금액', '환율',
            '수금일', '회수담당자', '미수반영', '연결 잔금 ID', '비고'];
        $this->writeSheetHeader($sheet, $headers);

        $mirrored = Vehicle::MIRRORED_RECEIVABLE_METHODS;

        $row = 2;
        foreach ($vehicles as $v) {
            foreach ($v->receivableHistories->sortBy([['collected_at', 'asc'], ['id', 'asc']]) as $h) {
                $vals = [
                    ['str', $v->vehicle_number],
                    ['str', $v->nice_reg_vin],
                    ['str', $v->buyer?->name],
                    ['str', $v->currency],
                    ['str', __('receivable.method.'.$h->method)],
                    ['num', $h->amount],
                    ['num', $h->exchange_rate],
                    ['date', $h->collected_at],
                    ['str', $h->collector?->name],
                    // 미수에 «또» 반영되는 행인가 — 미러 항목은 아니오.
                    ['str', in_array($h->method, $mirrored, true) ? __('common.no') : __('common.yes')],
                    ['str', $h->final_payment_id ? (string) $h->final_payment_id : ''],
                    ['str', $h->note],
                ];
                $this->writeSheetRow($sheet, $row++, $vals);
            }
        }
        $this->autoSize($sheet, count($headers));
    }

    /** 상세 시트 공통 — 헤더 1행(차량목록 시트와 같은 서식·고정). */
    private function writeSheetHeader($sheet, array $headers): void
    {
        $i = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i).'1', $h, DataType::TYPE_STRING);
            $i++;
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$last.'1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8E5F5']],
        ]);
        $sheet->freezePane('A2');
    }

    /** @param  array<int, array{0: string, 1: mixed}>  $vals  [타입, 값] 쌍 — writeCell 과 같은 규칙(수식 주입 방어). */
    private function writeSheetRow($sheet, int $row, array $vals): void
    {
        $c = 1;
        foreach ($vals as [$type, $val]) {
            $this->writeCell($sheet, Coordinate::stringFromColumnIndex($c).$row, $type, $val);
            $c++;
        }
    }

    private function autoSize($sheet, int $count): void
    {
        foreach (range(1, $count) as $ci) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($ci))->setAutoSize(true);
        }
    }

    private function writeCell($sheet, string $coord, string $type, mixed $val): void
    {
        if ($val === null || $val === '') {
            return;   // 빈칸 유지
        }
        switch ($type) {
            case 'num':
                $sheet->setCellValueExplicit($coord, (string) $val, DataType::TYPE_NUMERIC);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0');
                break;
            case 'date':
                // Carbon/날짜 → 'Y-m-d' 문자열(TYPE_STRING 이라 formula injection 불가). 읽기용.
                $s = $val instanceof \DateTimeInterface ? $val->format('Y-m-d') : (string) $val;
                $sheet->setCellValueExplicit($coord, $s, DataType::TYPE_STRING);
                break;
            default: // str — 무조건 TYPE_STRING. '='/'+'/'-'/'@' 시작값도 수식 실행 안 됨(무손실).
                $sheet->setCellValueExplicit($coord, (string) $val, DataType::TYPE_STRING);
        }
    }

    // ── PII 마스킹 (이 클래스 단일 출처) ────────────────────────────

    /** 880717-1234567 → 880717-******* (생년월일만 + 뒤 7자리 전부 가림). 표준 개인정보 마스킹. */
    public function maskRrn(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) >= 6) {
            return substr($digits, 0, 6).'-*******';
        }

        return '*******';
    }

    /** 김혜진 → 김*진 / 김논자(개인매입) → 김*자 (괄호 주석 제거 후 가운데 마스킹). */
    public function maskName(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $name = preg_split('/[(\s]/u', $raw)[0] ?? $raw;   // 괄호/공백 앞 실명만
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name;
        }
        if ($len === 2) {
            return mb_substr($name, 0, 1).'*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', $len - 2).mb_substr($name, $len - 1, 1);
    }

    /** 경기도 수원시 권선구 권선로 308-5... → 경기도 수원시 권선구 *** (시/군/구까지만). */
    public function maskAddr(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $keep = [];
        foreach (preg_split('/\s+/u', $raw) as $token) {
            if (in_array(mb_substr($token, -1), ['도', '시', '군', '구'], true)) {
                $keep[] = $token;
            } else {
                break;
            }
        }

        return $keep === [] ? '***' : implode(' ', $keep).' ***';
    }
}
