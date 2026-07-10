<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 국내 바이어 말소등록증 전달 — 만료 서명 링크 라우트 + 매입탭 수동 발송 핸들러.
 */
class AlimtalkBuyerDeregistrationTest extends TestCase
{
    use RefreshDatabase;

    private function disk(): string
    {
        return config('filesystems.vehicle_docs_disk');
    }

    private function configureAlimtalk(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'heyman', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'PROFILE', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_deregistration_notice_{$set}"], ['value' => 'TMPL_DEREG', 'type' => 'string']);
    }

    private function vehicleWithDoc(string $number = '99마1234'): Vehicle
    {
        Storage::fake($this->disk());
        Storage::disk($this->disk())->put('deregs/'.$number.'.pdf', '%PDF-1.4 fake');

        return Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'heyman',
            'deregistration_document' => 'deregs/'.$number.'.pdf',
        ]);
    }

    public function test_signed_link_streams_the_document(): void
    {
        $v = $this->vehicleWithDoc();
        $url = URL::temporarySignedRoute('buyer.deregistration', now()->addDays(3), ['vehicle' => $v->id]);

        $res = $this->get($url);

        $res->assertOk();
        $this->assertStringContainsString('inline', $res->headers->get('content-disposition'));
    }

    public function test_unsigned_link_is_forbidden(): void
    {
        $v = $this->vehicleWithDoc();

        // 서명 없는 접근 → 403 (signed 미들웨어). 로그인 없어도 서명이 인가.
        $this->get(route('buyer.deregistration', ['vehicle' => $v->id]))->assertForbidden();
    }

    public function test_signed_link_404_when_no_document(): void
    {
        $v = Vehicle::create(['vehicle_number' => '99마7777', 'sales_channel' => 'heyman']);
        $url = URL::temporarySignedRoute('buyer.deregistration', now()->addDays(3), ['vehicle' => $v->id]);

        $this->get($url)->assertNotFound();
    }

    public function test_manual_send_builds_signed_link_and_logs(): void
    {
        $this->configureAlimtalk();
        Http::fake(['*' => Http::response([['msgid' => 'BIZM-DEREG']], 200)]);
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $buyer = Buyer::create(['name' => '국내바이어', 'contact_phone' => '010-5555-6666', 'is_active' => true]);
        $v = $this->vehicleWithDoc('88가4321');
        $v->update(['buyer_id' => $buyer->id]);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', '010-5555-6666')
            ->call('sendDeregistrationAlimtalk');

        $log = AlimtalkLog::where('template_code', 'erp_deregistration_notice')->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame((int) $v->id, (int) $log->vehicle_id);
        $this->assertSame('01055556666', $log->phone);
        $this->assertStringContainsString('/d/deregistration/'.$v->id, (string) $log->message);
        $this->assertStringContainsString('signature=', (string) $log->message);
    }

    public function test_manual_send_requires_document(): void
    {
        $this->configureAlimtalk();
        Http::fake();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $v = Vehicle::create(['vehicle_number' => '88가0000', 'sales_channel' => 'heyman']);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', '010-1111-2222')
            ->call('sendDeregistrationAlimtalk');

        Http::assertNothingSent();
        $this->assertSame(0, AlimtalkLog::count());
    }

    // jin 2026-07-10 — 딜러 번호는 사용자가 입력·저장하면 유지된다(바이어 번호 프리필 아님).
    public function test_notice_phone_is_saved_and_reloaded_not_prefilled_from_buyer(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        // 판매 바이어는 다른 번호 — 프리필되면 안 됨.
        $buyer = Buyer::create(['name' => '수출바이어', 'contact_phone' => '010-0000-0000', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '88가1212', 'sales_channel' => 'export',
            'purchase_date' => '2026-06-01', 'dhl_request' => false, 'buyer_id' => $buyer->id,
        ]);

        // 사용자가 딜러 번호 입력 후 저장 → 컬럼에 유지
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('deregistrationBuyerPhone', '010-9999-8888')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('010-9999-8888', $v->fresh()->deregistration_notice_phone);

        // 재편집 시 저장값 로드 (바이어 010-0000-0000 이 아니라 저장한 010-9999-8888)
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('deregistrationBuyerPhone', '010-9999-8888');
    }
}
