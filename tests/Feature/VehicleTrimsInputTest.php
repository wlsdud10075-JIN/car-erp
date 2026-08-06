<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량 입력값 앞뒤 공백 자동 제거 (jin 2026-08-06).
 *
 * '19더9065' 와 '19더9065 ' 가 다른 값으로 저장되면 검색·집계가 조용히 갈린다
 * (화면에선 같아 보여 "왜 두 건이지?" 로만 드러난다).
 *
 * ⚠️ 이미 DB 에 저장된 값은 안 바뀐다 — hydrate 는 setAttribute 를 안 지난다.
 */
class VehicleTrimsInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_trims_on_create(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '  19더9065  ',
            'brand' => " 현대\t",
            'model_type' => "\n포터2 ",
            'sales_channel' => 'export',
            'dhl_request' => false,
        ]);

        $this->assertSame('19더9065', $v->vehicle_number);
        $this->assertSame('현대', $v->brand);
        $this->assertSame('포터2', $v->model_type);

        // DB 에 실제로 잘려 들어갔는지 (accessor 눈속임 아님)
        $this->assertSame('19더9065', DB::table('vehicles')->where('id', $v->id)->value('vehicle_number'));
    }

    public function test_trims_on_update_and_direct_assignment(): void
    {
        $v = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'dhl_request' => false]);

        $v->update(['vessel_name' => '  GMT MERCURY  ']);
        $this->assertSame('GMT MERCURY', $v->fresh()->vessel_name);

        $v->container_number = ' TCLU1234567 ';
        $v->save();
        $this->assertSame('TCLU1234567', $v->fresh()->container_number);
    }

    /** 가운데 공백은 건드리지 않는다 — 선박명·주소처럼 띄어쓰기가 의미 있는 값이 많다. */
    public function test_keeps_inner_spaces(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '22가2222',
            'vessel_name' => '  GMT  MERCURY  ',
            'sales_channel' => 'export',
            'dhl_request' => false,
        ]);

        $this->assertSame('GMT  MERCURY', $v->vessel_name);
    }

    /**
     * 암호화 cast 컬럼도 **평문 상태에서** 잘린 뒤 암호화돼야 한다.
     * saving 훅에서 getDirty() 를 훑는 방식이었다면 이미 암호화된 값을 다시 대입해 깨졌을 지점.
     */
    public function test_trims_encrypted_columns_before_encrypting(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '33가3333',
            'purchase_seller_account' => '  110-123-456789  ',
            'sales_channel' => 'export',
            'dhl_request' => false,
        ]);

        $this->assertSame('110-123-456789', $v->fresh()->purchase_seller_account, '복호화한 평문이 안 잘렸다');
        $this->assertNotSame(
            '110-123-456789',
            DB::table('vehicles')->where('id', $v->id)->value('purchase_seller_account'),
            'DB 에 평문으로 들어갔다 — 암호화가 깨졌다'
        );
    }

    /** 문자열이 아닌 값·null 은 그대로 통과해야 한다. */
    public function test_leaves_non_strings_alone(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '44가4444',
            'sale_price' => 1234567,
            'is_deregistered' => false,
            'purchase_from' => null,
            'sales_channel' => 'export',
            'dhl_request' => false,
        ]);

        $this->assertSame(1234567.0, (float) $v->sale_price);
        $this->assertFalse((bool) $v->is_deregistered);
        $this->assertNull($v->purchase_from);
    }

    /** 화면(차량 편집 패널) 저장 경로에서도 걸려야 한다. */
    public function test_trims_through_the_edit_panel(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $v = Vehicle::create(['vehicle_number' => '55가5555', 'sales_channel' => 'export', 'dhl_request' => false]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('vehicle_number', '  55가5555  ')
            ->set('brand', '  기아 ')
            ->call('save');

        $fresh = $v->fresh();
        $this->assertSame('55가5555', $fresh->vehicle_number);
        $this->assertSame('기아', $fresh->brand);
    }
}
