<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * [관리] 시점 전체 라이프사이클 E2E — **차량 편집 패널 save() 경로** 구동.
 *
 * 2026-06-01 자동 PBP Draft phantom 수정 검증 + 매입~정산 회계 정합 확인.
 * 기존 VehicleLifecycleE2ETest 는 모델 직접 생성이라 패널 save() (수정 위치)를 안 거친다.
 * 본 테스트는 [관리]가 실제 화면처럼 openCreate/openEdit + set + save 로 전 단계를 진행:
 *   등록 → 매입가+매입 송금(확정) → 말소 → 판매가/바이어 → 바이어 입금(계약금+잔금) →
 *   컨사이니 → 선적 → 수출신고서 → 통관 → B/L → 거래완료 → 정산 자동 생성.
 *
 * 통화 KRW·환율 1·비용 0 으로 고정해 정산 금액을 결정적으로 단언.
 *
 * ⚠️ 게이트 순서 주의 (실제 동작 반영):
 *   - 선적(bl_loading_location)은 컨사이니가 DB 에 저장된 *다음* save 에서 입력한다
 *     (preview.consignee_id 는 replicate 기반이라 같은 save 의 신규 컨사이니를 못 본다).
 *   - (2026-06-01 Option B 이후 컨사이니를 판매 시점에 지정해도 통관 당사자가 전파되지 않아
 *     C5 가 조기 발동하지 않는다. 본 테스트는 단계별 cascade 확인을 위해 입금 후 순서를 유지.)
 */
class ManagerPanelLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Storage::fake(config('filesystems.vehicle_docs_disk'));
    }

    public function test_manager_drives_full_lifecycle_via_panel(): void
    {
        // [관리] + 본인 팀 영업 + 프리랜서 영업담당자 (정산 비율제 검증용)
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id, 'email_verified_at' => now()]);
        $salesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'E2E BUYER', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'E2E CONS', 'is_active' => true]);

        $this->actingAs($manager);

        $pdf = fn (string $name) => UploadedFile::fake()->create($name, 10, 'application/pdf');

        // ─── 1) 차량 등록 + 매입가 10,000,000 + 매입 송금(계약금 지급) 10,000,000 확정 ───
        //     핵심 [FIX]: Vehicle::saved 자동 Draft phantom 이 남지 않아야 한다.
        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'E2EMGR-1')
            ->set('currency', 'KRW')
            ->set('exchange_rate_str', '1')
            ->set('salesman_id_str', (string) $salesman->id)
            ->set('purchase_date', '2026-04-01')
            ->set('purchase_price_str', '10,000,000')
            ->set('cost_deregistration_str', '0')   // 정산 결정성 위해 default 비용 0
            ->set('cost_license_str', '0')
            ->set('cost_towing_str', '0')
            ->set('down_payment_str', '10,000,000')  // 매입 송금 (전액 확정)
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', 'E2EMGR-1')->firstOrFail();
        $rows = $v->purchaseBalancePayments()->get();

        $this->assertSame(0, $rows->whereNull('confirmed_at')->count(), '[FIX] 확정 송금 전액 커버 → 자동 Draft phantom 없어야 함');
        $this->assertSame(1, $rows->count(), '확정 계약금 1건만');
        $this->assertSame(10_000_000, (int) $rows->first()->amount);
        $this->assertSame(0, $v->fresh()->purchase_unpaid_amount, '매입 미지급 0');
        $this->assertSame('매입완료', $v->fresh()->progress_status);

        // ─── 2) 말소 처리(체크+서류+RRN) + 판매가/바이어 (컨사이니는 아직 X) ───
        //     sale_price>0 + 미입금 → 판매중. 컨사이니 미지정이라 export 전파 없음 → C5 미발동.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_deregistered', true)
            ->set('deregistrationDocFile', $pdf('dereg.pdf'))
            ->set('nice_reg_owner_rrn', '123456-1234567')  // 말소 H10 가드 — RRN 필수 (000000-0000000 형식)
            ->set('sale_date', '2026-05-01')
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('sale_price_str', '10,000,000')
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertTrue((bool) $v->is_deregistered);
        $this->assertSame('판매중', $v->progress_status);
        $this->assertSame(0, $v->purchaseBalancePayments()->whereNull('confirmed_at')->count(), '재저장 후에도 phantom 없음');

        // ─── 3) 바이어 입금 — 계약금 입금 4,000,000 + 판매 잔금 6,000,000 = 100% 완납 ───
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('deposit_down_payment_str', '4,000,000')   // 콤마 포함 — 4항목 파서 콤마 제거 검증
            ->set('finalPayments', [[
                'id' => null,
                'amount' => '6000000',
                'payment_date' => '2026-05-10',
                'exchange_rate' => '1',
                'note' => '바이어 잔금',
                'locked' => false,
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount, '판매 미입금 0 (완납)');
        $this->assertSame('판매완료', $v->progress_status);

        // ─── 4) 컨사이니 지정 (100% 완납 후 → export 전파돼도 C5 통과) ───
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('consignee_id_str', (string) $consignee->id)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame((int) $consignee->id, (int) $v->fresh()->consignee_id);

        // ─── 5) 선적(반입지) → 6) 수출신고서 → 7) 통관 → 8) B/L → 거래완료 ───
        //     100% 완납이므로 C5(50%)·G1(100%) 게이트 모두 통과.
        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('bl_loading_location', 'PUSAN')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('선적중', $v->fresh()->progress_status);

        // 수출신고서 업로드 → 선적완료. is_export_cleared 미체크 상태라 doc-check 경고 모달이 뜬다
        // (수출통관 체크↔서류 XOR). v4 에선 수출신고서만 있어도 선적완료가 정상 → 사용자가 [확인] 진행.
        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('exportDeclarationDocFile', $pdf('export-decl.pdf'))
            ->call('save')
            ->assertSet('showDocCheckModal', true);
        $c->call('confirmSaveWithDocMismatch')->assertHasNoErrors();
        $this->assertSame('선적완료', $v->fresh()->progress_status);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('is_export_cleared', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('통관중', $v->fresh()->progress_status);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('blDocFile', $pdf('bl.pdf'))
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('거래완료', $v->fresh()->progress_status);

        // ─── 9) 정산 자동 생성 + 회계 정합 (KRW·환율1·비용0 기준 결정적 단언) ───
        //     판매금원화=10,000,000 / 판매마진=0 / 부가세마진=900,000
        //     총마진=(0+900,000)×0.9=810,000 / 정산액(50%)=405,000 / 실지급=355,000(프리랜서 서류비 5만)
        $s = Settlement::where('vehicle_id', $v->id)->first();
        $this->assertNotNull($s, '거래완료 진입 시 정산 자동 생성');
        $this->assertSame('pending', $s->settlement_status);
        $this->assertSame('ratio', $s->settlement_type, '프리랜서 → 비율제');
        $this->assertSame(50, (int) $s->settlement_ratio);

        $this->assertSame(10_000_000, $s->sales_amount_krw, '판매금원화');
        $this->assertSame(0, $s->sales_margin, '판매마진');
        $this->assertSame(900_000, $s->vat_margin, '부가세마진 = 매입가×0.09');
        $this->assertSame(810_000, $s->total_margin, '총마진 = (판매마진+부가세마진)×0.9');
        $this->assertSame(405_000, $s->settlement_amount, '정산액 = 총마진×50%');
        $this->assertSame(355_000, $s->actual_payout, '실지급 = 정산액 - 서류비 50,000');

        // 최종: 전 생애주기 통틀어 phantom 자동 Draft 가 한 번도 잔존하지 않았다.
        $this->assertSame(0, $v->purchaseBalancePayments()->whereNull('confirmed_at')->count());
    }
}
