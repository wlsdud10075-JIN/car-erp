<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\SignedContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use App\Services\Documents\SigningSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 전자서명 요청 「무르기」 (jin 2026-07-31).
 *
 * 종전엔 재발급할 때만 옛 세션이 자동으로 무릎어, 잘못 눌렀을 때 화면에서 되돌릴 방법이 없었다.
 * 실제로 발급 3분 뒤 서버 tinker 로 손수 무른 건이 있었다 — 그걸 화면에서 하게 만든 기능.
 */
class SigningSessionRevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        App::setLocale('ko');
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $spreadsheet): string
            {
                return "%PDF-1.4\n% test\n";
            }
        });
    }

    private function vehicle(string $no = 'R1'): Vehicle
    {
        $buyer = Buyer::firstOrCreate(['name' => 'TOKYO'], ['contact_email' => 'b@t.jp', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyer->id, 'purchase_date' => '2026-06-01',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    public function test_revoking_kills_the_link_and_leaves_a_trace(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle();
        $svc = app(SigningSessionService::class);
        $c = $svc->issue(collect([$v]), null, auth()->id())['contract'];

        $this->assertTrue($c->isSignable(), '발급 직후에는 서명 가능해야 한다');

        $svc->revoke($c);

        $c->refresh();
        $this->assertSame(SignedContract::STATUS_REVOKED, $c->status);
        $this->assertNotNull($c->revoked_at);
        $this->assertFalse($c->isSignable(), '무른 세션은 링크가 죽어야 한다');
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => SignedContract::class,
            'auditable_id' => $c->id,
            'action' => 'signing_session_revoked',
        ]);
    }

    /** 🚨 서명완료는 법적 증거물 — 어떤 경로로도 무를 수 없어야 한다. */
    public function test_signed_contract_can_never_be_revoked(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle();
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, auth()->id())['contract'];
        $c->forceFill(['status' => SignedContract::STATUS_SIGNED])->save();

        $this->expectException(\DomainException::class);
        app(SigningSessionService::class)->revoke($c->fresh());
    }

    public function test_already_revoked_session_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle();
        $svc = app(SigningSessionService::class);
        $c = $svc->issue(collect([$v]), null, auth()->id())['contract'];
        $svc->revoke($c);

        $this->expectException(\DomainException::class);
        $svc->revoke($c->fresh());
    }

    public function test_doc_tab_shows_revoke_button_and_revokes(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicle();
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, auth()->id())['contract'];

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('무르기')
            ->call('revokeSignature', $c->id);

        $this->assertSame(SignedContract::STATUS_REVOKED, $c->fresh()->status);
    }

    /** 스코프 밖 차량의 세션을 id 주입으로 무르려는 시도는 막혀야 한다 (§8 #26). */
    public function test_out_of_scope_user_cannot_revoke(): void
    {
        $owner = $this->admin();
        $this->actingAs($owner);
        $v = $this->vehicle();
        $c = app(SigningSessionService::class)->issue(collect([$v]), null, $owner->id)['contract'];

        // 영업 = 본인 담당 차량만. 다른 담당자의 차량으로 만들어 스코프 밖으로 둔다.
        //   ⚠️ 담당자 미지정 차량은 salesman_id(null) === 본인 salesman(null) 이라 통과해버린다 —
        //      스코프 테스트는 반드시 담당자를 붙여서 해야 한다.
        $other = Salesman::create(['name' => '다른영업', 'is_active' => true]);
        $v->update(['salesman_id' => $other->id]);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        Volt::test('erp.vehicles.index')->call('revokeSignature', $c->id);

        $this->assertSame(SignedContract::STATUS_PENDING, $c->fresh()->status, '스코프 밖이면 무르지 못해야 한다');
        AuditLog::query()->delete();
    }
}
