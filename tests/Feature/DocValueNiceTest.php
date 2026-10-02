<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Tests\TestCase;

/**
 * 통관 서류용 NICE 파생값 — nice_raw 폴백 파싱.
 * (2026-05-26 엔 「새 컬럼 없이 서류에만」이었으나 2026-10-02 부터 전용 컬럼이 우선이고 raw 는 폴백 — NiceEditableSpecFieldsTest.
 *  여기 테스트는 컬럼이 빈 차량(백필 전·NICE 미연동)의 폴백 경로를 그대로 지킨다.)
 */
class DocValueNiceTest extends TestCase
{
    public function test_parses_cylinders_and_inspection_dates_from_nice_raw(): void
    {
        $v = new Vehicle;
        $v->nice_raw = [
            'engineSpec' => '4/1950',                                  // 기통/배기량
            'resValidPeriod' => '2025-09-15 ~ 2027-09-14  주행거리:108449',
        ];

        $this->assertSame('4', DocValue::niceCylinders($v));            // 슬래시 앞
        $this->assertSame('2025-09-15', DocValue::niceInspectionStart($v));
        $this->assertSame('2027-09-14', DocValue::niceInspectionEnd($v));
    }

    public function test_returns_null_when_nice_raw_absent(): void
    {
        $v = new Vehicle;   // nice_raw 없음 (NICE 미연동 차량)

        $this->assertNull(DocValue::niceCylinders($v));
        $this->assertNull(DocValue::niceInspectionStart($v));
        $this->assertNull(DocValue::niceInspectionEnd($v));
    }
}
