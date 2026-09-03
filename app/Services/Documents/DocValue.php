<?php

namespace App\Services\Documents;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * 서류 매핑에서 공유하는 값 resolver. 같은 의미의 칸이 여러 서류에 나올 때
 * 한 곳에서만 정의해 drift 를 막는다 (advisor 2026-05-24 권고).
 */
class DocValue
{
    /**
     * 차명 — 차명 칸이 나오는 모든 서류(말소·위임장·통관·CIPL)가 이걸 호출.
     * NICE commCarName→model_type 이 "차량명". 없으면 spec model, 그것도 없으면 brand+spec.
     */
    public static function carName(Vehicle $v): string
    {
        return trim((string) (
            $v->model_type
            ?: $v->nice_spec_model
            ?: trim(($v->brand ? $v->brand.' ' : '').($v->nice_spec_model ?? ''))
        ));
    }

    /**
     * 수출 서류 바이어 — 수출(통관) → 선적(B/L) → 판매 순 fallback (jin 2026-07-09 당사자 축소).
     * 바이어는 판매에서 입력, 통관이 이어받음(export_buyer_id). 어느 단계에서 채워지든 서류가 잡도록 3단 fallback.
     */
    public static function invoiceBuyer(Vehicle $v): ?Buyer
    {
        return $v->exportBuyer ?: $v->blBuyer ?: $v->buyer;
    }

    /**
     * 수출 서류 컨사이니(Client) — 수출(통관) → 선적(B/L) → 판매 순 fallback.
     * 컨사이니는 선적에서 입력, 통관이 이어받음(export_consignee_id). 3단 fallback 으로 단계 무관 안전.
     * 폴백 정의는 `Vehicle::effective_consignee` 단일 출처 — 화면·엑셀도 같은 값을 본다(2026-08-07).
     */
    public static function invoiceConsignee(Vehicle $v): ?Consignee
    {
        return $v->effective_consignee;
    }

    /** 컨사이니 ID(여권/주민) — 신규 id_value 우선, 없으면 legacy passport. */
    public static function consigneeIdValue(Vehicle $v): ?string
    {
        $c = self::invoiceConsignee($v);

        return $c?->id_value ?: $c?->passport;
    }

    /**
     * 차대번호(nice_reg_vin) 끝자리 숫자 — Invoice No. 접미 (item 7, jin 2026-07-18/21).
     * VIN 은 알파벳이 끝난 뒤 숫자로 끝남 → 마지막 연속 숫자 묶음(보통 6~7자리)만.
     * 중간 숫자는 제외(구: 전체 숫자 → 예 KMHJ581ABGU108491 이 581108491 로 잘못 뽑힘).
     */
    public static function chassisDigits(Vehicle $v): string
    {
        return preg_match('/(\d+)$/', (string) ($v->nice_reg_vin ?? ''), $m) ? $m[1] : '';
    }

    /**
     * Proforma Invoice No. = {영업담당자 이니셜}{차대번호 끝자리 숫자} (item 7, jin 2026-07-18/21).
     * 이니셜 자체가 회사 코드(예: 무사백=MU) — 리터럴 접두 없음(구: 리터럴 'MU' 가 이니셜과 겹쳐 MUMU 중복).
     * 이니셜 또는 차대번호 미입력 시 기존 SC{연월}-{id} 포맷으로 fallback.
     */
    public static function invoiceNo(Vehicle $v): string
    {
        $initials = strtoupper(trim((string) ($v->salesman?->initials ?? '')));
        $digits = self::chassisDigits($v);
        if ($initials !== '' && $digits !== '') {
            return $initials.$digits;
        }

        return 'SC'.now()->format('ym').'-'.str_pad((string) ($v->id ?? 0), 5, '0', STR_PAD_LEFT);
    }

    /** 확정 입금 합(= 인보이스 DEPOSIT). 22-A 통합으로 모든 입금유형이 confirmed FP. 판매통화 기준. */
    public static function confirmedReceived(Vehicle $v): float
    {
        if (! $v->exists) {
            return 0.0;
        }

        return (float) $v->finalPayments()->whereNotNull('confirmed_at')->sum('amount');
    }

