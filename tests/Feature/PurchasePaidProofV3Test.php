<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📎 매입대금 입금완료 v3 — 📨 모달에서 이체확인증(붙여넣기·파일)을 붙이면 「이체확인증 보기」 버튼 (jin 2026-10-23).
 *
 * 지키는 것: ①안 붙이면 종전 v2(버튼 없음) ②붙이면 v3 + 만료 서명 링크 버튼 ③v3 승인 전이면 파일은 저장하고 v2
 * ④링크는 로그인 없이 그 지급의 파일만 연다 ⑤버튼 링크는 APP_URL 호스트(등록 URL 패턴과 같아야 K108 이 안 난다).
 */
class PurchasePaidProofV3Test extends TestCase
{
    use RefreshDatabase;

    private function configure(bool $v3): void
    {
        $set = Setting::companyTemplateSet();
        $vals = [
            "alimtalk_enabled_{$set}" => '1', "alimtalk_userid_{$set}" => 'heyman', "alimtalk_profile_{$set}" => 'PROFILE',
            "alimtalk_tmpl_erp_purchase_paid_{$set}" => 'TMPL_PAID', "alimtalk_tmpl_erp_purchase_paid_v2_{$set}" => 'TMPL_PAID_V2',
            "alimtalk_roles_erp_purchase_paid_v2_{$set}" => '',
        ];
        if ($v3) {
            $vals["alimtalk_tmpl_erp_purchase_paid_v3_{$set}"] = 'TMPL_PAID_V3';
        }
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'BIZM-PAID']]], 200)]);
    }

    private function vehicleWithDown(): array
    {
        $v = Vehicle::create(['vehicle_number' => '77거8899', 'sales_channel' => 'export', 'purchase_date' => '2026-10-01', 'purchase_price' => 20_000_000]);
        $pbp = $v->purchaseBalancePayments()->create(['type' => 'down', 'amount' => 1_000_000, 'payment_date' => '2026-10-02', 'confirmed_at' => now()]);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));

        return [$v->fresh(), $pbp];
    }

    private function modal(Vehicle $v)
    {
        return Volt::test('erp.vehicles.index')->set('editingId', $v->id)->set('has_mortgage', false)
            ->set('deregistrationBuyerPhone', '010-5555-6666')->call('openPaidNotice', 'down');
    }

    public function test_modal_opens_with_amount_and_target_row(): void
    {
        $this->configure(false);
        [$v, $pbp] = $this->vehicleWithDown();

        $this->modal($v)->assertSet('paidNotice.pbp_id', $pbp->id)->assertSet('paidNotice.amount', 1_000_000)
            ->assertSeeHtml('data-paid-notice-modal')->assertSeeHtml('data-paid-paste-zone');
    }

    public function test_send_without_image_goes_as_v2_without_button(): void
    {
        $this->configure(true);
        [$v] = $this->vehicleWithDown();

        $this->modal($v)->call('sendPaidNotice', false)->assertSet('paidNotice', null);

        $log = AlimtalkLog::latest('id')->firstOrFail();
        $this->assertSame('erp_purchase_paid_v2', $log->template_code);
        Http::assertSent(fn ($req) => ! str_contains($req->body(), 'button1'));
    }

    public function test_attached_screenshot_is_saved_and_sent_as_v3_with_a_signed_app_url_button(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        config(['app.url' => 'https://heysellcar.com']);
        $this->configure(true);
        [$v, $pbp] = $this->vehicleWithDown();

        $this->modal($v)->set('paidProofUpload', UploadedFile::fake()->image('image.png'))->call('sendPaidNotice', true);

        $path = $pbp->fresh()->proof_path;
        $this->assertNotNull($path);
        $this->assertStringContainsString("purchase-proofs/{$pbp->id}/screenshot-", $path, '붙여넣은 image.png 는 이름을 바꿔 저장');
        $this->assertSame('erp_purchase_paid_v3', AlimtalkLog::latest('id')->value('template_code'));
        Http::assertSent(function ($req) use ($pbp) {
            $b = json_decode($req->body(), true)[0]['button1'] ?? null;

            return $b !== null
                && $b['name'] === AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v3']['button'][0]['name']
                && str_starts_with($b['url_mobile'], 'https://heysellcar.com/a/proof/'.$pbp->id.'?')
                && str_contains($b['url_mobile'], 'signature=');
        });
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $pbp->id, 'column_name' => 'proof_path']);
    }

    public function test_before_v3_approval_the_file_is_kept_and_v2_goes_out(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $this->configure(false);
        [$v, $pbp] = $this->vehicleWithDown();

        $this->modal($v)->assertSeeHtml(__('vehicle.paidnotice.proof_v3_off'))
            ->set('paidProofUpload', UploadedFile::fake()->create('이체확인증.pdf', 50, 'application/pdf'))->call('sendPaidNotice', true);

        $this->assertNotNull($pbp->fresh()->proof_path);
        $this->assertSame('erp_purchase_paid_v2', AlimtalkLog::latest('id')->value('template_code'));
    }

    public function test_send_with_proof_requires_a_file(): void
    {
        $this->configure(true);
        [$v] = $this->vehicleWithDown();

        $this->modal($v)->call('sendPaidNotice', true)->assertSet('paidNotice.kind', 'down');
        $this->assertSame(0, AlimtalkLog::count(), '파일 없이 「함께 보내기」 는 보내지 않는다');
    }

    public function test_signed_link_opens_only_that_payments_file_without_login(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        [, $pbp] = $this->vehicleWithDown();
        Storage::disk(config('filesystems.vehicle_docs_disk'))->put('vehicles/1/purchase-proofs/1/a.png', 'PNGDATA');
        PurchaseBalancePayment::where('id', $pbp->id)->update(['proof_path' => 'vehicles/1/purchase-proofs/1/a.png']);
        auth()->logout();

        $url = URL::temporarySignedRoute('purchase.proof', now()->addDays(7), ['pbp' => $pbp->id]);
        $this->get($url)->assertOk()->assertSee('PNGDATA', false);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $pbp->id, 'action' => 'purchase_proof_viewed']);

        $this->get(route('purchase.proof', ['pbp' => $pbp->id]))->assertForbidden();   // 서명 없음
        // 다른 지급의 서명으로는 못 연다(서명이 지급 id 에 묶여 있다)
        $other = $pbp->vehicle->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 5, 'payment_date' => '2026-10-03', 'confirmed_at' => now(), 'proof_path' => 'vehicles/1/purchase-proofs/1/a.png']);
        $this->get(str_replace('/a/proof/'.$pbp->id.'?', '/a/proof/'.$other->id.'?', $url))->assertForbidden();
    }

    public function test_v3_body_is_v2_plus_one_button_line_and_shares_v2_recipients(): void
    {
        $v2 = explode("\n", AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v2']['body']);
        array_splice($v2, count($v2) - 1, 0, ['아래 [이체확인증 보기] 버튼으로 이체확인증을 7일간 볼 수 있습니다.']);
        $this->assertSame(implode("\n", $v2), AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v3']['body'], '등록본과 글자 단위로 같아야 한다 — 바꾸면 재승인');
        $this->assertSame('erp_purchase_paid_v2', AlimtalkTemplates::baseCode('erp_purchase_paid_v3'));
        $btn = AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v3']['button'][0];
        $this->assertSame('/a/proof/', $btn['register_path']);
        $this->assertLessThanOrEqual(14, mb_strlen($btn['name']));
        $this->assertStringStartsWith('/a/proof/', parse_url(route('purchase.proof', ['pbp' => 1]), PHP_URL_PATH), '라우트 접두와 등록 URL 이 같아야 한다');
    }
}
