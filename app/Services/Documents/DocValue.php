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
}