    /**
     * 금액 셀 — float 로 강제. (sale_price 등 decimal cast 는 PHP 에서 문자열 "3760.00" 이라
     * writeCell 이 텍스트로 박제 → Excel SUM 이 텍스트를 무시해 SUB TOTAL/GRAND TOTAL 이 0 이 됨.
     * float 로 넘겨 숫자 셀로 기입해야 footer 합산 수식이 Excel 에서 동작.)
     * null/'' 은 null 유지(빈 칸).
     */
    public static function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /** 차명(제조사 포함) — 통관 차명 칸 등 brand+model 형식이 필요한 곳. (말소/위임장은 model-only carName 사용) */
    public static function carNameFull(Vehicle $v): string
    {
        $model = $v->model_type ?: $v->nice_spec_model ?: '';

        return trim(($v->brand ? $v->brand.' ' : '').$model);
    }

    /**
     * 제조사 영문 — NICE 는 한글(벤츠·아우디)로 보냄. 수출서류는 영문 필요.
     * 매핑에 없으면 원본 그대로(이미 영문이거나 미지정 브랜드 → 통과). 기존 import 영문값 보존.
     */
    public static function brandEn(Vehicle $v): string
    {
        $brand = trim((string) $v->brand);

        return [
            '벤츠' => 'BENZ', '메르세데스벤츠' => 'BENZ', '메르세데스-벤츠' => 'BENZ',
            '비엠더블유' => 'BMW', '아우디' => 'AUDI', '폭스바겐' => 'Volkswagen',
            '볼보' => 'VOLVO', '르노' => 'RENAULT', '르노코리아' => 'RENAULT',
            // 삼성 계열 표기는 `Renault Samsung` 으로 (jin 2026-09-03). 모델명은 별도 칸이라 그대로 붙는다.
            //   ⚠️ 괄호형(`르노(삼성)`)이 운영 실제 값이다 — heymanerp 3대. 둘을 다르게 두면 같은 브랜드가 갈린다.
            '르노(삼성)' => 'Renault Samsung', '르노삼성' => 'Renault Samsung',
            '기아' => 'KIA', '현대' => 'HYUNDAI', '제네시스' => 'GENESIS',
            '쌍용' => 'SSANGYONG', '롤스로이스' => 'ROLLSROYCE', '푸조' => 'Peugeot',
            '도요타' => 'TOYOTA', '토요타' => 'TOYOTA', '렉서스' => 'LEXUS', '혼다' => 'HONDA',
            '닛산' => 'NISSAN', '포드' => 'FORD', '쉐보레' => 'CHEVROLET', '시보레' => 'CHEVROLET',
            '지프' => 'JEEP', '크라이슬러' => 'CHRYSLER', '캐딜락' => 'CADILLAC', '링컨' => 'LINCOLN',
            '포르쉐' => 'PORSCHE', '미니' => 'MINI', '재규어' => 'JAGUAR', '랜드로버' => 'LAND ROVER',
            '마세라티' => 'MASERATI', '페라리' => 'FERRARI', '람보르기니' => 'LAMBORGHINI',
            '벤틀리' => 'BENTLEY', '테슬라' => 'TESLA',
        ][$brand] ?? $brand;
    }

    /**
     * 연료 영문 — NICE 한글(휘발유·경유·하이브리드(휘발유+전기) 등). 통관 SET I13 수식과 동일 컨벤션
     * (GASOLINE/DIESEL/LPG/HYBRID/ELECTRIC). 괄호 변형까지 잡도록 부분일치, 하이브리드 우선.
     */
    public static function fuelEn(Vehicle $v): ?string
    {
        $fuel = trim((string) $v->nice_reg_fuel_type);
        if ($fuel === '') {
            return null;
        }

        // ⚠️ 하이브리드를 **맨 먼저** — `하이브리드(경유+전기)` 가 DIESEL 로 새면 안 된다.
        return match (true) {
            str_contains($fuel, '하이브리드') => 'HYBRID',
            str_contains($fuel, '경유') || str_contains($fuel, '디젤') || stripos($fuel, 'diesel') !== false => 'DIESEL',
            str_contains($fuel, '휘발유') || str_contains($fuel, '가솔린') || stripos($fuel, 'gasoline') !== false => 'GASOLINE',
            str_contains($fuel, '전기') || stripos($fuel, 'electric') !== false => 'ELECTRIC',
            str_contains($fuel, '수소') => 'HYDROGEN',
            str_contains($fuel, '엘피지') || stripos($fuel, 'lpg') !== false => 'LPG',
            stripos($fuel, 'cng') !== false => 'CNG',
            default => $fuel,
        };
    }

