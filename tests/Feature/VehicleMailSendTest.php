<?php

namespace Tests\Feature;

use App\Mail\VehicleDocumentMail;
use App\Models\MailDeliveryLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\CompanyMailConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 서류 탭 「메일 발송」 — 회사 방식(SES 로 테스트)대로 발송 + 로그 + IDOR(자기 차량 문서만 첨부).
 */
class VehicleMailSendTest extends TestCase
{
    use RefreshDatabase;

    private function configureSes(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => 'ses', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => 'sales@heyman.com', 'type' => 'string']);
    }

    private function super(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    public function test_sends_and_logs_with_own_documents_only(): void
    {
        Mail::fake();
        $this->configureSes();
        $this->actingAs($this->super());

        $vehicle = Vehicle::create(['vehicle_number' => '99마0001', 'sales_channel' => 'export']);
        $p1 = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => 'vehicles/1/bl.pdf', 'sort_order' => 1]);

        $other = Vehicle::create(['vehicle_number' => '99마0002', 'sales_channel' => 'export']);
        $pOther = VehiclePhoto::create(['vehicle_id' => $other->id, 'path' => 'vehicles/2/secret.pdf', 'sort_order' => 1]);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->set('mailTo', 'buyer@example.com')
            ->set('mailSubject', 'HEYMAN - 99마0001')
            ->set('mailBody', 'Please find attached.')
            ->set('mailDocIds', ['photo:'.$p1->id, 'photo:'.$pOther->id])   // 남의 차량 문서 섞어도 무시돼야
            ->call('sendVehicleMail')
            ->assertHasNoErrors();

        Mail::assertSent(VehicleDocumentMail::class, fn ($m) => $m->hasTo('buyer@example.com'));

        $log = MailDeliveryLog::first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('ses', $log->channel);
        $this->assertSame((int) $vehicle->id, (int) $log->vehicle_id);
        // IDOR: 남의 차량 문서(secret.pdf)는 제외 — 자기 차량 1건만
        $this->assertSame(['bl.pdf'], $log->document_names);
    }

    public function test_attaches_generated_document(): void
    {
        Mail::fake();
        $this->configureSes();
        $this->actingAs($this->super());

        $vehicle = Vehicle::create(['vehicle_number' => '99마0009', 'sales_channel' => 'export']);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->set('mailTo', 'buyer@example.com')
            ->set('mailDocIds', ['gen:deregistration_set'])   // 자동생성 서류(전 채널, item 8 병합본)
            ->call('sendVehicleMail')
            ->assertHasNoErrors();

        Mail::assertSent(VehicleDocumentMail::class, fn ($m) => count($m->dataFiles) === 1 && $m->storedFiles === []);

        $log = MailDeliveryLog::first();
        $this->assertSame('sent', $log->status);
        $this->assertCount(1, $log->document_names);
    }

    public function test_open_modal_prefills_body_with_company_name(): void
    {
        $this->configureSes();
        $this->actingAs($this->super());
        $vehicle = Vehicle::create(['vehicle_number' => '99마0011', 'sales_channel' => 'export']);

        $company = CompanyMailConfig::active()->companyLabel();
        $expected = __('vehicle.mail.body_default', ['company' => $company]);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->call('openMailModal')
            ->assertSet('showMailModal', true)
            ->assertSet('mailBody', $expected);

        $this->assertStringContainsString($company, $expected);
    }

    public function test_mail_modal_splits_basic_and_shipping_photos(): void
    {
        // 메일 첨부 후보 = 기본정보(mailDocsUpload) / 선적(mailDocsShip) 그룹 분리 (jin 2026-07-06).
        $this->configureSes();
        $this->actingAs($this->super());
        $vehicle = Vehicle::create(['vehicle_number' => '99마0022', 'sales_channel' => 'export']);
        $basic = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => 'vehicles/1/car.jpg', 'sort_order' => 1]);
        $ship = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => 'vehicles/1/ship-photos/v.jpg', 'category' => 'shipping', 'sort_order' => 1]);

        $c = Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->call('openMailModal');

        $this->assertSame(['photo:'.$basic->id], collect($c->get('mailDocsUpload'))->pluck('key')->all(), '기본정보 그룹엔 차량사진만');
        $this->assertSame(['photo:'.$ship->id], collect($c->get('mailDocsShip'))->pluck('key')->all(), '선적 그룹엔 선박사진만');
    }

    public function test_invalid_recipient_blocks_send(): void
    {
        Mail::fake();
        $this->configureSes();
        $this->actingAs($this->super());
        $vehicle = Vehicle::create(['vehicle_number' => '99마0003', 'sales_channel' => 'export']);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->set('mailTo', 'not-email')
            ->call('sendVehicleMail')
            ->assertHasErrors('mailTo');

        Mail::assertNothingSent();
        $this->assertSame(0, MailDeliveryLog::count());
    }

    public function test_unconfigured_sender_blocks_send(): void
    {
        Mail::fake();
        $this->actingAs($this->super());   // 설정 없음
        $vehicle = Vehicle::create(['vehicle_number' => '99마0004', 'sales_channel' => 'export']);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $vehicle->id)
            ->set('mailTo', 'buyer@example.com')
            ->call('sendVehicleMail');

        Mail::assertNothingSent();
        $this->assertSame(0, MailDeliveryLog::count());
    }
}
