<?php

namespace Tests\Feature;

use App\Mail\VehicleDocumentMail;
use App\Models\Buyer;
use App\Models\Setting;
use App\Models\SignedContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use App\Services\Documents\SigningSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 판매계약서 전자서명 Phase 2~5 — 발급 → 서명 페이지 → 서명 확정(서명본·CoC·증거메일) → 재발급 revoke.
 * soffice(PdfConverter)는 CI 미설치라 페이크 바인딩(PDF 렌더 자체는 게이트 스크립트로 별도 실증).
 */
class SignedContractFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        Mail::fake();

        // 회사 메일 설정(ses) — 증거메일 발송 경로 활성화(isConfigured true).
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => 'ses', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => 'noreply@ssancar.test', 'type' => 'string']);

        // soffice 없이 — PdfConverter 를 더미 PDF 반환으로 대체(변환 실측은 게이트 스크립트에서).
        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $spreadsheet): string
            {
                return "%PDF-1.4\n% signed-contract-test\n";
            }
        });
    }

    private function vehicle(string $plate = 'SC-1', string $currency = 'USD'): Vehicle
    {
        $buyer = Buyer::firstOrCreate(['name' => 'TOKYO AUTO'], [
            'contact_email' => 'buy@tokyo.jp', 'is_active' => true,
        ]);

        return Vehicle::create([
            'vehicle_number' => $plate,
            'sales_channel' => 'export', 'currency' => $currency, 'exchange_rate' => 1300,
            'dhl_request' => false, 'purchase_date' => '2026-06-01',
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyer->id,
        ]);
    }

    private function pngDataUri(): string
    {
        $im = imagecreatetruecolor(200, 60);
        imagefilledrectangle($im, 0, 0, 199, 59, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 40, 190, 20, imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_issue_creates_pending_session_with_frozen_snapshot(): void
    {
        $v = $this->vehicle();
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $result = app(SigningSessionService::class)->issue(collect([$v]), null, $user->id);
        $c = $result['contract'];

        $this->assertSame(SignedContract::STATUS_PENDING, $c->status);
        $this->assertSame([$v->id], $c->vehicle_ids);
        $this->assertSame('buy@tokyo.jp', $c->recipient_email);            // 바이어 이메일 기본
        $this->assertNotEmpty($c->source_hash);
        Storage::disk(config('filesystems.vehicle_docs_disk'))->assertExists($c->snapshot_path);
        Storage::disk(config('filesystems.vehicle_docs_disk'))->assertExists($c->previewPdfPath());
        $this->assertStringContainsString('/sign/', $result['url']);
    }

    public function test_signing_page_marks_viewed_then_signs_and_emails(): void
    {
        $v = $this->vehicle();
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, $user->id)['contract'];

        // GET 서명 페이지 → viewed
        $showUrl = URL::temporarySignedRoute('sign.show', now()->addDays(7), ['token' => $c->sign_token]);
        $this->get($showUrl)->assertOk()->assertSee('Sales Contract');
        $this->assertSame(SignedContract::STATUS_VIEWED, $c->fresh()->status);

        // POST 서명 → signed + 서명본 저장 + 증거메일
        $submitUrl = URL::temporarySignedRoute('sign.submit', now()->addHours(2), ['token' => $c->sign_token]);
        $this->post($submitUrl, [
            'signature' => $this->pngDataUri(),
            'signer_name' => 'Taro Yamada',
            'recipient_email' => 'buy@tokyo.jp',
        ])->assertOk()->assertSee('Signed');

        $c->refresh();
        $this->assertSame(SignedContract::STATUS_SIGNED, $c->status);
        $this->assertSame('Taro Yamada', $c->signer_name);
        $this->assertNotEmpty($c->signed_hash);
        Storage::disk(config('filesystems.vehicle_docs_disk'))->assertExists($c->signed_pdf_path);
        $this->assertNotNull($c->signed_at);
        Mail::assertSent(VehicleDocumentMail::class);
    }

    public function test_already_signed_is_idempotent(): void
    {
        $v = $this->vehicle();
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, $user->id)['contract'];
        $c->update(['status' => SignedContract::STATUS_SIGNED, 'signed_at' => now()]);

        $submitUrl = URL::temporarySignedRoute('sign.submit', now()->addHours(2), ['token' => $c->sign_token]);
        $this->post($submitUrl, ['signature' => $this->pngDataUri(), 'recipient_email' => 'x@y.z'])
            ->assertOk()->assertSee('Signed');

        // 재제출은 no-op — 기존 서명 불변
        $this->assertSame(SignedContract::STATUS_SIGNED, $c->fresh()->status);
    }

    public function test_reissue_revokes_overlapping_active_session(): void
    {
        $v = $this->vehicle();
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $svc = app(SigningSessionService::class);

        $first = $svc->issue(collect([$v]), null, $user->id)['contract'];
        $second = $svc->issue(collect([$v]), null, $user->id)['contract'];

        $this->assertSame(SignedContract::STATUS_REVOKED, $first->fresh()->status);
        $this->assertSame(SignedContract::STATUS_PENDING, $second->fresh()->status);
    }

    public function test_revoked_link_shows_closed_page(): void
    {
        $v = $this->vehicle();
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, $user->id)['contract'];
        $c->update(['status' => SignedContract::STATUS_REVOKED, 'revoked_at' => now()]);

        $showUrl = URL::temporarySignedRoute('sign.show', now()->addDays(7), ['token' => $c->sign_token]);
        $this->get($showUrl)->assertOk()->assertSee('no longer available');
    }

    public function test_mixed_currency_rejected(): void
    {
        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $v1 = $this->vehicle('SC-1', 'USD');
        $v2 = $this->vehicle('SC-2', 'JPY');

        $this->expectException(ValidationException::class);
        app(SigningSessionService::class)->issue(collect([$v1, $v2]), null, $user->id);
    }
}
