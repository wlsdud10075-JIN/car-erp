<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\ReceivableHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 판매 입금·매입 지급 줄의 감사 기록 (jin 2026-10-23 «계약금·매입잔금·판매잔금 기록이 로그에 없다»).
 *
 * 지키는 것: ①차량 패널에서 줄 추가·금액 수정·삭제가 남는다 — 패널은 bulk 쿼리라 모델 훅을 안 타서 원래 0건이었다
 * ②미확정 줄 수정도 남는다 ③재무 확정이 남는다 ④같은 값 재저장은 안 남는다 ⑤로그 화면에서 한글로 읽힌다.
 */
class PaymentRowAuditTest extends TestCase
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

    private function logs(string $class, int $id)
    {
        return AuditLog::where('auditable_type', $class)->where('auditable_id', $id)->orderBy('id')->get();
    }

    public function test_panel_add_edit_delete_of_a_sale_payment_is_logged(): void
    {
        $v = $this->vehicle();
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('finalPayments', [['id' => null, 'amount' => '3,000', 'exchange_rate' => '1400', 'payment_date' => '2026-10-05', 'note' => '']])
            ->call('save');
        $fp = FinalPayment::where('vehicle_id', $v->id)->firstOrFail();
        $created = $this->logs(FinalPayment::class, $fp->id);
        $this->assertSame(['created', 'payment_confirmed'], $created->pluck('action')->all(), '관리자 직접 추가 = 즉시 확정까지 남는다');
        $this->assertStringContainsString('3,000 USD', $created->first()->new_value);

        // 저장된 판매 입금 줄은 채권 미러가 붙어 패널에선 잠긴다 — 정정·삭제는 재무처리(모델 경로)에서 한다.
        $fp->update(['amount' => 2_500]);
        $edit = AuditLog::where('auditable_id', $fp->id)->where('auditable_type', FinalPayment::class)->where('column_name', 'amount')->firstOrFail();
        $this->assertEqualsWithDelta([3000, 2500], [(float) $edit->old_value, (float) $edit->new_value], 0.001);

        $fp->fresh()->update(['amount' => 2_500]);   // 같은 값
        $this->assertSame(1, AuditLog::where('auditable_id', $fp->id)->where('auditable_type', FinalPayment::class)->where('column_name', 'amount')->count(), '같은 값 재저장은 안 남는다');

        ReceivableHistory::where('final_payment_id', $fp->id)->get()->each->delete();   // 미러를 지우면 짝 줄이 같이 지워진다
        $del = AuditLog::where('auditable_id', $fp->id)->where('auditable_type', FinalPayment::class)->where('action', 'deleted')->firstOrFail();
        $this->assertStringContainsString('2,500 USD', $del->old_value, '삭제 줄에 무엇이 지워졌는지 남는다');
    }

    public function test_panel_purchase_down_payment_and_balance_rows_are_logged(): void
    {
        $v = $this->vehicle();
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('down_payment_str', '1,000,000')
            ->set('purchaseBalancePayments', [['id' => null, 'amount' => '4,000,000', 'payment_date' => '2026-10-06', 'note' => '']])
            ->call('save');
        $down = PurchaseBalancePayment::where('vehicle_id', $v->id)->where('type', 'down')->firstOrFail();
        $this->assertStringContainsString('계약금 1,000,000원', $this->logs(PurchaseBalancePayment::class, $down->id)->first()->new_value);
        $bal = PurchaseBalancePayment::where('vehicle_id', $v->id)->where('amount', 4_000_000)->firstOrFail();
        $this->assertSame('created', $this->logs(PurchaseBalancePayment::class, $bal->id)->first()->action);

        // 계약금 금액을 바꾸면 패널은 옛 줄을 지우고 새 줄을 만든다 — 둘 다 남는다
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('down_payment_str', '1,500,000')->call('save');
        $this->assertSame(1, AuditLog::where('auditable_type', PurchaseBalancePayment::class)->where('auditable_id', $down->id)->where('action', 'deleted')->count());

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('save');
        $idx = collect(Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->get('purchaseBalancePayments'))->search(fn ($r) => (int) $r['id'] === $bal->id);
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set("purchaseBalancePayments.{$idx}.payment_date", '2026-10-07')->call('save');
        $d = AuditLog::where('auditable_type', PurchaseBalancePayment::class)->where('auditable_id', $bal->id)->where('column_name', 'payment_date')->firstOrFail();
        $this->assertSame(['2026-10-06', '2026-10-07'], [$d->old_value, $d->new_value]);
    }

    public function test_unconfirmed_edit_and_confirmation_via_model_are_logged(): void
    {
        $v = $this->vehicle();
        $pbp = $v->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 5_000_000, 'payment_date' => '2026-10-02']);
        $pbp->update(['amount' => 4_000_000]);
        $pbp->update(['confirmed_at' => now()]);

        $actions = $this->logs(PurchaseBalancePayment::class, $pbp->id)->pluck('action')->all();
        $this->assertSame(['created', 'updated', 'payment_confirmed'], $actions);
    }

    public function test_labels_read_in_korean(): void
    {
        $this->assertSame('판매 입금', ColumnLabel::model(FinalPayment::class));
        $this->assertSame('매입 지급', ColumnLabel::model(PurchaseBalancePayment::class));
        $this->assertSame('매입 지급 줄', ColumnLabel::column(PurchaseBalancePayment::class, 'payment_row'));
        $this->assertSame('재무 확정', ColumnLabel::action('payment_confirmed'));
        $this->assertSame('급여대장 (2026-09)', ColumnLabel::column('Salesman', 'payroll_2026-09'));
        $this->assertSame('매도비', ColumnLabel::value(PurchaseBalancePayment::class, 'type', 'selling_fee'));
    }

    /**
     * 🧭 코드가 감사로그에 쓰는 액션은 전부 한글 라벨이 있어야 한다 — 없으면 로그 표와 「액션」 드롭박스에 영문이 뜬다
     * (§8 #41 재발 — 2026-10-23 점검에서 11개가 영문이었다). 정적 검사라 아직 운영에 안 찍힌 것까지 잡는다.
     */
    public function test_every_action_written_in_code_has_a_korean_label(): void
    {
        $files = array_merge(
            glob(app_path('{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE),
            glob(resource_path('views/livewire/{,*/,*/*/,*/*/*/}*.blade.php'), GLOB_BRACE),
        );
        $missing = [];
        foreach ($files as $f) {
            $s = file_get_contents($f);
            if (! str_contains($s, 'AuditLog')) {
                continue;
            }
            preg_match_all("/'action'\\s*=>\\s*'([a-z0-9_]+)'/", $s, $m1);
            preg_match_all("/recordEvent\\([^,]+,\\s*'([a-z0-9_]+)'\\)/", $s, $m2);
            preg_match_all("/recordChange\\([^;]*?,\\s*'([a-z0-9_]+)'\\)\\s*;/s", $s, $m3);
            foreach (array_merge($m1[1], $m2[1], $m3[1]) as $a) {
                if (ColumnLabel::action($a) === $a) {
                    $missing[$a] = basename($f);
                }
            }
        }
        $this->assertSame([], $missing, '한글 라벨 없는 감사 액션 — config/column_labels.php actions 에 추가할 것');
    }
}
