<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 락 기준선은 **시스템관리자 전용** (jin 2026-08-21).
 *
 * 배경: ERP 실무자는 관리·업무관리자 둘뿐이다(영업은 board 를 쓴다). 그 둘이 락을 설정하는
 * 사람이자 락에 걸리는 사람이면 "막히면 자기가 올린다"가 되어 통제가 성립하지 않는다.
 * 그래서 락 %와 무담보 한도를 super 로 올리고, 감사로그를 유일한 견제로 남긴다.
 *
 * ⚠️ 무담보 한도는 **기존에 [관리] 이상이 만지던 값**이다(2026-08-10~08-21). 운영 heymanerp 에
 *    실제 2행(R.S.H·EASY DRIVE)이 있으므로 이 변경은 동작 변경이다.
 */
class BuyerLockAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission): User
    {
        return User::factory()->create([
            'permission' => $permission,
            'role' => $permission === 'user' ? '관리' : '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function buyer(array $attrs = []): Buyer
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);

        return Buyer::create(array_merge([
            'name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id,
        ], $attrs));
    }

    private function save(User $as, Buyer $b, array $set): void
    {
        $c = Volt::actingAs($as)->test('erp.buyers.index')->call('openEdit', $b->id);
        foreach ($set as $k => $v) {
            $c->set($k, $v);
        }
        $c->call('save');
    }

    // ── super 는 설정할 수 있다 ─────────────────────────────────

    public function test_super_can_set_the_lock_percentages(): void
    {
        $b = $this->buyer();

        $this->save($this->user('super'), $b, [
            'lock_shipping_entry_pct_str' => '85',
            'lock_purchase_registration_pct_str' => '40',
        ]);

        $b->refresh();
        $this->assertSame(85, (int) $b->lock_shipping_entry_pct);
        $this->assertSame(40, (int) $b->lock_purchase_registration_pct);
    }

    /** 🚨 '0' 은 「락 없음」이라는 유효값이다 — 빈칸(전역값)과 구분돼야 한다. */
    public function test_zero_is_stored_and_blank_clears_to_null(): void
    {
        $b = $this->buyer(['lock_shipping_entry_pct' => 70]);
        $super = $this->user('super');

        $this->save($super, $b, ['lock_shipping_entry_pct_str' => '0']);
        $this->assertSame(0, (int) $b->fresh()->lock_shipping_entry_pct,
            "'0' 이 null 로 저장되면 일부러 풀어준 바이어가 조용히 전역으로 돌아간다");

        $this->save($super, $b->fresh(), ['lock_shipping_entry_pct_str' => '']);
        $this->assertNull($b->fresh()->lock_shipping_entry_pct, '빈칸은 미설정(전역값)이어야 한다');
    }

    public function test_values_are_clamped_to_0_100(): void
    {
        $b = $this->buyer();
        $this->save($this->user('super'), $b, [
            'lock_shipping_entry_pct_str' => '150',
            'lock_purchase_registration_pct_str' => '-20',
        ]);

        $b->refresh();
        $this->assertSame(100, (int) $b->lock_shipping_entry_pct);
        $this->assertSame(0, (int) $b->lock_purchase_registration_pct);
    }

    // ── 관리·업무관리자는 못 바꾼다 ─────────────────────────────

    public function test_admin_cannot_change_the_lock_percentages(): void
    {
        $b = $this->buyer(['lock_shipping_entry_pct' => 70]);

        $this->save($this->user('admin'), $b, [
            'lock_shipping_entry_pct_str' => '10',
            'lock_purchase_registration_pct_str' => '10',
        ]);

        $b->refresh();
        $this->assertSame(70, (int) $b->lock_shipping_entry_pct, '최고관리자가 락을 낮출 수 있으면 견제가 무너진다');
        $this->assertNull($b->lock_purchase_registration_pct);
    }

    /** 무담보 한도도 super 로 올렸다 — 올리면 매입 판정이 미수율에서 금액으로 통째로 바뀌기 때문. */
    public function test_admin_can_no_longer_change_the_unsecured_limit(): void
    {
        $b = $this->buyer(['unsecured_limit_krw' => 5_000_000]);

        $this->save($this->user('admin'), $b, ['unsecured_limit_krw_str' => '99,000,000']);

        $this->assertSame(5_000_000, (int) $b->fresh()->unsecured_limit_krw);
    }

    public function test_super_can_still_change_the_unsecured_limit(): void
    {
        $b = $this->buyer(['unsecured_limit_krw' => 5_000_000]);

        $this->save($this->user('super'), $b, ['unsecured_limit_krw_str' => '9,000,000']);

        $this->assertSame(9_000_000, (int) $b->fresh()->unsecured_limit_krw);
    }

    // ── 감사 — 유일한 견제 수단 ────────────────────────────────

    public function test_lock_changes_are_audited(): void
    {
        $b = $this->buyer(['lock_shipping_entry_pct' => 60]);

        $this->save($this->user('super'), $b, ['lock_shipping_entry_pct_str' => '90']);

        $log = AuditLog::where('auditable_type', Buyer::class)
            ->where('auditable_id', $b->id)
            ->where('column_name', 'lock_shipping_entry_pct')
            ->latest('id')->first();

        $this->assertNotNull($log, '락 변경이 기록되지 않으면 super 전용 값은 아무도 모르게 바뀐다');
        $this->assertSame('60', (string) $log->old_value);
        $this->assertSame('90', (string) $log->new_value);
    }

    /** 0 ↔ null 은 뜻이 다르므로(락 없음 vs 전역값) 그 전환도 기록돼야 한다. */
    public function test_null_to_zero_is_audited(): void
    {
        $b = $this->buyer();   // null

        $this->save($this->user('super'), $b, ['lock_shipping_entry_pct_str' => '0']);

        $this->assertTrue(
            AuditLog::where('auditable_type', Buyer::class)
                ->where('auditable_id', $b->id)
                ->where('column_name', 'lock_shipping_entry_pct')->exists(),
            'null → 0 은 "전역 따름"에서 "락 없음"으로 바뀐 것이라 반드시 기록돼야 한다',
        );
    }

    // ── 화면 노출 ──────────────────────────────────────────────

    public function test_only_super_sees_the_lock_inputs(): void
    {
        $b = $this->buyer();

        Volt::actingAs($this->user('super'))->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->assertSee('lock_shipping_entry_pct_str');

        Volt::actingAs($this->user('admin'))->test('erp.buyers.index')
            ->call('openEdit', $b->id)
            ->assertDontSee('lock_shipping_entry_pct_str');
    }

    /** 감사로그 화면은 DB 값을 그대로 찍는다 — 한글 라벨이 없으면 영문 컬럼명이 노출된다(SKILLS §8 #41). */
    public function test_new_columns_have_korean_labels(): void
    {
        $labels = config('column_labels.buyers', []);

        foreach (['lock_shipping_entry_pct', 'lock_purchase_registration_pct'] as $column) {
            $this->assertArrayHasKey($column, $labels, "{$column} 한글 라벨 누락 — 감사로그에 영문이 그대로 뜬다");
            $this->assertMatchesRegularExpression('/[가-힣]/', $labels[$column]);
        }
    }
}
