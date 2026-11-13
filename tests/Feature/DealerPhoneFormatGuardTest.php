<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PhoneFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📵 딜러 알림톡 번호가 휴대폰 형식이 아니면 발송을 막는다 (jin 2026-11-13).
 *
 * 실사고 — ssancarerp 07더9896: 「010-960-2299」(한 자리 빠짐)로 입금완료 알림톡이 두 번 나갔고,
 * BizM 은 접수(sent)한 뒤 나중에야 미도달로 돌려줬다. 보내는 사람은 성공 토스트만 본다.
 * 🚫 저장은 막지 않는다 — 유선번호를 적어 둔 차(ssancarerp 12대)의 다른 수정이 막힌다.
 */
class DealerPhoneFormatGuardTest extends TestCase
{
    use RefreshDatabase;

    private function ready(): Vehicle
    {
        $set = Setting::companyTemplateSet();
        foreach ([
            "alimtalk_enabled_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'heyman',
            "alimtalk_profile_{$set}" => 'PROFILE',
            "alimtalk_tmpl_erp_purchase_paid_{$set}" => 'TMPL_PAID',
        ] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'BIZM-PAID']]], 200)]);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));

        $v = Vehicle::create([
            'vehicle_number' => '07더9896', 'sales_channel' => 'export', 'purchase_date' => '2026-11-09', 'purchase_price' => 20_000_000,
            'purchase_seller_bank' => '국민', 'purchase_seller_account' => '123456-78-901234', 'purchase_seller_holder' => '김딜러',
        ]);
        $v->purchaseBalancePayments()->create(['type' => 'down', 'amount' => 1_000_000, 'payment_date' => '2026-11-10', 'confirmed_at' => now()]);

        return $v;
    }

    public function test_is_mobile_accepts_only_reachable_mobile_numbers(): void
    {
        foreach (['010-9602-2990', '01096022990', '011-234-5678', '016-1234-5678'] as $ok) {
            $this->assertTrue(PhoneFormat::isMobile($ok), $ok);
        }
        foreach (['010-960-2299', '0109602299', '031-378-2961', '02-899-8715', '102-803-7668', '', null] as $bad) {
            $this->assertFalse(PhoneFormat::isMobile($bad), (string) $bad);
        }
    }

    public function test_a_number_missing_a_digit_is_not_sent(): void
    {
        $v = $this->ready();

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', '010-960-2299')
            ->call('sendPurchasePaidAlimtalk', 'down')
            ->assertHasErrors('deregistrationBuyerPhone');

        $this->assertSame(0, AlimtalkLog::count(), '틀린 번호로는 한 통도 나가면 안 된다');
        Http::assertNothingSent();
    }

    public function test_a_proper_mobile_number_still_goes_out(): void
    {
        $v = $this->ready();

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', '010-9602-2990')
            ->call('sendPurchasePaidAlimtalk', 'down')
            ->assertHasNoErrors('deregistrationBuyerPhone');

        $this->assertSame('01096022990', AlimtalkLog::latest('id')->first()?->phone);
    }

    public function test_leaving_the_field_with_a_bad_number_warns_right_away(): void
    {
        $v = $this->ready();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)   // 패널이 열려야 매입 탭의 안내 문구가 그려진다
            ->set('deregistrationBuyerPhone', '0109602299')
            ->assertHasErrors('deregistrationBuyerPhone')
            ->assertSee('휴대폰 번호 형식이 아닙니다')
            ->set('deregistrationBuyerPhone', '01096022990')
            ->assertHasNoErrors('deregistrationBuyerPhone');
    }

    public function test_alimtalk_log_search_finds_a_number_typed_with_hyphens(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        AlimtalkLog::create(['template_code' => 'erp_purchase_paid_v3', 'phone' => '0109602299', 'status' => 'sent']);
        AlimtalkLog::create(['template_code' => 'erp_purchase_paid_v3', 'phone' => '01077778888', 'status' => 'sent']);

        Volt::test('admin.alimtalk-logs.index')
            ->set('search', '010-960-2299')
            ->assertSee('0109602299')
            ->assertDontSee('01077778888');
    }
}
