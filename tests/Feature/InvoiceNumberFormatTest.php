<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * item 7 (jin 2026-07-18/21) — Proforma Invoice No. = {영업담당자 이니셜}{차대번호 끝자리 숫자}.
 * 차대번호는 마지막 연속 숫자 묶음(6~7자리)만. 리터럴 'MU' 접두 없음(이니셜 자체가 회사코드).
 * 이니셜/차대번호 미입력 시 기존 SC{연월}-{id} fallback.
 */
class InvoiceNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_no_uses_initials_and_trailing_chassis_digits(): void
    {
        $sm = Salesman::create(['name' => '김영업', 'initials' => 'JK', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'KMHJ581ABGU108491',
        ]);

        // 끝자리 숫자만 = 108491 (마지막 알파벳 U 뒤). 중간 581 은 제외.
        $this->assertSame('108491', DocValue::chassisDigits($v));
        $this->assertSame('JK108491', DocValue::invoiceNo($v));
    }

    public function test_initials_mu_not_doubled(): void
    {
        // 무사백 이니셜=MU. 리터럴 MU 제거로 MUMU 중복 방지 — MU + 끝자리숫자.
        $sm = Salesman::create(['name' => '무사백', 'initials' => 'MU', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '55마5555', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'VF15RBJ0DJR437869',
        ]);

        $this->assertSame('437869', DocValue::chassisDigits($v));
        $this->assertSame('MU437869', DocValue::invoiceNo($v));
    }

    public function test_seven_digit_trailing(): void
    {
        // 끝자리 7자리 케이스 (jin: 6~7자리 가변).
        $sm = Salesman::create(['name' => '정영업', 'initials' => 'JU', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '66바6666', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'WAUZZZFY8M2112213',
        ]);

        $this->assertSame('2112213', DocValue::chassisDigits($v));
        $this->assertSame('JU2112213', DocValue::invoiceNo($v));
    }

    public function test_initials_stored_uppercase(): void
    {
        // 소문자 입력해도 저장 시 대문자 (저장 로직 strtoupper). DocValue 도 대문자 강제.
        $sm = Salesman::create(['name' => '박영업', 'initials' => 'ph', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'ABCD000123',
        ]);

        $this->assertSame('PH000123', DocValue::invoiceNo($v));
    }

    public function test_fallback_when_no_initials_or_chassis(): void
    {
        // 이니셜 없음 → 기존 SC 포맷 fallback
        $sm = Salesman::create(['name' => '이영업', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'KMH999',
        ]);

        $this->assertStringStartsWith('SC', DocValue::invoiceNo($v));

        // 차대번호 없음 → fallback
        $sm2 = Salesman::create(['name' => '최영업', 'initials' => 'CH', 'is_active' => true]);
        $v2 = Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'export', 'salesman_id' => $sm2->id]);
        $this->assertStringStartsWith('SC', DocValue::invoiceNo($v2));
    }
}
