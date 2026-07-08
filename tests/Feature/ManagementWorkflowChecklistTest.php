<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * [관리] 시선 워크플로우 체크리스트 실증 테스트.
 *
 * 대응 문서: docs/verification/2026-05-26-관리시선-워크플로우-체크리스트.md
 * (각 test 메서드 = 체크리스트 1행. fail 메시지·해결법이 1:1 대응)
 *
 * ── 구조: 관리 1 : 영업 5 (users.manager_user_id self-FK) ──
 *
 * ── 검증 4축 ──
 *   ① 정상 흐름 통과 — 영업 담당 차량 1대를 매입→…→거래완료까지 락을 순서대로 통과
 *   ② 각 락 fail      — 선행조건 없이 진입 → ValidationException + 메시지 확인
 *   ③ fail 후 해결·재통과 — 선행조건 충족 후 통과 (체크리스트 '해결법' 실증)
 *   ④ 관리 scoping    — 관리 1명이 본인 영업 5명 차량만 조회 (타 팀 비노출)
 *
 * ── 락 발동 위치 (체크리스트 §발동위치 컬럼과 대응) ──
 *   - saving 훅 자동:  G2 / G1 / Ledger / 삭제가드   (auth 있을 때만, raw 시드/artisan 우회)
 *   - UI save() 명시:  C4 / 컨사이니 / C5 / H3 / H1   (raw Vehicle::create/update 우회)
 *
 *   ⚠️ raw create/update 는 saving 훅 락만 발동 → C4/C5/컨사이니/H3/H1 은
 *      guardStageOrderForExport() / guardAttachmentDeps() 직접 호출로 검증 (UI save() 모사).
 *      ConsigneeGateTest 와 동일 컨벤션.
 *
 *   ⚠️ 행위자(actor) 차이:
 *      - G2 는 canApprove()(admin/super/관리)가 우회 + 신규 차량만 검사 → 영업 actor 로만 발동.
 *      - G1/Ledger 는 admin 도 발동 (admin 이 잠금 해제·우회 승인 주체).
 *
 *   ⚠️ G1 grandfather·null-ratio 분기, H10(말소→RRN 폼검증) 은 각각 G1BlLockTest /
 *      vehicles/index 폼 검증에 별도 박제됨 — 체크리스트 문서 §부록 참조.
 */
class ManagementWorkflowChecklistTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private User $manager;

    /** @var array<int, array{user: User, salesman: Salesman}> */
    private array $sales = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');

        // 관리 1 : 영업 5 구성
        $this->manager = User::factory()->create([
            'name' => '관리자-알파',
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $u = User::factory()->create([
                'name' => "영업{$i}",
                'permission' => 'user',
                'role' => '영업',
                'type' => 'employee',
                'manager_user_id' => $this->manager->id,
                'email_verified_at' => now(),
            ]);
            $s = Salesman::create([
                'user_id' => $u->id,
                'name' => $u->name,
                'is_active' => true,
                'type' => 'employee',
            ]);
            $this->sales[] = ['user' => $u, 'salesman' => $s];
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    private function buyer(string $name): Buyer
    {
        return Buyer::create(['name' => $name, 'is_active' => true]);
    }

    private function consignee(Buyer $buyer, string $name): Consignee
    {
        return Consignee::create(['buyer_id' => $buyer->id, 'name' => $name, 'is_active' => true]);
    }

    private function vnum(string $prefix): string
    {
        return $prefix.'-'.(++$this->counter);
    }

    /** export 기본 차량 (매입중). raw create — saving 훅만 발동, UI 가드 우회. */
    private function baseVehicle(int $salesmanId, array $overrides = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => $this->vnum('MW'),
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'salesman_id' => $salesmanId,
            'purchase_date' => '2026-04-01',
            'purchase_price' => 1_000_000,
        ], $overrides));
    }

    // ══════════════════════════════════════════════════════════════
    // 축 ① 정상 흐름 — 매입→…→거래완료, 락 8종 순서대로 통과
    // ══════════════════════════════════════════════════════════════

    public function test_axis1_happy_path_full_workflow_passes_every_lock(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $sm = $this->sales[0]['salesman'];
        $buyer = $this->buyer('HAPPY BUYER');
        $cons = $this->consignee($buyer, 'HAPPY CONSIGNEE');

        // 1) 매입중
        $v = $this->baseVehicle($sm->id, ['buyer_id' => $buyer->id]);
        $this->assertSame('매입중', $v->fresh()->progress_status, '매입가 입력 → 매입중');

        // 2) 매입완료 — 매입 잔금 confirmed
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('매입완료', $v->fresh()->progress_status, '매입 미지급 0 → 매입완료');

        // 3) 말소완료 — C4 선행조건 충족
        $v = $v->fresh();
        $v->update(['is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf']);
        $v->refreshCaches();
        $this->assertSame('말소완료', $v->fresh()->progress_status);

        // 4) 판매중 — 판매 컨사이니 지정(선적 가드 선행조건)
        $v = $v->fresh();
        $v->update(['sale_price' => 2_000_000, 'sale_date' => '2026-05-01', 'consignee_id' => $cons->id]);
        $v->refreshCaches();
        $this->assertSame('판매중', $v->fresh()->progress_status);

        // 5) 판매완료 — 판매 잔금 100% confirmed (G1 100% / C5 50% 룰 통과 준비)
        $v->finalPayments()->create([
            'amount' => 2_000_000, 'exchange_rate' => 1, 'payment_date' => '2026-05-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('판매완료', $v->fresh()->progress_status);

        // 6) 선적중 — C4·컨사이니·C5 가드 통과 (UI save() 모사)
        $v = $v->fresh();
        $v->bl_loading_location = 'PUSAN PORT';
        $v->guardStageOrderForExport();   // 예외 없음: 말소완료 + 컨사이니 + 입금100%
        $v->save();                       // saving 훅(G1/G2/Ledger) 통과
        $v->refreshCaches();
        $this->assertSame('선적중', $v->fresh()->progress_status);

        // 7) 거래완료 — H3(반입지 선행) 통과 + G1(미수 0) 통과
        $v = $v->fresh();
        $v->bl_document = 'fake/bl.pdf';
        $v->guardAttachmentDeps();        // H3 통과 (반입지 입력됨)
        $v->save();                       // G1 통과 (미수율 0%)
        $v->refreshCaches();
        $this->assertSame('거래완료', $v->fresh()->progress_status, 'B/L 발급 = 거래완료(v4)');
    }

    // ══════════════════════════════════════════════════════════════
    // 축 ②③ 각 락 fail → 해결·재통과 매트릭스
    // ══════════════════════════════════════════════════════════════

    /** 락 G2 — 같은 바이어 미수 잔존 + 신규 거래 (saving 훅, 영업 actor). */
    public function test_lock_g2_same_buyer_overlap_blocks_then_approval_passes(): void
    {
        $salesUser = $this->sales[0]['user'];   // 영업 — canApprove() false → G2 발동
        $sm = $this->sales[0]['salesman'];
        $this->actingAs($salesUser);

        $buyer = $this->buyer('G2 BUYER');

        // 미수 잔존 차량 1대 (판매가만, 입금 0 → 미수 100%)
        $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
        ]);

        // FAIL — 같은 바이어로 신규 거래 시도
        $blockedNumber = $this->vnum('G2NEW');
        try {
            Vehicle::create([
                'vehicle_number' => $blockedNumber,
                'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            ]);
            $this->fail('G2 락이 신규 거래를 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('미수 잔존 차량', $e->getMessage());
            $this->assertStringContainsString('관리자 승인', $e->getMessage());
        }

        // 해결 — 관리가 inter_buyer_overlap 승인 발급 (차량번호 바인딩)
        ApprovalRequest::create([
            'requester_id' => $salesUser->id,
            'approver_id' => $this->manager->id,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'payload' => ['new_vehicle_number' => $blockedNumber],
            'status' => ApprovalRequest::STATUS_APPROVED,
            'decided_at' => now(),
        ]);

        // 재통과 — 승인받은 차량번호로 재시도
        $v = Vehicle::create([
            'vehicle_number' => $blockedNumber,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
        ]);
        $this->assertNotNull($v->id, '승인 후 신규 거래 통과');
        $this->assertNotNull(
            ApprovalRequest::where('target_id', $buyer->id)->first()->used_at,
            '승인은 1회 소진(used_at 마킹)'
        );
    }

    /** 락 G1 — 100% B/L 게이트 (saving 훅). 미완납(미수율 > 0) 시 B/L 발행 차단. */
    public function test_lock_g1_bl_full_payment_blocks_then_payment_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[1]['salesman'];
        $buyer = $this->buyer('G1 BUYER');

        // 판매가 100만, 입금 0 → 미수율 100%
        $v = $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
            'bl_loading_location' => 'PUSAN',   // H3 선행조건 미리 충족
        ]);

        // FAIL — B/L 신규 첨부 차단
        $v = Vehicle::find($v->id);
        $v->bl_document = 'bl/test.pdf';
        try {
            $v->save();
            $this->fail('G1 락이 B/L 발행을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('B/L 발행 차단', $e->getMessage());
            $this->assertStringContainsString('100% 미완납', $e->getMessage());
        }

        // 해결 — 잔금 100% 완납 (미수율 0)
        $v = Vehicle::find($v->id);
        $v->finalPayments()->create([
            'amount' => 1_000_000, 'exchange_rate' => 1, 'payment_date' => '2026-05-02',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();

        // 재통과
        $v = Vehicle::find($v->id);
        $v->bl_document = 'bl/test.pdf';
        $v->save();
        $this->assertSame('bl/test.pdf', $v->fresh()->bl_document, '완납 → B/L 발행 통과');
    }

    /** 락 Ledger — 재무 확정 잔금 후 회계 영향 필드 잠금 (saving 훅). admin 잠금 해제로 1회 통과. */
    public function test_lock_ledger_field_blocks_then_admin_unlock_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[2]['salesman'];

        // 매입가 set + 매입 잔금 confirmed → 잠금 트리거
        $v = $this->baseVehicle($sm->id, ['purchase_price' => 1_000_000]);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertTrue($v->fresh()->hasConfirmedPaymentLock());

        // FAIL — 매입가(LEDGER_LOCK_FIELDS) 소급 변경 차단
        $v = Vehicle::find($v->id);
        $v->purchase_price = 1_500_000;
        try {
            $v->save();
            $this->fail('Ledger 락이 확정 후 회계 필드 변경을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('잠금 해제', $e->getMessage());
        }

        // 해결 — admin 잠금 해제 토큰 발급 (사유 10자 이상)
        app(VehicleLedgerUnlockService::class)->unlock(
            Vehicle::find($v->id), $admin, '바이어 정정 요청으로 매입가 재산정 필요 (전표 #1234)'
        );

        // 재통과 — 1회 소비
        $v = Vehicle::find($v->id);
        $v->purchase_price = 1_500_000;
        $v->save();
        $this->assertEquals(1_500_000, (float) $v->fresh()->purchase_price, '잠금 해제 후 1회 변경 통과');

        // 재잠금 검증 — 토큰 소비됐으므로 추가 변경은 다시 차단
        $v = Vehicle::find($v->id);
        $v->purchase_price = 2_000_000;
        $this->expectException(ValidationException::class);
        $v->save();
    }

    /** 락 삭제가드 — 확정 잔금 차량은 admin/super 만 삭제 (deleting 훅). */
    public function test_lock_delete_confirmed_payment_blocks_nonadmin_then_admin_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[3]['salesman'];

        $v = $this->baseVehicle($sm->id);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-01',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();

        // FAIL — 영업(비-admin)이 삭제 시도
        $this->actingAs($this->sales[3]['user']);
        try {
            Vehicle::find($v->id)->delete();
            $this->fail('삭제가드가 비-admin 삭제를 막아야 한다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('admin/super만 삭제', $e->getMessage());
        }
        $this->assertNotNull(Vehicle::find($v->id), '아직 삭제 안 됨');

        // 해결·재통과 — admin 삭제
        $this->actingAs($admin);
        Vehicle::find($v->id)->delete();
        $this->assertNull(Vehicle::find($v->id), 'admin 삭제 통과');
    }

    /** 락 C4 — 통관 진입 전 말소 완료 강제 (UI save()). */
    public function test_lock_c4_deregistration_required_blocks_then_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[4]['salesman'];
        $buyer = $this->buyer('C4 BUYER');

        // 말소 미완료 차량에 통관 정보 입력 시도
        $v = $this->baseVehicle($sm->id, ['buyer_id' => $buyer->id]);

        // FAIL
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        try {
            $v->guardStageOrderForExport();
            $this->fail('C4 락이 말소 미완료 통관 진입을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('말소 처리', $e->getMessage());
        }

        // 해결 — 말소 체크 + 서류 업로드
        $v = Vehicle::find($v->id);
        $v->update(['is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf']);

        // 재통과
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        $v->guardStageOrderForExport();
        $this->assertTrue(true, '말소 완료 후 통관 진입 통과');
    }

    /** 락 컨사이니 — 선적 진입(반입지) 전 판매 컨사이니 필수 (UI save()). */
    public function test_lock_consignee_required_for_shipping_blocks_then_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[0]['salesman'];
        $buyer = $this->buyer('CONS BUYER');

        // 말소 완료(C4 통과) + 판매가 0(C5 skip) + 반입지 입력, 컨사이니 없음
        $v = $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf',
            'bl_loading_location' => 'PUSAN',
        ]);

        // FAIL
        $v = Vehicle::find($v->id);
        try {
            $v->guardStageOrderForExport();
            $this->fail('컨사이니 락이 선적 진입을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('판매 컨사이니', $e->getMessage());
        }

        // 해결 — 판매 컨사이니 지정
        $cons = $this->consignee($buyer, 'CONS A');
        $v = Vehicle::find($v->id);
        $v->update(['consignee_id' => $cons->id]);

        // 재통과
        $v = Vehicle::find($v->id);
        $v->guardStageOrderForExport();
        $this->assertTrue(true, '판매 컨사이니 지정 후 선적 진입 통과');
    }

    /** 락 C5 — 입금률 < 50% 통관/선적 진입 차단 (UI save()). 50% 입금으로 해결. */
    public function test_lock_c5_fifty_percent_clearance_blocks_then_payment_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[1]['salesman'];
        $buyer = $this->buyer('C5 BUYER');

        // 말소완료 + 판매가 200만 + 입금 0 → 미수율 100%
        $v = $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf',
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01',
        ]);

        // FAIL — 통관 진입 시도 (clearance 단계)
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        try {
            $v->guardStageOrderForExport();
            $this->fail('C5 락이 입금률 < 50% 통관 진입을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('입금률 < 50%', $e->getMessage());
        }

        // 해결 — 잔금 75% 입금 (미수율 25% ≤ 50%)
        $v = Vehicle::find($v->id);
        $v->finalPayments()->create([
            'amount' => 1_500_000, 'exchange_rate' => 1, 'payment_date' => '2026-05-02',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();

        // 재통과
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        $v->guardStageOrderForExport();
        $this->assertTrue(true, '입금 50% 이상 → 통관 진입 통과');
    }

    /** 락 C5(외화) — 외화 + 환율 미입력 통관 진입 차단 (UI save()). admin 우회 승인으로 해결. */
    public function test_lock_c5_foreign_no_exchange_rate_blocks_then_override_passes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[2]['salesman'];
        $buyer = $this->buyer('C5FX BUYER');

        // 외화(USD) + 환율 0 + 판매가 입력 + 말소완료
        $v = $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf',
            'currency' => 'USD', 'exchange_rate' => 0,
            'sale_price' => 10_000, 'sale_date' => '2026-05-01',
        ]);

        // FAIL — 환율 미입력 → 미수율 평가 불가
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        try {
            $v->guardStageOrderForExport();
            $this->fail('C5 락이 환율 미입력 외화 통관 진입을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('환율 미입력 외화', $e->getMessage());
        }

        // 해결 — admin 미입금 우회 승인(clearance 단계)
        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'clearance',
            'approved_by' => $admin->id,
            'reason' => '바이어 신용장(L/C) 확인 완료. 환율 확정 전 통관 선진행 승인.',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 10_000,
        ]);

        // 재통과 — 우회 승인이 모든 시나리오 통과
        $v = Vehicle::find($v->id);
        $v->shipping_date = '2026-05-05';   // 방향1: 게이트 트리거는 실제 통관 행위(당사자 배정 아님)
        $v->guardStageOrderForExport();
        $this->assertTrue(true, 'admin 미입금 우회 승인 → 통관 진입 통과');
    }

    /**
     * 방향1 회귀 (2026-07-08) — 당사자 배정만으론 통관 게이트 미발동.
     * 말소 미완료 + 입금 0%(미수율 100%) 차량에 export_buyer_id(통관 바이어)만 채워도,
     * 실제 통관 행위(반입지·수출신고서·선적일·B/L·DHL)가 없으면 게이트 통과해야 한다.
     * (이전엔 export_buyer_id 단독으로 C4/C5가 오발동해 판매·말소 저장을 통째 막았음.)
     */
    public function test_party_assignment_alone_does_not_trigger_export_gate(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $sm = $this->sales[0]['salesman'];
        $buyer = $this->buyer('PARTY ONLY');

        // 말소 미완료 + 판매가 200만 + 입금 0 → 예전 C4·C5 둘 다 걸리던 조합
        $v = $this->baseVehicle($sm->id, [
            'buyer_id' => $buyer->id,
            'sale_price' => 2_000_000, 'sale_date' => '2026-05-01',
        ]);

        // 당사자(통관 바이어)만 배정 — 실제 통관 행위 없음 → throw 하면 실패
        $v = Vehicle::find($v->id);
        $v->export_buyer_id = $buyer->id;
        $v->guardStageOrderForExport();
        $this->assertTrue(true, '당사자 배정만으론 게이트 미발동 (방향1)');
    }

    /** 락 H3 — B/L 문서 업로드 전 선적 반입지 입력 필수 (UI save()). */
    public function test_lock_h3_bl_requires_loading_location_blocks_then_passes(): void
    {
        $sm = $this->sales[3]['salesman'];

        // 반입지 없이 B/L 문서만
        $v = $this->baseVehicle($sm->id);
        $v->bl_document = 'bl/test.pdf';

        // FAIL
        try {
            $v->guardAttachmentDeps();
            $this->fail('H3 락이 반입지 없는 B/L 업로드를 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('선적 반입지 입력', $e->getMessage());
        }

        // 해결·재통과 — 반입지 입력
        $v->bl_loading_location = 'PUSAN';
        $v->guardAttachmentDeps();
        $this->assertTrue(true, '반입지 입력 후 B/L 업로드 통과');
    }

    /** 락 H1 — DHL 발송 신청 전 B/L 문서 업로드 필수 (UI save()). */
    public function test_lock_h1_dhl_requires_bl_document_blocks_then_passes(): void
    {
        $sm = $this->sales[4]['salesman'];

        // B/L 문서 없이 DHL 발송 신청
        $v = $this->baseVehicle($sm->id);
        $v->dhl_request = true;

        // FAIL
        try {
            $v->guardAttachmentDeps();
            $this->fail('H1 락이 B/L 없는 DHL 신청을 막아야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('B/L 문서 업로드', $e->getMessage());
        }

        // 해결·재통과 — 반입지 + B/L 문서 입력 (H3 선행조건 포함)
        $v->bl_loading_location = 'PUSAN';
        $v->bl_document = 'bl/test.pdf';
        $v->guardAttachmentDeps();
        $this->assertTrue(true, 'B/L 업로드 후 DHL 신청 통과');
    }

    // ══════════════════════════════════════════════════════════════
    // 축 ④ 관리 scoping — 관리 1명이 본인 영업 5명 차량만 조회
    // ══════════════════════════════════════════════════════════════

    public function test_axis4_manager_sees_only_own_five_salesmen_vehicles(): void
    {
        // 본인 팀: 영업 5명 각 1대 = 5대
        $myIds = [];
        foreach ($this->sales as $i => $pair) {
            $v = $this->baseVehicle($pair['salesman']->id, ['vehicle_number' => "MINE-{$i}"]);
            $myIds[] = $v->id;
        }

        // 타 팀: 다른 관리 + 영업 1명 + 차량 1대
        $otherManager = User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $otherUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'type' => 'employee',
            'manager_user_id' => $otherManager->id, 'email_verified_at' => now(),
        ]);
        $otherSm = Salesman::create([
            'user_id' => $otherUser->id, 'name' => $otherUser->name,
            'is_active' => true, 'type' => 'employee',
        ]);
        $otherVehicle = $this->baseVehicle($otherSm->id, ['vehicle_number' => 'OTHER-1']);

        // 관리-알파 시선 — 본인 영업 5명 차량만
        $this->actingAs($this->manager);
        $list = Volt::test('erp.vehicles.index')
            ->set('dateFrom', now()->subYear()->format('Y-m-d'))
            ->set('dateTo', now()->addDay()->format('Y-m-d'))
            ->instance()->vehicles;
        $ids = $list->pluck('id')->toArray();

        foreach ($myIds as $id) {
            $this->assertContains($id, $ids, '본인 영업 5명 차량은 모두 노출');
        }
        $this->assertNotContains($otherVehicle->id, $ids, '타 팀(다른 관리) 차량은 비노출');
        $this->assertCount(5, $ids, '정확히 5대만 (관리 1 : 영업 5)');
    }
}