    /**
     * 차종 영문 — **변환의 단일 출처**. 통관 SET 영문등록증(구매리스트 I6 → M4)과 말소증 영문시트가 함께 쓴다.
     *
     * 🔀 2026-09-03 — 종전엔 구매리스트 I6 의 **중첩 SUBSTITUTE 수식**(3사 양식에 각각 복제)이 했는데,
     *    실제 NICE 값과 어긋나 있었다. 운영 실측(heymanerp 265대):
     *      승용 중형 148 · 승용 대형 71 · 승용 소형 1 · **승합 중형 1** · (쓰레기값 `205 004` 1)
     *    그 수식은 승합·화물을 「중형 승합」 순서로 찾아 **「승합 중형」을 못 잡고 한글이 그대로 인쇄**됐고,
     *    승용 대형만 `HEAVY Passenger`, 승합은 `Ven`(Van 오타) 이었다.
     *    ⇒ 수식을 걷어내고(`scripts/fix-clearance-vehicle-type-en.php`) 여기로 합쳤다 — SKILLS §8 #45.
     *
     * 순서·띄어쓰기를 안 가린다: `승용 중형` · `중형 승용` · `중형승용`(옛 적재분) 전부 같은 결과.
     * 종류를 못 찾으면 **원본 그대로 통과** — 쓰레기값을 영문으로 위장하지 않는다.
     */
    public static function vehicleFormEn(Vehicle $v): ?string
    {
        $form = trim((string) $v->nice_reg_vehicle_form);
        if ($form === '') {
            return null;
        }

        $kinds = ['승용' => 'Passenger', '승합' => 'Van', '화물' => 'Cargo', '특수' => 'Special'];
        $sizes = ['경형' => 'Light', '소형' => 'Small', '중형' => 'Medium', '대형' => 'Large'];

        $find = function (array $table) use ($form): ?string {
            foreach ($table as $ko => $en) {
                if (str_contains($form, $ko)) {
                    return $en;
                }
            }

            return null;
        };

        $kind = $find($kinds);
        if ($kind === null) {
            return $form;   // 모르는 값 — 그대로 둔다
        }

        $size = $find($sizes);

        return trim(($size !== null ? $size.' ' : '').$kind);
    }

    /**
     * 용도 영문 — NICE `nice_reg_use_type`(자가용·영업용·관용).
     * 통관 SET 영문등록증 P4 의 SUBSTITUTE 수식과 **같은 규칙**(Private Car / Business / Official).
     * 실측 heymanerp: 자가용 221 · 영업용 3 — 「영업용」을 자가용으로 찍던 SKILLS §8 #71 의 그 3대.
     */
    public static function useTypeEn(Vehicle $v): ?string
    {
        $use = trim((string) ($v->nice_reg_use_type ?: self::niceRaw($v, 'resUseType')));
        if ($use === '') {
            return null;
        }

        return ['자가용' => 'Private Car', '영업용' => 'Business', '관용' => 'Official'][$use] ?? $use;
    }

    /** NICE 응답 원본(nice_raw JSON)에서 키로 읽기. 전용컬럼 없는 NICE 필드용. NICE 연동 전엔 null(공란). */
    public static function niceRaw(Vehicle $v, string $key): mixed
    {
        return data_get($v->nice_raw, $key);
    }

