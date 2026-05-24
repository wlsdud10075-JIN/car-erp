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

    /** 차명(제조사 포함) — 통관 차명 칸 등 brand+model 형식이 필요한 곳. (말소/위임장은 model-only carName 사용) */
    public static function carNameFull(Vehicle $v): string
    {
        $model = $v->model_type ?: $v->nice_spec_model ?: '';

        return trim(($v->brand ? $v->brand.' ' : '').$model);
    }

    /** NICE 응답 원본(nice_raw JSON)에서 키로 읽기. 전용컬럼 없는 NICE 필드용. NICE 연동 전엔 null(공란). */
    public static function niceRaw(Vehicle $v, string $key): mixed
    {
        return data_get($v->nice_raw, $key);
    }

    /** 목적국 — 컨사이니 국가 우선, 없으면 바이어 국가. */
    public static function destinationCountry(Vehicle $v): ?string
    {
        $country = self::invoiceConsignee($v)?->country ?: self::invoiceBuyer($v)?->country;

        return $country?->name;
    }

    /** 컨사이니 통합 블록(이름 + ID + 주소) — 통관 구매리스트 컨사이니 칸. */
    public static function consigneeBlock(Vehicle $v): ?string
    {
        $c = self::invoiceConsignee($v);
        if (! $c) {
            return null;
        }

        return trim(implode(' ', array_filter([$c->name, $c->id_value, $c->address]))) ?: null;
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
