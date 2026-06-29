<?php

namespace App\Services;

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
     * 고정 화이트리스트. [label, type(str|num|date), fn(Vehicle), group].
     * fn 은 반드시 accessor/마스킹 경유. 원본 PII 컬럼 직접 노출 금지.
     *
     * @return array<string, array{0:string,1:string,2:callable,3:string}>
     */
    private function whitelist(): array
    {
        return [
            'vehicle_number' => ['차량번호', 'str', fn (Vehicle $v) => $v->vehicle_number, '기본'],
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
            'consignee' => ['컨사이니', 'str', fn (Vehicle $v) => $v->consignee?->name, '판매'],
            'sale_date' => ['판매일자', 'date', fn (Vehicle $v) => $v->sale_date, '판매'],
            'currency' => ['통화', 'str', fn (Vehicle $v) => $v->currency, '판매'],
            'exchange_rate' => ['환율', 'num', fn (Vehicle $v) => $v->exchange_rate, '판매'],
            'sale_price' => ['판매금액', 'num', fn (Vehicle $v) => $v->sale_price, '판매'],
            'commission' => ['커미션', 'num', fn (Vehicle $v) => $v->commission, '판매'],
            'auto_loading' => ['Auto Loading', 'num', fn (Vehicle $v) => $v->auto_loading, '판매'],
            'tax_dc' => ['TAX/D.C', 'num', fn (Vehicle $v) => $v->tax_dc, '판매'],
            'transport_fee' => ['운임비', 'num', fn (Vehicle $v) => $v->transport_fee, '판매'],
            'sale_total_amount' => ['판매총액', 'num', fn (Vehicle $v) => $v->sale_total_amount, '판매'],     // accessor
            'sale_unpaid_amount' => ['미입금액', 'num', fn (Vehicle $v) => $v->sale_unpaid_amount, '판매'],   // accessor
            // 선적/통관
            'shipping_date' => ['선적일ETD', 'date', fn (Vehicle $v) => $v->shipping_date, '선적'],
            'eta_date' => ['도착일ETA', 'date', fn (Vehicle $v) => $v->eta_date, '선적'],
            'bl_number' => ['B/L번호', 'str', fn (Vehicle $v) => $v->bl_number, '선적'],
        ];
    }

    /** @return list<string> export 컬럼 key (감사 로그용) */
    public function columnKeys(): array
    {
        return array_keys($this->whitelist());
    }

    /**
     * 팝오버 UI 용 그룹별 컬럼 목록. [그룹라벨 => [key => 컬럼라벨]].
     *
     * @return array<string, array<string,string>>
     */
    public function columnsForUi(): array
    {
        $grouped = [];
        foreach ($this->whitelist() as $key => $def) {
            $grouped[$def[3]][$key] = $def[0];
        }

        return $grouped;
    }

    /**
     * @param  Collection<int,Vehicle>  $vehicles
     * @param  list<string>|null  $selectedKeys  선택 컬럼(화이트리스트 key). null/빈 배열이면 전체.
     */
    public function build(Collection $vehicles, ?array $selectedKeys = null): Spreadsheet
    {
        $cols = $this->whitelist();
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

        return $ss;
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
