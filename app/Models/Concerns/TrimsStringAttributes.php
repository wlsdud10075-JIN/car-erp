<?php

namespace App\Models\Concerns;

/**
 * 문자열 속성 앞뒤 공백 자동 제거 (jin 2026-08-06).
 *
 * 왜 필요한가 — `xh123` 과 `xh123 `(뒤 공백)이 **다른 값으로 저장**되면 검색·집계가 조용히 갈린다.
 * 화면에서는 같아 보여서 "왜 두 건으로 나오지?" 로만 드러난다.
 *
 * 왜 `setAttribute` 인가 — 여기서 자르면 **cast 가 적용되기 전(평문)** 에 처리된다.
 *   · 암호화 cast(`encrypted`) 컬럼도 평문 상태에서 잘린 뒤 암호화된다(이중 암호화 없음).
 *   · `saving` 훅에서 `getDirty()` 를 훑는 방식은 이미 암호화된 값을 다시 대입하게 되어 위험하다.
 *   · fill/create/update/직접대입 어느 경로로 와도 전부 이 지점을 지난다.
 *
 * ⚠️ DB 에서 읽어올 때(hydrate)는 이 경로를 안 지난다 — **이미 저장된 값은 그대로**다.
 *    기존 데이터를 정리하려면 별도 일괄 처리가 필요하다.
 * ⚠️ 앞뒤만 자른다. 문자열 가운데 공백(`GMT  MERCURY`)은 건드리지 않는다.
 */
trait TrimsStringAttributes
{
    public function setAttribute($key, $value)
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return parent::setAttribute($key, $value);
    }
}
