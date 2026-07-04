<?php

namespace Tests\Feature;

use App\Mail\VehicleDocumentMail;
use App\Models\MailDeliveryLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
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
            ->set('mailDocIds', [$p1->id, $pOther->id])   // 남의 차량 문서 섞어도 무시돼야
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
