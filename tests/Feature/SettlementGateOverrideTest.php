<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
use App\Services\SettlementGateOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 신규 정산 게이트 + 예외 처리 (jin 2026-09-12).
 * 정본 = `docs/design/settlement-gate-exception.md`.
 *
 * 🚨 **전부 일부러 깨뜨려 확인했다**(SKILLS §8 #73) — 술어를 하나씩 되돌려 빨개지는지 봤다.
 *
 * ⚠️ 잠금 유예·재잠금은 **운영 경로**를 탄다(`FinalPayment::creating` · `PaymentConfirmationService`).
 *    팩토리로 행을 직접 만들면 지키려는 훅을 안 태워 원리상 아무것도 검증하지 못한다(§8 #43·#80).
 */
class SettlementGateOverrideTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 영업 — 「재무 이상」 밖. ⚠️ role '관리' 는 **안에** 있다(canConfirmFinanceTransfer, jin 2026-05-21). */
    private function sales(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
    }

    /** 판매됐고 담당자도 있는데 **운임비만큼 미수가 남은** 차 — 실사고 6건의 모양 그대로. */
    private function freightUnpaidVehicle(float $freight = 1312.0): Vehicle
    {
        $buyer = Buyer::create(['name' => 'GB'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'GS'.$this->c, 'is_active' => true, 'type' => 'employee']);

        $v = Vehicle::create([
            'vehicle_number' => 'GO'.$this->c, 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1400, 'incoterms' => 'FOB',
            'sale_price' => 10_000, 'transport_fee' => $freight,
            'sale_date' => '2026-07-10', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
            'purchase_price' => 5_000_000,
        ]);

        // 판매가만 입금 — 운임비가 미수로 남는다(총판매가에는 들어가고 정산 기준액에는 없다).
        //   withoutEvents = 여기서 자동 정산이 만들어지지 않게(미수가 있으니 어차피 안 만들어지지만
        //   조건을 테스트 셋업이 우연히 만족시키는 일이 없도록 명시한다).
        FinalPayment::withoutEvents(fn () => $v->finalPayments()->create([
            'amount' => 10_000, 'type' => 'balance', 'payment_date' => '2026-07-20',
            'confirmed_at' => now(), 'exchange_rate' => 1400,
        ]));
        $v->refresh()->refreshCaches();

        return $v->fresh();
    }

    // ── 1. 조건 단일 출처 ───────────────────────────────────────────

    public function test_blockers_collect_every_reason_not_just_the_first(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'GB0', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'sale_price' => 0,
        ]);

        // 판매가 0 + 담당자 없음이 **둘 다** 나와야 한다. early-return 이면 하나만 나오고,
        // 그러면 「예외 대상」 판정이 담당자 없는 차를 「미수만 걸렸다」로 읽는다.
        $b = $v->settlementBlockers();
        $this->assertContains('no_sale', $b);
        $this->assertContains('no_salesman', $b);
    }

    public function test_freight_unpaid_vehicle_is_overridable_but_missing_salesman_is_not(): void
    {
        $svc = app(SettlementGateOverrideService::class);

        $v = $this->freightUnpaidVehicle();
        $this->assertSame(['unpaid'], $v->settlementBlockers());
        $this->assertTrue($svc->isOverridable($v));

        // 담당자를 떼면 **입력 미비**라 예외로 못 넘긴다 — 예외로 뚫으면 지급 대상 없는 정산이 생긴다.
        $v->forceFill(['salesman_id' => null])->save();
        $this->assertFalse($svc->isOverridable($v->fresh()));
    }

    // ── 2. 수동 생성 게이트 (create 분기) ──────────────────────────────

    public function test_manual_create_is_blocked_without_a_reason_and_passes_with_one(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();

        // 사유 없이 = 차단. ⚠️ 이 경로는 오늘까지 테스트가 0건이었고, 그래서 미수 87 EUR 짜리
        //    수동 정산(#5806)이 아무 저항 없이 만들어졌다.
        Volt::test('erp.settlements.index')
            ->call('openCreate')
            ->call('selectVehicle', $v->id)
            ->set('settlement_type', 'per_unit')
            ->set('per_unit_amount', 100000)
            ->set('settlement_status', 'pending')
            ->call('save')
            ->assertHasErrors('gateReason');
        $this->assertSame(0, Settlement::count(), '막혔으면 행이 남지 않아야 한다');

        // 사유를 쓰면 통과 + 5컬럼 기입
        Volt::test('erp.settlements.index')
            ->call('openCreate')
            ->call('selectVehicle', $v->id)
            ->set('settlement_type', 'per_unit')
            ->set('per_unit_amount', 100000)
            ->set('settlement_status', 'pending')
            ->set('gateReason', '미수 1,312 EUR — 운임비와 동일. 도착 후 정산 예정.')
            ->call('save')
            ->assertHasNoErrors();

        $s = Settlement::sole();
        $this->assertTrue($s->hasGateOverride());
        $this->assertSame(['unpaid'], $s->gate_override_blockers);
        $this->assertSame(1312.0, (float) $s->gate_override_unpaid_amount, '그때의 미수 스냅샷');
        $this->assertNotNull($s->gate_override_by);
    }

    public function test_manual_create_stays_blocked_when_the_reason_is_not_overridable(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $v->forceFill(['salesman_id' => null])->save();

        // 담당자 없음은 사유로 못 넘긴다 — 사유를 길게 써도 막혀야 한다.
        Volt::test('erp.settlements.index')
            ->call('openCreate')
            ->call('selectVehicle', $v->id)
            ->set('settlement_type', 'per_unit')
            ->set('per_unit_amount', 100000)
            ->set('settlement_status', 'pending')
            ->set('gateReason', '담당자가 곧 정해질 예정이라 미리 만들어 둡니다.')
            ->call('save')
            ->assertHasErrors('vehicle_id');
        $this->assertSame(0, Settlement::count());
    }

    public function test_editing_an_existing_settlement_never_hits_the_gate(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000, 'settlement_status' => 'pending',
        ]);

        // 미수가 남은 차의 정산이어도 **메모·기타공제 수정은 막히면 안 된다**(§8 #65 ①).
        //   폼에 실린 옛 vehicle_id 로 게이트가 참이 되어 무관한 저장을 막는 게 이 부류의 사고다.
        Volt::test('erp.settlements.index')
            ->call('openEdit', $s->id)
            ->set('other_deduction', 50000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(50000.0, (float) $s->fresh()->other_deduction);
        $this->assertFalse($s->fresh()->hasGateOverride(), '편집은 예외를 찍지 않는다');
    }

    // ── 3. 주 통로 — 「예외로 정산 생성」 ────────────────────────────────

    public function test_creating_with_override_uses_the_automatic_body(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();

        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');

        // 🔑 수동 폼과 다른 점이 정확히 이것이다 — 자동 경로와 같은 본체라 귀속월·내수가 채워진다.
        $this->assertNotNull($s->attributed_month, '귀속월이 생성월이 아니라 완납월로 박제돼야 한다');
        $this->assertSame('2026-07-01', $s->attributed_month->format('Y-m-d'));
        $this->assertFalse((bool) $s->is_domestic);
        $this->assertSame($v->salesman_id, $s->salesman_id);
        $this->assertStringContainsString('예외 처리로 생성', $s->note);
        $this->assertTrue($s->hasGateOverride());
    }

    public function test_candidates_only_lists_vehicles_whose_blockers_are_all_overridable(): void
    {
        $svc = app(SettlementGateOverrideService::class);

        $ok = $this->freightUnpaidVehicle();
        $noSalesman = $this->freightUnpaidVehicle();
        $noSalesman->forceFill(['salesman_id' => null])->save();
        $settled = $this->freightUnpaidVehicle();
        Settlement::create([
            'vehicle_id' => $settled->id, 'salesman_id' => $settled->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000, 'settlement_status' => 'pending',
        ]);

        $ids = $svc->candidates()->pluck('id');
        $this->assertContains($ok->id, $ids);
        $this->assertNotContains($noSalesman->id, $ids, '담당자 없음은 입력 미비다');
        $this->assertNotContains($settled->id, $ids, '이미 정산이 있으면 대상이 아니다');
    }

    public function test_only_finance_or_above_can_override(): void
    {
        $v = $this->freightUnpaidVehicle();

        // ⚠️ 「재무 이상」에 role '관리' 는 **포함**된다(canConfirmFinanceTransfer — 2026-05-21 jin
        //    «중간 관리자라 일상 운영 허용»). 밖에 있는 건 영업이다.
        $this->expectException(\DomainException::class);
        app(SettlementGateOverrideService::class)
            ->createWithOverride($v, $this->sales(), '영업은 예외를 걸 수 없어야 합니다.');
    }

    // ── 4. 지급보류 ────────────────────────────────────────────────

    public function test_override_passes_the_payout_hold_in_all_four_places(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        $s->forceFill(['settlement_status' => 'confirmed', 'confirmed_at' => now()])->save();

        // ① 술어 ② SQL 스코프 ③ 월배치 대상 — 셋이 같은 답을 내야 한다.
        $this->assertFalse($s->fresh()->isPayoutHeldByUnpaid());
        $this->assertFalse(Settlement::payoutHeldByUnpaid()->whereKey($s->id)->exists());
        $this->assertContains(
            $s->id,
            SettlementPayoutBatch::eligibleSettlementIds($s->attributed_month->format('Y-m'))->all()
        );

        // 예외를 해제하면 셋 다 되돌아간다(상태값이 없어 즉시 반영 — 재잠금과 같은 원리).
        app(SettlementGateOverrideService::class)->release($s->fresh(), auth()->user());
        $s->refresh();
        $this->assertTrue($s->isPayoutHeldByUnpaid());
        $this->assertTrue(Settlement::payoutHeldByUnpaid()->whereKey($s->id)->exists());
        $this->assertNotContains(
            $s->id,
            SettlementPayoutBatch::eligibleSettlementIds($s->attributed_month->format('Y-m'))->all()
        );
    }

    public function test_individual_pay_approval_now_respects_the_hold_and_the_override(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin);

        // 예외가 없으면 막힌다 — 이 가드는 2026-09-12 에 새로 생겼다(월배치만 보던 비대칭).
        $req = ApprovalRequest::create([
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class, 'target_id' => $s->id,
            'requester_id' => $admin->id, 'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $admin->id, 'decided_at' => now(), 'reason' => '지급',
        ]);
        try {
            $req->execute();
            $this->fail('미수가 남은 정산이 개별 승인으로 지급됐다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('미수', $e->getMessage());
        }
        $this->assertSame('confirmed', $s->fresh()->settlement_status);

        // 예외를 걸면 통과한다.
        $this->actingAs($this->finance());
        app(SettlementGateOverrideService::class)
            ->applyToExisting($s->fresh(), auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');

        $this->actingAs($admin);
        ApprovalRequest::create([
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class, 'target_id' => $s->id,
            'requester_id' => $admin->id, 'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $admin->id, 'decided_at' => now(), 'reason' => '지급',
        ])->execute();

        $this->assertSame('paid', $s->fresh()->settlement_status);
    }

    // ── 5. 2차 마감 ────────────────────────────────────────────────

    public function test_override_passes_the_secondary_close_full_payment_gate(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        Settlement::$allowBatchPayout = true;   // Phase 2 — setup paid 가드 우회
        $s->forceFill([
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
            'secondary_status' => 'pending', 'exchange_rate_at_close' => 1400,
        ])->save();
        Settlement::$allowBatchPayout = false;

        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        // 외화 + 미수 > 0 인데도 닫혀야 한다 — 안 열면 이 정산은 영영 안 닫히고
        // 환차·이월이 계산되지 않아 프리랜서 이월이 통째로 증발한다.
        $this->assertSame('closed', $s->fresh()->secondary_status);
    }

    // ── 6. 회계 잠금 유예 · 재잠금 ──────────────────────────────────

    public function test_closed_override_defers_the_lock_for_new_payments_only(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        $s->forceFill(['secondary_status' => 'closed', 'secondary_closed_at' => now()])->save();

        $v->refresh();
        $this->assertTrue($v->hasClosedSecondarySettlement(), '마감 자체는 그대로다');
        $this->assertFalse($v->ledgerLockedForNewPayments(), '신규 잔금만 유예된다');

        // 신규 잔금은 들어간다(B-①) — 운영 경로인 모델 훅을 그대로 탄다.
        $fp = $v->finalPayments()->create([
            'amount' => 1312, 'type' => 'balance', 'payment_date' => '2026-09-10', 'exchange_rate' => 1400,
        ]);
        $this->assertDatabaseHas('final_payments', ['id' => $fp->id]);

        // 차량 회계칸(A)은 **그대로 잠겨 있다** — 과거 기록은 못 건드린다.
        $v = $v->fresh();
        $v->purchase_price = 9_999_999;
        try {
            $v->save();
            $this->fail('마감 차량의 매입가가 예외 하나로 열렸다');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(5_000_000.0, (float) $v->fresh()->purchase_price);
    }

    public function test_the_lock_returns_by_itself_once_the_receivable_is_cleared(): void
    {
        $finance = $this->finance();
        $this->actingAs($finance);
        $v = $this->freightUnpaidVehicle();
        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, $finance, '미수 1,312 EUR — 운임비와 동일합니다.');
        $s->forceFill(['secondary_status' => 'closed', 'secondary_closed_at' => now()])->save();

        $fp = $v->fresh()->finalPayments()->create([
            'amount' => 1312, 'type' => 'balance', 'payment_date' => '2026-09-10', 'exchange_rate' => 1400,
        ]);

        // 🔑 재무확정을 같이 안 풀면 반쪽이다 — 미수 분자는 confirmed 행만 센다(§13).
        //    잔금은 들어갔는데 미수가 안 줄면 재잠금이 영영 안 걸린다(§8 #66 에서 밟은 자리).
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);

        $v = $v->fresh();
        $v->refreshCaches();
        $this->assertLessThanOrEqual(0, (int) $v->fresh()->sale_unpaid_amount, '미수 0');
        $this->assertTrue($v->fresh()->ledgerLockedForNewPayments(), '미수가 0 이면 즉시 재잠금');
    }

    public function test_releasing_the_override_locks_the_ledger_again(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        $s->forceFill(['secondary_status' => 'closed', 'secondary_closed_at' => now()])->save();
        $this->assertFalse($v->fresh()->ledgerLockedForNewPayments());

        app(SettlementGateOverrideService::class)->release($s->fresh(), auth()->user());
        $this->assertTrue($v->fresh()->ledgerLockedForNewPayments(), '미수가 남아 있어도 예외를 풀면 잠긴다');
    }

    public function test_one_non_overridden_closed_settlement_keeps_the_lock(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $s1 = app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        $s1->forceFill(['secondary_status' => 'closed', 'secondary_closed_at' => now()])->save();

        // 담당자 승계·재생성으로 한 차에 마감 정산이 둘 이상 생기는 일이 실재한다. 안전한 쪽으로 잠근다.
        Settlement::$allowBatchPayout = true;   // Phase 2 — setup paid 가드 우회
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100000,
            'settlement_status' => 'paid', 'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);
        Settlement::$allowBatchPayout = false;

        $this->assertTrue($v->fresh()->ledgerLockedForNewPayments());
    }

    // ── 7. 미수 표시는 손대지 않았다 ─────────────────────────────────

    public function test_the_override_does_not_touch_the_receivable_figures(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $before = (float) $v->sale_unpaid_amount;
        $ratioBefore = $v->unpaid_ratio;

        app(SettlementGateOverrideService::class)
            ->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');

        $v = $v->fresh();
        $this->assertSame($before, (float) $v->sale_unpaid_amount, '예외는 미수를 깎지 않는다');
        $this->assertSame($ratioBefore, $v->unpaid_ratio, '게이지도 그대로');
    }

    // ── 8. 감사 기록 ───────────────────────────────────────────────

    public function test_both_audit_actions_are_recorded_and_have_korean_labels(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $svc = app(SettlementGateOverrideService::class);
        $s = $svc->createWithOverride($v, auth()->user(), '미수 1,312 EUR — 운임비와 동일합니다.');
        $svc->release($s->fresh(), auth()->user());

        foreach (['settlement_gate_overridden', 'settlement_gate_override_released'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'auditable_type' => Settlement::class, 'auditable_id' => $s->id, 'action' => $action,
            ]);
            // 영문 식별자가 감사 화면에 그대로 노출되면 안 된다(§8 #41).
            $label = config('column_labels.actions.'.$action);
            $this->assertNotNull($label, "{$action} 한글 라벨 누락");
            $this->assertMatchesRegularExpression('/[가-힣]/u', $label);
        }
    }

    // ── 9. 「예외 대상」 목록 — 검색·페이지·담아두기 ──────────────────

    public function test_the_candidate_list_can_be_searched_by_plate_and_salesman(): void
    {
        $this->actingAs($this->finance());
        $a = $this->freightUnpaidVehicle();
        $b = $this->freightUnpaidVehicle();

        $c = Volt::test('erp.settlements.index')->call('openGateCandidates');
        $c->assertSet('gateTotal', 2);

        // 차량번호
        $c->set('gateSearch', $a->vehicle_number)->assertSet('gateTotal', 1);
        $this->assertSame($a->vehicle_number, $c->get('gateRows')[0]['plate']);

        // 담당자 이름
        $c->set('gateSearch', $b->salesman->name)->assertSet('gateTotal', 1);
        $this->assertSame($b->vehicle_number, $c->get('gateRows')[0]['plate']);

        // 없는 값
        $c->set('gateSearch', 'ZZZZ없음')->assertSet('gateTotal', 0);
        $this->assertSame([], $c->get('gateRows'));

        // 비우면 전부 돌아온다
        $c->set('gateSearch', '')->assertSet('gateTotal', 2);
    }

    public function test_the_candidate_list_is_paged_not_dumped_at_once(): void
    {
        $this->actingAs($this->finance());
        for ($i = 0; $i < 22; $i++) {
            $this->freightUnpaidVehicle();
        }

        $c = Volt::test('erp.settlements.index')->call('openGateCandidates');
        $c->assertSet('gateTotal', 22)->assertSet('gatePages', 2)->assertSet('gatePage', 1);
        $this->assertCount(20, $c->get('gateRows'), '한 화면에 스무 줄까지만');

        $c->call('gatePageMove', 1)->assertSet('gatePage', 2);
        $this->assertCount(2, $c->get('gateRows'));

        // 끝을 넘겨 눌러도 마지막 페이지에 머문다
        $c->call('gatePageMove', 1)->assertSet('gatePage', 2);
        $c->call('gatePageMove', -1)->assertSet('gatePage', 1);
    }

    /**
     * 🚨 성능 가드 — 이 화면은 `wire:poll.30s` 다. 목록을 computed 로 두면 모달을 열어둔 30초마다
     *    후보 전량의 미수 accessor 가 다시 돈다(싼카 458대). 담아 두면 poll 이 공짜다.
     *
     * 그래서 **밖에서 상태가 바뀌어도 다시 그리는 것만으로는 목록이 안 변해야** 한다 —
     * 그게 「다시 계산하지 않는다」는 뜻이다. [새로고침] 을 눌러야 반영된다.
     */
    public function test_the_list_is_stashed_so_the_poll_does_not_recompute_it(): void
    {
        $finance = $this->finance();
        $this->actingAs($finance);
        $v = $this->freightUnpaidVehicle();

        $c = Volt::test('erp.settlements.index')->call('openGateCandidates');
        $c->assertSet('gateTotal', 1);

        // 밖에서 완납시키면 이 차는 더 이상 후보가 아니다.
        $fp = $v->fresh()->finalPayments()->create([
            'amount' => 1312, 'type' => 'balance', 'payment_date' => '2026-09-10', 'exchange_rate' => 1400,
        ]);
        app(PaymentConfirmationService::class)->confirmPayment($fp, $finance);
        $v->fresh()->refreshCaches();
        // ⚠️ 완납되면 **자동 정산이 생긴다** — 그래서 사유가 사라지는 게 아니라 `already_exists` 로
        //    바뀐다. 어느 쪽이든 「예외 대상」에서는 빠진다. 그걸 단언한다(빈 배열이 아니라).
        $this->assertFalse(
            app(SettlementGateOverrideService::class)->isOverridable($v->fresh()),
            '후보 조건이 실제로 풀렸다'
        );

        // 다시 그려도(= poll 이 돌아도) 담아둔 값 그대로 — 재계산하지 않는다는 증거다.
        $c->call('$refresh')->assertSet('gateTotal', 1);

        // [새로고침] 을 눌러야 빠진다.
        $c->call('loadGateCandidates')->assertSet('gateTotal', 0);
    }

    public function test_creating_an_override_drops_that_row_from_the_open_list(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();

        $c = Volt::test('erp.settlements.index')->call('openGateCandidates');
        $c->assertSet('gateTotal', 1);

        $c->call('startGateOverride', $v->id)
            ->set('gateReason', '미수 1,312 EUR — 운임비와 동일합니다.')
            ->call('confirmGateOverride');

        // 만들고 나면 그 행은 목록에서 빠져야 한다 — 안 그러면 두 번 누른다.
        $c->assertSet('gateTotal', 0);
        $this->assertTrue(Settlement::sole()->hasGateOverride());
    }

    public function test_a_short_reason_is_rejected(): void
    {
        $this->actingAs($this->finance());
        $v = $this->freightUnpaidVehicle();
        $this->expectException(\DomainException::class);
        app(SettlementGateOverrideService::class)->createWithOverride($v, auth()->user(), '운임');
    }
}
