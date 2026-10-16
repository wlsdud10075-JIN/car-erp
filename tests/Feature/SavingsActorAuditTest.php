<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 👤 적립금 거래의 처리자·감사로그 (jin 2026-10-16 «적립금 추가하고 취소한 기록이 있는데 로그엔 전혀 없고 누가 했는지도 안 나오네»).
 *
 * 실측 heymanerp: ALKEO 바이어 적립·취소·사용 12줄, 적립금 감사로그 0건, 처리자 칸 자체가 없었다.
 * 고침 = `savings_statuses.created_by` + `SavingsStatus::booted()`(creating 에 처리자, created 에 감사 이벤트) — 경로 다섯이 전부 모델을 지난다.
 */
class SavingsActorAuditTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'name' => '김재무', 'email_verified_at' => now()]);
    }

    /** 바이어탭 적립 → 취소: 두 줄 모두 처리자·감사 이벤트. 화면에 처리자 열. */
    public function test_buyer_tab_add_and_cancel_record_the_actor_and_audit_events(): void
    {
        $user = $this->finance();
        $buyer = Buyer::create(['name' => 'ACTOR BUYER', 'is_active' => true]);
        $this->actingAs($user);

        $c = Volt::test('erp.buyers.index')->call('openEdit', $buyer->id)
            ->set('txn_currency', 'EUR')->set('txn_type', 'EARNED')->set('txn_amount', '280')->set('txn_note', '테스트 적립')
            ->call('addSavingsTransaction')->assertHasNoErrors();

        $earned = SavingsStatus::where('buyer_id', $buyer->id)->first();
        $this->assertSame($user->id, (int) $earned->created_by, '적립 줄에 처리자');
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => SavingsStatus::class, 'auditable_id' => $earned->id, 'action' => 'savings_earned', 'column_name' => 'savings_earned', 'user_id' => $user->id, 'new_value' => 'EUR +280.00 → 잔액 280.00']);
        $this->assertStringContainsString('data-savings-by>김재무', $c->html(), '적립금 목록에 처리자 열');
        // 감사 화면 「컬럼」 드롭다운에 종류별로 뜬다(컬럼이 비면 액션 필터로만 찾을 수 있었다)
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        $cols = Volt::test('admin.audit-logs.index')->instance()->distinctColumns;
        $this->assertSame('적립금 적립', $cols['savings_earned'] ?? null);
        $this->actingAs($user);   // 취소는 다시 재무로

        $c->call('cancelSavingsTransaction', $earned->id)->assertHasNoErrors();
        $cancelled = SavingsStatus::where('buyer_id', $buyer->id)->where('transaction_type', 'CANCELLED')->first();
        $this->assertNotNull($cancelled);
        $this->assertSame($user->id, (int) $cancelled->created_by);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => SavingsStatus::class, 'auditable_id' => $cancelled->id, 'action' => 'savings_cancelled', 'user_id' => $user->id]);
    }

    /** 판매탭 자동 경로(H6 훅)도 모델을 지나므로 처리자·감사가 남는다. 로그인 없는 경로는 NULL(「시스템」). */
    public function test_automatic_usage_rows_carry_the_logged_in_actor_or_null(): void
    {
        $user = $this->finance();
        $buyer = Buyer::create(['name' => 'AUTO BUYER', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => 'SA-1', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500, 'buyer_id' => $buyer->id, 'sale_price' => 1000]);
        $v->syncSavingsDeposit(300);   // 로그인 없음 → 시스템
        $this->assertNull(SavingsStatus::where('buyer_id', $buyer->id)->value('created_by'));
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => SavingsStatus::class, 'action' => 'savings_earned', 'user_id' => null]);

        $this->actingAs($user);
        $v->syncSavingsUsage(100);   // USED
        $used = SavingsStatus::where('buyer_id', $buyer->id)->where('transaction_type', 'USED')->first();
        $this->assertSame($user->id, (int) $used->created_by);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => SavingsStatus::class, 'auditable_id' => $used->id, 'action' => 'savings_used', 'user_id' => $user->id]);
        $v->syncSavingsUsage(-100);   // REFUND
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => SavingsStatus::class, 'action' => 'savings_refund', 'user_id' => $user->id]);
    }

    /** 감사 화면 사전 — 새 이벤트 이름이 영문 키로 안 찍힌다(§8 #41). 현금 탭엔 처리자가 글자로 보인다. */
    public function test_audit_dictionary_and_cash_tab_show_the_actor(): void
    {
        foreach (['savings_earned', 'savings_used', 'savings_refund', 'savings_cancelled', 'savings_adjustment'] as $a) {
            $this->assertNotSame($a, ColumnLabel::action($a), "$a 사전 없음");
        }
        $this->assertSame('바이어 적립금', ColumnLabel::model(SavingsStatus::class));

        $user = $this->finance();
        $buyer = Buyer::create(['name' => 'CASH BUYER', 'is_active' => true]);
        Setting::updateOrCreate(['key' => 'buyer_cash_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);   // 현금 탭은 회사별 토글
        $this->actingAs($user);
        $c = Volt::test('erp.buyers.index')->call('openEdit', $buyer->id)
            ->set('cash_currency', 'EUR')->set('cash_amount', '500')->set('cash_date', '2026-10-16')->call('addCashReceipt');
        $html = $c->html();
        $this->assertMatchesRegularExpression('/data-cash-by>[^<]*김재무/u', $html, '현금 탭 입금 줄에 처리자가 글자로');
        $this->assertStringNotContainsString('title="'.__('buyer.cash.col_by').':', $html, '툴팁으로만 숨기던 방식은 끝');
    }
}
