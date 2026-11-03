<?php

namespace Tests\Feature;

use App\Models\DomesticFeeStatement;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Services\Payout\BatchPayoutBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 내수직원 월정산 (jin 2026-11-03) — 사내직원 월정산과 **따로 결재**한다.
 *
 * - 회사별 토글(기본 ssancarerp 켬 · heymanerp·karabaerp 끔). 끄면 내수 기능이 통째로 숨는다(종전 동작 = 내수직원은 사내직원 월정산 안).
 * - 볼 사람 = 시스템관리자 + 기능설정에서 지정한 관리 이상. 지정 안 된 결재자는 자기 차례일 때만(알림톡 링크·메뉴).
 * - 켜진 회사의 사내직원 월정산엔 내수직원 카드가 빠지고 「내수직원 합계」 한 줄만 남는다(표시 전용 — jin «합계 한 줄이면 족해»).
 * - 내수 배치 = kind=domestic, 정산 0건 · 총액 = 그 사람들의 그 달 급여 줄 합, 결재선은 사내직원 월정산과 같다. 달을 닫지 않는다.
 */
class DomesticPayoutBatchTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0109999'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now(),
        ]);
    }

    private function setCompany(string $set): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_template_set'], ['value' => $set, 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    private function viewers(array $users): void
    {
        Setting::query()->updateOrCreate(['key' => 'domestic_payout_viewers'], ['value' => json_encode(array_map(fn ($u) => $u->id, $users)), 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    /** 내수직원 한 명 + 그 달 평가수수료 표 + 급여 줄(업로드가 만드는 그대로). */
    private function domestic(string $name, int $payout, string $month = '2026-10'): Salesman
    {
        $sm = Salesman::create(['name' => $name, 'type' => 'domestic_staff', 'is_active' => true]);
        DomesticFeeStatement::create(['salesman_id' => $sm->id, 'month' => $month, 'headers' => ['건수', '총 평가사수수료'], 'values' => [10, $payout], 'payout' => $payout]);
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => $month, 'label' => DomesticFeeStatement::PAYROLL_LABEL, 'amount' => $payout, 'sort' => 0, 'is_custom' => true]);

        return $sm;
    }

    private function line(): array
    {
        $ceo = $this->user('admin', '관리', '대표');

        return [$ceo, ['대표' => $ceo->id]];
    }

    public function test_toggle_defaults_on_for_ssancar_only(): void
    {
        $this->setCompany('system');
        $this->assertTrue(Setting::domesticPayoutEnabled());
        $this->setCompany('heyman');
        $this->assertFalse(Setting::domesticPayoutEnabled());
        $this->setCompany('karaba');
        $this->assertFalse(Setting::domesticPayoutEnabled());
        $this->assertNotContains(User::DOMESTIC_STAFF_ROLE, User::roleOptions(), '꺼진 회사는 「내수」 역할을 안 내놓는다');
    }

    public function test_only_super_and_designated_managers_see_it(): void
    {
        $this->setCompany('system');
        $super = $this->user('super');
        $picked = $this->user('user', '관리');
        $other = $this->user('admin');
        $this->viewers([$picked]);

        $this->assertTrue($super->canSeeDomesticPayout());
        $this->assertTrue($picked->canSeeDomesticPayout());
        $this->assertFalse($other->canSeeDomesticPayout(), '지정 안 된 최고관리자');

        $this->setCompany('heyman');
        $this->assertFalse($picked->canSeeDomesticPayout(), '토글이 꺼지면 지정자도 못 본다');
        $this->assertFalse($super->canSeeDomesticPayout());
    }

    public function test_submit_makes_a_separate_batch_and_the_staff_settlement_keeps_one_total_line(): void
    {
        $this->setCompany('system');
        $a = $this->domestic('김진기', 2_701_200);
        $this->domestic('이기용', 6_488_570);
        $submitter = $this->user('user', '관리');
        $this->viewers([$submitter]);
        [, $line] = $this->line();

        $batch = SettlementPayoutBatch::submitForDomestic($submitter, '2026-10', $line);

        $this->assertSame(SettlementPayoutBatch::KIND_DOMESTIC, $batch->kind);
        $this->assertSame(9_189_770, (int) $batch->total_payout, '총액 = 그 달 총 평가사수수료 합');
        $this->assertSame(2, (int) $batch->settlement_count, '인원');
        $this->assertSame(1, $batch->steps()->count());

        $bd = BatchPayoutBreakdown::forBatch($batch);
        $this->assertSame(['domestic_staff', 'domestic_staff'], array_column($bd['people'], 'type'));

        // 사내직원 월정산(제출 전 미리보기) — 내수직원 카드는 없고 합계 한 줄만
        $month = BatchPayoutBreakdown::forMonthPreview('2026-10', collect());
        $this->assertNotContains($a->id, array_column($month['people'], 'salesman_id'));
        $this->assertTrue($month['domestic_split']);
        $this->assertSame(2, $month['domestic_fee_total']['count']);
        $this->assertSame(0, $month['totals']['common_labor'], '회사이익(공통 인건비)엔 안 들어간다 — 표시 전용 한 줄');

        $this->expectException(\DomainException::class);
        SettlementPayoutBatch::submitForDomestic($submitter, '2026-10', $line);   // 같은 달 두 번 금지
    }

    public function test_final_approval_does_not_close_the_month(): void
    {
        $this->setCompany('system');
        $this->domestic('김진기', 2_701_200);
        $submitter = $this->user('user', '관리');
        $this->viewers([$submitter]);
        [$ceo, $line] = $this->line();

        $batch = SettlementPayoutBatch::submitForDomestic($submitter, '2026-10', $line);
        $batch->approveBy($ceo);

        $batch->refresh();
        $this->assertSame(SettlementPayoutBatch::STATUS_APPROVED, $batch->status);
        $this->assertSame(2_701_200, (int) $batch->total_payout, '승인 때 다시 재도 급여 합 그대로(정산 0건이라 0 이 되면 안 된다)');
        $this->assertFalse(SettlementPayoutBatch::isMonthClosed('2026-10'), '내수 배치는 달을 닫지 않는다');
        $this->assertSame(['domestic_staff'], array_column($batch->breakdown_snapshot['people'], 'type'));
    }

    public function test_submit_needs_a_viewer_a_line_and_a_sheet(): void
    {
        $this->setCompany('system');
        $this->domestic('김진기', 1_000);
        [, $line] = $this->line();
        $viewer = $this->user('user', '관리');
        $this->viewers([$viewer]);

        foreach ([
            fn () => SettlementPayoutBatch::submitForDomestic($this->user('user', '관리'), '2026-10', $line),   // 지정 안 됨
            fn () => SettlementPayoutBatch::submitForDomestic($viewer, '2026-10', []),                          // 결재선 없음
            fn () => SettlementPayoutBatch::submitForDomestic($viewer, '2026-09', $line),                       // 표 없음
        ] as $i => $call) {
            try {
                $call();
                $this->fail("#{$i} 이 통과하면 안 된다");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** 순수 보존 — 꺼진 회사(heymanerp·karabaerp)는 종전대로 내수직원이 사내직원 월정산 카드 안에 있다. */
    public function test_toggle_off_keeps_todays_staff_settlement(): void
    {
        $this->setCompany('heyman');
        $a = $this->domestic('김진기', 2_701_200);

        $month = BatchPayoutBreakdown::forMonthPreview('2026-10', collect());
        $this->assertContains($a->id, array_column($month['people'], 'salesman_id'));
        $this->assertFalse($month['domestic_split']);
        $this->assertSame(2_701_200, $month['totals']['common_labor']);
    }

    public function test_screens_are_gated(): void
    {
        $this->setCompany('system');
        $this->domestic('김진기', 2_701_200);
        $viewer = $this->user('user', '관리');
        $other = $this->user('user', '관리');
        $this->viewers([$viewer]);
        [, $line] = $this->line();

        $this->actingAs($other)->get(route('erp.domestic-payouts.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('erp.domestic-payouts.index'))->assertOk()->assertSee('김진기');

        // 사내직원관리 — 지정자만 내수 탭·업로드 버튼
        $this->actingAs($other);
        $html = Volt::test('erp.salesmen.index')->html();
        $this->assertStringNotContainsString('data-domestic-import-button', $html);
        $this->assertStringNotContainsString('김진기', $html, '「전체」에서도 내수직원 행이 안 보인다');
        $this->actingAs($viewer);
        $this->assertStringContainsString('data-domestic-import-button', Volt::test('erp.salesmen.index')->html());

        // 제출 → 사내직원 월정산 목록엔 안 보이고, 내수직원 월정산 목록에만
        $this->actingAs($viewer);
        Volt::test('erp.payout-batches.index')->assertDontSee('data-domestic-batch', false);
        $batch = SettlementPayoutBatch::submitForDomestic($viewer, '2026-10', $line);
        Volt::test('erp.payout-batches.index')->assertDontSee('data-domestic-batch', false);
        $this->get(route('erp.domestic-payouts.index'))->assertOk()->assertSee('data-domestic-batch', false);
        $this->assertSame(SettlementPayoutBatch::STATUS_PENDING, $batch->status);
    }

    public function test_feature_settings_toggle_and_viewers_are_super_only_and_audited(): void
    {
        $this->setCompany('heyman');
        $super = $this->user('super');
        $manager = $this->user('user', '관리');
        $finance = $this->user('user', '재무');

        $this->actingAs($super);
        $c = Volt::test('admin.settings')->assertSet('domesticPayoutEnabled', false)
            ->set('domesticPayoutEnabled', true)
            ->assertSee('data-domestic-viewers', false)
            ->assertSee($manager->name)->assertDontSee($finance->name)   // 후보 = 관리 이상
            ->set('domesticViewerIds', [(string) $manager->id, (string) $finance->id])
            ->call('saveDomesticViewers');

        $this->assertTrue(Setting::domesticPayoutEnabled());
        $this->assertSame([$manager->id], Setting::domesticPayoutViewerIds(), '후보 밖(재무)은 저장되지 않는다');
        $this->assertTrue($manager->fresh()->canSeeDomesticPayout());
        $this->assertDatabaseHas('audit_logs', ['action' => 'domestic_payout_toggle_changed', 'new_value' => '1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'domestic_payout_viewers_changed']);

        $this->actingAs($manager)->get(route('admin.settings'))->assertStatus(403);   // 기능설정 자체가 시스템관리자 전용
    }

    public function test_a_non_designated_approver_reaches_it_only_on_their_turn(): void
    {
        $this->setCompany('system');
        $this->domestic('김진기', 2_701_200);
        $viewer = $this->user('user', '관리');
        $this->viewers([$viewer]);
        [$ceo, $line] = $this->line();

        $this->actingAs($ceo)->get(route('erp.domestic-payouts.index'))->assertForbidden();
        SettlementPayoutBatch::submitForDomestic($viewer, '2026-10', $line);
        $this->actingAs($ceo)->get(route('erp.domestic-payouts.index'))->assertOk()->assertDontSee('data-domestic-submit', false);
    }
}
