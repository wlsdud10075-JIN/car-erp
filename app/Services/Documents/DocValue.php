<?php

namespace App\Services\Documents;

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
}