    /**
     * NICE engineSpec "기통/배기량"(예: "4/1950") → 기통수(슬래시 앞 숫자).
     * 전용 컬럼/입력 필드 없이 nice_raw 에서 서류 생성 시점에만 파싱 (사용자 결정).
     */
    public static function niceCylinders(Vehicle $v): ?string
    {
        $spec = (string) self::niceRaw($v, 'engineSpec');
        $head = str_contains($spec, '/') ? substr($spec, 0, strpos($spec, '/')) : $spec;

        return preg_match('/\d+/', $head, $m) ? $m[0] : null;
    }

    /**
     * NICE resValidPeriod "2025-09-15 ~ 2027-09-14  주행거리:..." → 검사 유효기간 [시작, 종료] 날짜.
     * 형식에서 YYYY-MM-DD 를 순서대로 추출(첫째=시작, 둘째=종료). 단일 날짜면 종료는 null.
     */
    private static function niceValidPeriodDates(Vehicle $v): array
    {
        preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) self::niceRaw($v, 'resValidPeriod'), $m);

        return [$m[0][0] ?? null, $m[0][1] ?? null];
    }

    /** 검사 유효기간 시작일 (resValidPeriod 첫 날짜). */
    public static function niceInspectionStart(Vehicle $v): ?string
    {
        return self::niceValidPeriodDates($v)[0];
    }

    /** 검사 유효기간 종료일 (resValidPeriod 둘째 날짜). */
    public static function niceInspectionEnd(Vehicle $v): ?string
    {
        return self::niceValidPeriodDates($v)[1];
    }

    /**
     * 목적국 — 컨사이니 국가 우선, 없으면 바이어 국가.
     * 영문 수출서류라 한글 name 대신 영문명(Country::name_en, code→영문 맵) 사용 (jin 2026-07-06 quick win ⑤).
     * 통관을 건너뛰고 선적만 해서 목적항(dischargePort) 미입력일 때 dischargeDestination이 이 값으로
     * fallback 하므로, 한글 국가명이 서류에 박히던 문제를 근본 해결.
     */
    public static function destinationCountry(Vehicle $v): ?string
    {
        $country = self::invoiceConsignee($v)?->country ?: self::invoiceBuyer($v)?->country;

        return $country?->name_en;
    }

    /**
     * 선적 서류 Discharge / Final Destination — 입력한 목적항(discharge_port, 영문) 우선.
     * 양식이 영문 수출서류라 목적국 한글명("코소보") 대신 입력 항구명("DURRESS, ALBANIA")을 쓴다.
     * 목적항 미입력 차량은 목적국명으로 fallback (기존 동작 유지, 빈칸 방지).
     */
    public static function dischargeDestination(Vehicle $v): ?string
    {
        return $v->dischargePort?->name ?: self::destinationCountry($v);
    }

    /**
     * 컨사이니 통합 블록 — 통관 구매리스트 B14 + 선적 컨테이너/RORO 인보이스 수하인칸 공용.
     * 이름 + ID + 주소 + 이메일 + 전화 + 담당자. 줄바꿈 조인(대상 셀 전부 wrapText 확인됨).
     *
     * $labelIdValue=true 면 ID·주소 줄에 'Business number : '·'ADDRESS : ' 라벨을 붙인다(통관 B14 — jin 2026-06-25/06-29).
     * 선적 인보이스(B9)는 라벨 없이 값만(기존 동작 유지).
     */
    public static function consigneeBlock(Vehicle $v, bool $labelIdValue = false): ?string
    {
        $c = self::invoiceConsignee($v);
        if (! $c) {
            return null;
        }

        $idLine = $c->id_value;
        if ($labelIdValue && $idLine !== null && trim((string) $idLine) !== '') {
            $idLine = 'Business number : '.$idLine;
        }

        $addressLine = $c->address;
        if ($labelIdValue && $addressLine !== null && trim((string) $addressLine) !== '') {
            $addressLine = 'ADDRESS : '.$addressLine;
        }

        $lines = array_filter([
            $c->name,
            $idLine,
            $addressLine,
            $c->contact_email ? 'EMAIL: '.$c->contact_email : null,
            $c->contact_phone ? 'TEL: '.$c->contact_phone : null,
            $c->contact_name ? 'ATTN: '.$c->contact_name : null,
        ], fn ($l) => $l !== null && trim((string) $l) !== '');

        return $lines ? implode("\n", $lines) : null;
    }

    /**
     * 한글 차량번호 → 로마자 (영문차량번호). 예 "19더9065" → "19DEO9065".
     * 번호판 용도기호 표준 32자 + 배. 숫자·기타는 그대로 둠.
     */
    public static function romanizePlate(?string $plate): ?string
    {
        if (! $plate) {
            return null;
        }

        static $map = [
            '가' => 'GA', '거' => 'GEO', '고' => 'GO', '구' => 'GU',
            '나' => 'NA', '너' => 'NEO', '노' => 'NO', '누' => 'NU',
            '다' => 'DA', '더' => 'DEO', '도' => 'DO', '두' => 'DU',
            '라' => 'RA', '러' => 'REO', '로' => 'RO', '루' => 'RU',
            '마' => 'MA', '머' => 'MEO', '모' => 'MO', '무' => 'MU',
            '바' => 'BA', '버' => 'BEO', '보' => 'BO', '부' => 'BU',
            '사' => 'SA', '서' => 'SEO', '소' => 'SO', '수' => 'SU',
            '아' => 'A', '어' => 'EO', '오' => 'O', '우' => 'U',
            '자' => 'JA', '저' => 'JEO', '조' => 'JO', '주' => 'JU',
            '하' => 'HA', '허' => 'HEO', '호' => 'HO', '배' => 'BAE',
        ];

        return strtr($plate, $map);
    }

    /**
     * 서류 「기타 청구」 — Commission + Auto Loading − TAX D/C. 판매통화, 1대분.
     *
     * 🚨 **이 식을 서류마다 옮겨 적지 말 것** (SKILLS §8 #45). 예전엔 인보이스·판매계약서에
     *    각각 복제돼 있었고, 나머지 5종(통관SET·container/roro invoice·contract)은 아예 빠져
     *    **판매가가 `sale_price + transport_fee` 만** 찍히고 있었다(jin 2026-08-28 제보).
     * ⚠️ TAX D/C 는 **할인**이라 뺀다. 양식 푸터가 `SUM(...)` 으로 「더하는」 칸에 따로 적을
     *    때는 부호를 뒤집어 넣어야 한다(SalesInvoice E55 가 그 형태).
     */
    public static function otherCharge(Vehicle $v): float
    {
        return (float) (($v->commission ?? 0) + ($v->auto_loading ?? 0) - ($v->tax_dc ?? 0));
    }

    /** 위 것의 컬렉션 합 — 다중차량 서류 푸터용. */
    public static function otherChargeSum(Collection $vs): float
    {
        return (float) $vs->sum(fn (Vehicle $v) => self::otherCharge($v));
    }

    /**
     * 서류 「총 판매금액」 — Σ(판매가 + 운임비 + 기타청구). 바이어에게 청구하는 금액.
     *
     * ⚠️ ERP 의 **총판매가(`Vehicle::sale_total_amount`)와 한 항 다르다** — 여기엔
     *    `sale_other_costs`(기타 판매비용)가 **없다**. 서류 어느 양식에도 그 칸이 없기 때문이다.
     *    ⇒ 이 값을 「받을 돈」으로 쓰지 말 것. 미수·채권은 `Vehicle::sale_unpaid_amount` 가 단일 출처다.
     * ⚠️ 정산 기준액(`Settlement::sales_amount_krw`)과도 다르다 — 그건 운임비를 빼고 **KRW** 다.
     *    셋의 차이는 SKILLS §13 「한 글자 차이인 세 합계」 표 참조.
     */
    public static function documentSaleTotal(Collection $vs): float
    {
        return (float) $vs->sum(fn (Vehicle $v) => ($v->sale_price ?? 0)
            + ($v->transport_fee ?? 0)
            + self::otherCharge($v));
    }
}
