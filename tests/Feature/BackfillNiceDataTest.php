<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * vehicles:nice-backfill — NICE 미조회 차량 일괄 백필 (2026-06-11).
 * Http::fake 로 ssancar 미들웨어 응답을 흉내 → 실제 NICE 호출/과금 없이 매핑·빈칸보존·멱등 검증.
 */
class BackfillNiceDataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeNice(): void
    {
        config([
            'services.nice.provide_url' => 'https://ssancar.test/provide/api/nice-lookup/',
            'services.nice.provide_token' => 'TESTTOKEN',
        ]);
        Http::fake(['ssancar.test/*' => Http::response([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'resCarModelType' => '승용 대형',   // → nice_reg_vehicle_form
                'useFuelNm' => '휘발유(무연)',        // → nice_reg_fuel_type
                'engineSpec' => '8/5461',            // → nice_spec_displacement=5461, cc=5461
                'cbdLt' => '5210',                   // → nice_spec_length
                'cbdBt' => '1900',                   // → nice_spec_width
                'cbdHg' => '1495',                   // → nice_spec_height
                'vhcleWt' => '2115',                 // → weight_kg / nice_spec_curb_weight
                'resFirstDate' => '20150921',        // → nice_reg_first_date
                'resFinalOwner' => '김석희',          // → nice_reg_owner_name (빈칸만이므로 무시되어야)
                'resValidDistance' => '99999',       // → mileage (기존값 보존되어야)
            ],
        ], 200)]);
    }

    public function test_backfill_fills_empty_spec_and_preserves_import_values(): void
    {
        $this->fakeNice();

        // import 차량 모사: 소유자/주행거리/연식/브랜드는 있고, 제원·nice_raw 는 비어있음
        $v = Vehicle::create([
            'vehicle_number' => '25부7058', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'nice_reg_owner_name' => '김석희(상품용)',   // 꼬리표 — 보존되어야
            'mileage' => 140577, 'year' => 2016, 'brand' => 'BENZ', 'model_type' => 'S63',
        ]);

        $this->artisan('vehicles:nice-backfill', ['--ids' => (string) $v->id])->assertExitCode(0);

        $v->refresh();
        // 빈 칸 채워짐
        $this->assertNotNull($v->nice_raw, 'nice_raw 저장 안 됨');
        $this->assertSame('승용 대형', $v->nice_reg_vehicle_form);
        $this->assertSame('휘발유(무연)', $v->nice_reg_fuel_type);
        $this->assertSame('5461', (string) $v->nice_spec_displacement);
        $this->assertSame('5461', (string) $v->cc);
        $this->assertSame('5210', (string) $v->nice_spec_length);
        $this->assertSame(2115, (int) $v->weight_kg);
        $this->assertSame('2015-09-21', $v->nice_reg_first_date->format('Y-m-d'));
        // import 값 보존 (빈칸만 채움)
        $this->assertSame(140577, (int) $v->mileage, '주행거리 덮어써짐');
        $this->assertSame('김석희(상품용)', $v->nice_reg_owner_name, '소유자명 덮어써짐');
    }

    public function test_backfill_skips_vehicle_without_owner_and_is_idempotent(): void
    {
        $this->fakeNice();

        // 소유자명 없음 → 조회 불가 → 스킵
        $noOwner = Vehicle::create([
            'vehicle_number' => 'NO-OWNER', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
        ]);

        // 이미 nice_raw 있는 차량 → 멱등(재처리 안 함). 값이 안 바뀌어야.
        $already = Vehicle::create([
            'vehicle_number' => 'DONE', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'nice_reg_owner_name' => '홍길동', 'nice_raw' => ['preexisting' => true],
            'nice_reg_vehicle_form' => '기존값',
        ]);

        $this->artisan('vehicles:nice-backfill')->assertExitCode(0);

        $noOwner->refresh();
        $already->refresh();
        $this->assertNull($noOwner->nice_raw, '소유자명 없는 차량이 처리됨');
        $this->assertSame(['preexisting' => true], $already->nice_raw, '멱등 위반 — 기존 nice_raw 변경');
        $this->assertSame('기존값', $already->nice_reg_vehicle_form, '멱등 위반 — 기존값 덮어써짐');
    }
}
