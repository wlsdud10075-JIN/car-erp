<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 세금계산서 발행 요청 알림톡 (jin 2026-09-11).
 *
 * 🚫 **알림톡은 파일 첨부가 안 된다** — 사업자등록증은 만료 서명 링크로 보낸다(말소등록증과 같은 길).
 *
 * 지키는 것:
 *   ① 승인 전에는 버튼이 안 뜬다 (tmplId 가 채워지면 자동으로 켜진다)
 *   ② 회사 정보(등록증·상호·사업자번호)가 하나라도 비면 **안 보낸다** — 빈 값이 나가면
 *      딜러가 어느 회사의 요청인지 모른다
 *   ③ 링크는 로그인 없이 열리되 **서명·만료**가 인가다. 서명이 없으면 403
 *   ④ 영업은 못 보낸다
 */
class TaxInvoiceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function alimtalkReady(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'pf', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_tmpl_erp_tax_invoice_request_{$set}"], ['value' => 'TMPL123', 'type' => 'string']);
    }

    private function companyReady(): void
    {
        $set = Setting::companyTemplateSet();
        Storage::fake('local');
        Setting::updateOrCreate(['key' => "biz_cert_{$set}"], ['value' => 'biz-certs/'.$set.'/business-registration.pdf', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "biz_cert_name_{$set}"], ['value' => '주식회사 싼카', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "biz_cert_number_{$set}"], ['value' => '662-81-00898', 'type' => 'string']);
    }

    private function financeUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '12가3456',
            'sales_channel' => 'export',
            'deregistration_notice_phone' => '010-1234-5678',
        ]);
    }

    // ── ① 등록·게이트 ──────────────────────────────────────────────────

    public function test_the_template_is_registered_in_the_recipient_table(): void
    {
        // 🚨 셋 중 하나에 없으면 **영영 안 나가고 설정으로 켤 방법도 없다**(SKILLS §8 #62).
        $this->assertArrayHasKey('erp_tax_invoice_request', AlimtalkTemplates::TEMPLATES);
        $this->assertArrayHasKey('erp_tax_invoice_request', AlimtalkRecipients::TARGETED_LABELS);
    }

    public function test_the_body_uses_escaped_newlines_not_real_ones(): void
    {
        // 🚨 SKILLS §8 #77 — pint 의 single_quote 가 "…\n…" 을 실제 개행으로 펼치면 CRLF 가 섞이고,
        //    BizM 승인본과 글자단위로 어긋나 발송이 통째로 반려된다. 눈으로는 구분이 안 된다.
        $body = AlimtalkTemplates::TEMPLATES['erp_tax_invoice_request']['body'];

        $this->assertSame(0, substr_count($body, "\r\n"), '본문에 CR 이 섞였다 — 큰따옴표 + \\n 으로 쓸 것');
        $this->assertGreaterThan(0, substr_count($body, "\n"));
    }

    public function test_every_declared_variable_appears_in_the_body(): void
    {
        $t = AlimtalkTemplates::TEMPLATES['erp_tax_invoice_request'];

        foreach ($t['vars'] as $var) {
            $this->assertStringContainsString('#{'.$var.'}', $t['body'], "선언한 {$var} 가 본문에 없다");
        }
    }

    public function test_the_button_is_hidden_until_the_template_is_approved(): void
    {
        $this->actingAs($this->financeUser());

        // tmplId 가 없는 상태 = BizM 승인 전. 「눌러도 안 나가는 버튼」을 만들지 않는다.
        $c = Volt::test('erp.vehicles.index');
        $this->assertFalse($c->get('taxInvoiceNoticeEnabled'));

        $this->alimtalkReady();
        $this->assertTrue(Volt::test('erp.vehicles.index')->get('taxInvoiceNoticeEnabled'));
    }

    // ── ② 회사 정보가 비면 안 보낸다 ────────────────────────────────────

    public function test_it_refuses_to_send_when_the_company_info_is_incomplete(): void
    {
        $this->alimtalkReady();
        $this->companyReady();
        $this->actingAs($this->financeUser());
        $v = $this->vehicle();

        // 셋 중 하나씩 비워 보면 전부 막혀야 한다 — 빈 값이 나가면 딜러가 어느 회사인지 모른다.
        foreach (['biz_cert_', 'biz_cert_name_', 'biz_cert_number_'] as $key) {
            $this->companyReady();
            Setting::where('key', $key.Setting::companyTemplateSet())->delete();

            Volt::test('erp.vehicles.index')
                ->call('openEdit', $v->id)
                ->call('sendTaxInvoiceAlimtalk')
                ->assertDispatched('notify', type: 'error');

            // ⚠️ assertDatabaseCount 의 3번째 인자는 연결명이다 — 메시지를 넣으면 엉뚱한 에러가 난다.
            $this->assertSame(0, AlimtalkLog::count(), "{$key} 가 비었는데 발송 기록이 생겼다");
        }
    }

    public function test_it_refuses_to_send_without_a_phone_number(): void
    {
        $this->alimtalkReady();
        $this->companyReady();
        $this->actingAs($this->financeUser());
        $v = Vehicle::create(['vehicle_number' => '99가9999', 'sales_channel' => 'export', 'deregistration_notice_phone' => null]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('sendTaxInvoiceAlimtalk')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame(0, AlimtalkLog::count());
    }

    // ── ③ 링크 — 서명이 인가다 ─────────────────────────────────────────

    public function test_the_link_is_rejected_without_a_signature(): void
    {
        $v = $this->vehicle();

        // 로그인 없이 열리는 라우트라 서명이 유일한 인가다. 서명 없으면 403.
        $this->get(route('buyer.business-registration', ['vehicle' => $v->id]))->assertForbidden();
    }

    public function test_a_signed_link_serves_the_certificate(): void
    {
        $set = Setting::companyTemplateSet();
        $disk = Storage::fake(config('filesystems.vehicle_docs_disk'));
        $disk->put('biz-certs/'.$set.'/business-registration.pdf', '%PDF-1.4 fake');
        Setting::updateOrCreate(['key' => "biz_cert_{$set}"], ['value' => 'biz-certs/'.$set.'/business-registration.pdf', 'type' => 'string']);
        $v = $this->vehicle();

        $url = URL::temporarySignedRoute('buyer.business-registration', now()->addDays(7), ['vehicle' => $v->id]);

        $this->get($url)->assertOk();
    }

    public function test_a_signed_link_404s_when_nothing_is_registered(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $v = $this->vehicle();

        // 등록증이 없으면 링크가 살아 있어도 404 — 빈 파일을 내려주지 않는다.
        $url = URL::temporarySignedRoute('buyer.business-registration', now()->addDays(7), ['vehicle' => $v->id]);

        $this->get($url)->assertNotFound();
    }

    // ── ④ 권한 ─────────────────────────────────────────────────────────

    public function test_sales_cannot_send(): void
    {
        $this->alimtalkReady();
        $this->companyReady();
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        Volt::test('erp.vehicles.index')->call('sendTaxInvoiceAlimtalk')->assertStatus(403);
    }

    public function test_clearance_and_finance_can_send(): void
    {
        foreach (['재무', '수출통관', '관리'] as $role) {
            $u = User::factory()->create(['permission' => 'user', 'role' => $role, 'email_verified_at' => now()]);

            // jin 2026-09-11 — 재무·수출통관·관리·업무관리자·최고관리자·시스템관리자.
            $this->assertTrue(
                $u->canAccessClearance() || $u->canAccessSettlement(),
                "{$role} 이 보낼 수 있어야 한다",
            );
        }
    }

    // ── 업로드 실패 안전 (도장과 같은 순서) ──────────────────────────────

    public function test_the_upload_keeps_the_old_file_when_saving_fails(): void
    {
        // 🚨 정적 검사 — Storage::fake 는 로컬 드라이버라 쓰기가 늘 성공해서 기능 테스트로는
        //    원리상 못 잡는다(SKILLS §8 #47). 저장 성공을 확인한 **뒤에만** 옛 파일을 지우는지 본다.
        $src = file_get_contents(base_path('resources/views/livewire/admin/settings.blade.php'));
        $pos = strpos($src, 'private function storeBizCert(');
        $this->assertNotFalse($pos);
        $block = substr($src, $pos, 1400);

        $existsAt = strpos($block, '->exists($path)');
        $deleteAt = strpos($block, '$disk->delete($old)');

        $this->assertNotFalse($existsAt, 'storeAs 결과를 exists() 로 확인하지 않는다');
        $this->assertNotFalse($deleteAt, '옛 파일 삭제가 사라졌다');
        $this->assertLessThan($deleteAt, $existsAt, '저장 확인보다 옛 파일 삭제가 먼저다 — 실패하면 등록증이 0장이 된다');
    }
}
