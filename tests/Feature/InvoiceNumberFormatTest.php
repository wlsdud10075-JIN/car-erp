<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * item 7 (jin 2026-07-18) — Proforma Invoice No. = {영업담당자 이니셜}MU{차대번호 숫자}.
 * 이니셜/차대번호 미입력 시 기존 SC{연월}-{id} fallback.
 */
class InvoiceNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_no_uses_initials_mu_chassis_digits(): void
    {
        $sm = Salesman::create(['name' => '김영업', 'initials' => 'JK', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'KMH12AB3456789',
        ]);

        // 차대번호 KMH12AB3456789 → 숫자만 = 123456789
        $this->assertSame('123456789', DocValue::chassisDigits($v));
        $this->assertSame('JKMU123456789', DocValue::invoiceNo($v));
    }

    public function test_initials_stored_uppercase(): void
    {
        // 소문자 입력해도 저장 시 대문자 (저장 로직 strtoupper). DocValue 도 대문자 강제.
        $sm = Salesman::create(['name' => '박영업', 'initials' => 'ph', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export',
            'salesman_id' => $sm->id, 'nice_reg_vin' => 'AB000123CD',
        ]);

        $this->assertSame('PHMU000123', DocValue::invoiceNo($v));
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
