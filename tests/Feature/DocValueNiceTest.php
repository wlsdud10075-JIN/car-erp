<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Tests\TestCase;

/**
 * 통관 서류용 NICE 파생값 — 전용 컬럼/입력 필드 없이 nice_raw 에서 서류 생성 시점에 파싱.
 * (사용자 결정 2026-05-26: 검사종료 등은 새 컬럼 안 만들고 서류에만 적용)
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
