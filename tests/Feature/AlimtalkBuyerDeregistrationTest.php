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
}
