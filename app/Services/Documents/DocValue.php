<?php

namespace App\Services\Documents;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Vehicle;

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

    /** 수출 서류 바이어 — 수출 바이어 우선, 없으면 일반 바이어. */
    public static function invoiceBuyer(Vehicle $v): ?Buyer
    {
        return $v->exportBuyer ?: $v->buyer;
    }

    /** 수출 서류 컨사이니(Client) — 수출 컨사이니 우선. */
    public static function invoiceConsignee(Vehicle $v): ?Consignee
    {
        return $v->exportConsignee ?: $v->consignee;
    }

    /** 컨사이니 ID(여권/주민) — 신규 id_value 우선, 없으면 legacy passport. */
    public static function consigneeIdValue(Vehicle $v): ?string
    {
        $c = self::invoiceConsignee($v);

        return $c?->id_value ?: $c?->passport;
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
            '볼보' => 'VOLVO', '르노' => 'RENAULT', '르노삼성' => 'RENAULT', '르노코리아' => 'RENAULT',
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

        return match (true) {
            str_contains($fuel, '하이브리드') => 'HYBRID',
            str_contains($fuel, '경유') || stripos($fuel, 'diesel') !== false => 'DIESEL',
            str_contains($fuel, '휘발유') || str_contains($fuel, '가솔린') || stripos($fuel, 'gasoline') !== false => 'GASOLINE',
            str_contains($fuel, '전기') || stripos($fuel, 'electric') !== false => 'ELECTRIC',
            str_contains($fuel, '수소') => 'HYDROGEN',
            stripos($fuel, 'lpg') !== false => 'LPG',
            stripos($fuel, 'cng') !== false => 'CNG',
            default => $fuel,
        };
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

    /** 목적국 — 컨사이니 국가 우선, 없으면 바이어 국가. (Country.name = 한글명) */
    public static function destinationCountry(Vehicle $v): ?string
    {
        $country = self::invoiceConsignee($v)?->country ?: self::invoiceBuyer($v)?->country;

        return $country?->name;
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
}
