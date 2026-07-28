<?php

namespace Tests\Feature;

use App\Support\ColumnLabel;
use Tests\TestCase;

/**
 * ColumnLabel::columnAny() 는 어떤 입력에도 문자열만 반환해야 한다 (감사로그 화면 500 방지).
 *
 * 배경(2026-07-28 운영 장애): `config/column_labels.php` 의 `value_maps` 는 "컬럼→라벨" 이 아니라
 * "테이블→(컬럼→값매핑)" 2단 구조다. columnAny 가 이 그룹까지 훑는 바람에,
 * audit_logs.column_name 이 **테이블명과 겹치면**(purchase_payment_after_paid 이벤트가
 * column_name 에 'purchase_balance_payments' 를 기록) 배열이 반환돼 반환타입 위반 →
 * 감사로그 화면 전체가 500. heymanerp 실제 2건으로 재현됨.
 */
class ColumnLabelAnyTest extends TestCase
{
    /** value_maps 키(테이블명)를 컬럼명으로 넣어도 문자열 fallback 이어야 한다. */
    public function test_column_any_never_returns_array_for_value_map_table_keys(): void
    {
        foreach (array_keys(config('column_labels.value_maps', [])) as $tableKey) {
            $label = ColumnLabel::columnAny($tableKey);
            $this->assertIsString($label, "columnAny('{$tableKey}') 가 문자열이 아님");
            $this->assertSame($tableKey, $label, '매핑이 없으므로 영문 그대로 fallback 해야 한다');
        }
    }

    /** 사전 전체를 훑어도 배열이 새어나오지 않아야 한다. */
    public function test_column_any_returns_string_for_every_key_in_dictionary(): void
    {
        foreach (config('column_labels', []) as $group => $entries) {
            if (! is_array($entries)) {
                continue;
            }
            foreach (array_keys($entries) as $key) {
                $this->assertIsString(
                    ColumnLabel::columnAny($key),
                    "columnAny('{$key}') — 그룹 [{$group}] 에서 배열이 새어나옴"
                );
            }
        }
    }

    /** 정상 매핑은 그대로 한글 라벨을 준다 (회귀 방지 — 방어 코드가 기능을 죽이지 않았는지). */
    public function test_normal_column_still_maps_to_korean_label(): void
    {
        $this->assertSame('차량번호', ColumnLabel::columnAny('vehicle_number'));
        $this->assertSame('-', ColumnLabel::columnAny(null));
        $this->assertSame('알수없는컬럼', ColumnLabel::columnAny('알수없는컬럼'));
    }

    /** 테이블 지정 버전(column)도 배열을 흘리지 않는다. */
    public function test_column_with_table_never_returns_array(): void
    {
        $label = ColumnLabel::column('Vehicle', 'purchase_balance_payments');
        $this->assertIsString($label);
    }
}
