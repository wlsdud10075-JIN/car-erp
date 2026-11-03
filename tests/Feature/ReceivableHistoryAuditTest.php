<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 로그 기록 누락 점검 (jin 2026-11-03 «판매잔금 금액은 로그에 나오는데 환율 기입된 건 안 나오더라»).
 *
 * ①판매 입금 줄 「생성」·「확정」 요약에 환율이 없었다(수정만 칸별로 남았다) → 「잔금 7,400 USD @1,450 · 날짜」.
 * ②채권관리 회수 줄 중 판매 잔금으로 안 미러되는 현금·상계·기타·손실은 감사 기록이 아예 없었다(ssancarerp 10일 13건 0).
 * ③채권관리에서 「입금」 회수를 고치면 연결 판매 잔금을 raw update 로 바꾸는데 그 변경이 안 남았다.
 */
class ReceivableHistoryAuditTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => 'A1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-10-01', 'sale_price' => 10_000, 'currency' => 'USD', 'exchange_rate' => 1400,
            'purchase_date' => '2026-10-01', 'purchase_price' => 10_000_000,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_sale_payment_created_log_shows_the_exchange_rate(): void
    {
        $v = $this->vehicle();
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('finalPayments', [['id' => null, 'amount' => '7,400', 'exchange_rate' => '1450.5', 'payment_date' => '2026-11-03', 'note' => '']])
            ->call('save');
        $fp = FinalPayment::where('vehicle_id', $v->id)->firstOrFail();
        $logs = AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fp->id)->pluck('new_value', 'action');

        $this->assertStringContainsString('7,400 USD @1,450.5', $logs['created']);
        $this->assertStringContainsString('@1,450.5', $logs['payment_confirmed'], '확정 줄에도 환율');
    }

    public function test_cash_offset_other_write_off_rows_are_logged(): void
    {
        $v = $this->vehicle();
        $admin = $this->admin();
        $this->actingAs($admin);

        $h = ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-11-03', 'collector_id' => $admin->id,
            'method' => 'cash', 'amount' => 500, 'exchange_rate' => 1400, 'note' => '현장 수령']);
        $created = AuditLog::where('auditable_type', ReceivableHistory::class)->where('auditable_id', $h->id)->where('action', 'created')->firstOrFail();
        $this->assertSame('history_row', $created->column_name);
        $this->assertStringContainsString('현금 500 USD @1,400', $created->new_value);
        $this->assertSame($admin->id, (int) $created->user_id, '누가 넣었나');

        $h->update(['amount' => 450, 'exchange_rate' => 1420]);
        $cols = AuditLog::where('auditable_type', ReceivableHistory::class)->where('auditable_id', $h->id)->where('action', 'updated')->pluck('column_name')->all();
        $this->assertEqualsCanonicalizing(['amount', 'exchange_rate'], $cols);

        $h->delete();
        $this->assertTrue(AuditLog::where('auditable_type', ReceivableHistory::class)->where('auditable_id', $h->id)->where('action', 'deleted')->exists());
    }

    /** 「입금」 회수 = 판매 잔금 줄이 기록을 맡는다(두 번 남기지 않는다) · 그 회수를 고치면 판매 잔금 쪽에 칸별로 남는다. */
    public function test_deposit_history_logs_through_the_sale_payment_including_edits(): void
    {
        $v = $this->vehicle();
        $admin = $this->admin();
        $this->actingAs($admin);

        $h = ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-11-03', 'collector_id' => $admin->id,
            'method' => 'deposit', 'amount' => 1000, 'exchange_rate' => 1400]);
        $this->assertFalse(AuditLog::where('auditable_type', ReceivableHistory::class)->where('auditable_id', $h->id)->exists(), '입금은 판매 잔금 줄이 남긴다');
        $fpId = (int) $h->fresh()->final_payment_id;
        $this->assertTrue(AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fpId)->where('action', 'created')->exists());

        $h->fresh()->update(['amount' => 900, 'exchange_rate' => 1430]);
        $cols = AuditLog::where('auditable_type', FinalPayment::class)->where('auditable_id', $fpId)->where('action', 'updated')->pluck('column_name')->all();
        $this->assertContains('amount', $cols, '채권관리에서 고친 금액이 판매 잔금 기록에 남는다');
        $this->assertContains('exchange_rate', $cols, '채권관리에서 고친 환율도');
    }
}
