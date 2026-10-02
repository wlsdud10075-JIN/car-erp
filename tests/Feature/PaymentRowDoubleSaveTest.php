<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔁 **옛 폼 저장 거부 + 같은 폼 안 동일 잔금 행 방어** (jin 2026-10-02 «잔금 처리할 때 이중저장되는지 확인해줄래?»).
 *
 * 운영 실측(ssancarerp, 10-02): 판매잔금 3건 · 매입잔금 2건이 같은 차량·금액·날짜로 2행 — 전부 적재 이후 사용자 시기.
 *   - 16머0394: u=3 이 잔금을 넣고 68초 뒤 u=10 이 **옛 폼**으로 저장 → 같은 5,200 행이 또 들어가고 컨테이너 번호·면장번호가 빈값으로 되돌아감.
 *   - 58저0778: 같은 사용자의 요청 2개가 1초 차로 겹침(두 창) → 같은 모양.
 *   - 매입잔금 2건: 신규 등록 1요청에 같은 행 2개(원인 미확정 — 폼에 같은 행이 둘).
 * 재현(고치기 전): 두 인스턴스가 각자 새 행을 저장하면 판매잔금은 **2행**, 매입잔금은 **앞사람 행이 지워지고** 뒷사람 행만 남았다.
 *
 * 가드 = ① Vehicle::editFingerprint() 대조(StaleFormException → 롤백·안내·재로드) ② 새 행 중 금액·날짜·메모 동일 쌍 거부.
 */
class PaymentRowDoubleSaveTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'buyer_id' => $buyer->id, 'export_buyer_id' => $buyer->id, 'bl_buyer_id' => $buyer->id,
            'vehicle_number' => '58저0778', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 20_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => 8_711, 'sale_date' => '2026-09-10',
        ]);

        return [$v, $admin];
    }

    /** 패널을 열고 새 잔금 행 하나를 채운 인스턴스. */
    private function openWithNewRow(Vehicle $v, string $prop, string $addMethod, string $amount, string $date, string $note = '')
    {
        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call($addMethod);
        $rows = $c->get($prop);
        $idx = array_key_last($rows);
        $rows[$idx]['amount'] = $amount;
        $rows[$idx]['payment_date'] = $date;
        $rows[$idx]['note'] = $note;

        return $c->set($prop, $rows)->set('userConfirmedDocCheckMismatch', true);
    }

    /** 🚫 옛 폼의 두 번째 저장은 거부되고(잔금 1행), 패널은 최신 내용으로 다시 열린다. */
    public function test_stale_second_save_of_a_final_payment_is_rejected_and_reloaded(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $a = $this->openWithNewRow($v, 'finalPayments', 'addFinalPayment', '8711', '2026-09-22');
        $b = $this->openWithNewRow($v, 'finalPayments', 'addFinalPayment', '8711', '2026-09-22');
        $a->call('save')->assertHasNoErrors();
        $b->call('save')->assertDispatched('notify');

        $rows = FinalPayment::where('vehicle_id', $v->id)->where('amount', 8711)->get();
        $this->assertCount(1, $rows, '옛 폼의 두 번째 저장이 같은 잔금을 또 넣었다');
        $ids = collect($b->get('finalPayments'))->pluck('id')->filter()->all();
        $this->assertContains($rows->first()->id, $ids, '거부 뒤 패널이 앞사람 저장분을 보여줘야 한다');
    }

    /** 🚫 매입잔금 — 옛 폼의 두 번째 저장이 앞사람 행을 지우지 못한다. */
    public function test_stale_second_save_cannot_delete_the_first_purchase_payment_row(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $a = $this->openWithNewRow($v, 'purchaseBalancePayments', 'addPurchasePayment', '19200000', '2026-09-23', '앞사람');
        $b = $this->openWithNewRow($v, 'purchaseBalancePayments', 'addPurchasePayment', '19200000', '2026-09-23', '뒷사람');
        $a->call('save')->assertHasNoErrors();
        $firstId = PurchaseBalancePayment::where('vehicle_id', $v->id)->value('id');
        $b->call('save')->assertDispatched('notify');

        $rows = PurchaseBalancePayment::where('vehicle_id', $v->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, $rows->first()->id, '앞사람 행이 지워지고 뒷사람 행으로 바뀌었다');
        $this->assertSame('앞사람', $rows->first()->note);
    }

    /** 🚫 같은 폼 안에 금액·날짜·메모가 같은 새 행이 둘이면 저장 자체가 거부된다 (신규 등록 매입잔금 2건 유형). */
    public function test_identical_new_rows_in_one_form_are_rejected(): void
    {
        [, $admin] = $this->fixture();
        $this->actingAs($admin);
        $sm = Salesman::first();

        $c = Volt::test('erp.vehicles.index')->call('openCreate')
            ->set('vehicle_number', '32라8085')->set('salesman_id_str', (string) $sm->id)->set('buyer_undecided', true)
            ->set('purchase_price_str', '20000000')->set('purchase_date', '2026-09-23')
            ->call('addPurchasePayment')->call('addPurchasePayment');
        $rows = $c->get('purchaseBalancePayments');
        foreach (array_slice(array_keys($rows), -2) as $idx) {
            $rows[$idx]['amount'] = '19200000';
            $rows[$idx]['payment_date'] = '2026-09-23';
        }
        $c->set('purchaseBalancePayments', $rows)->set('userConfirmedDocCheckMismatch', true)
            ->call('save')->assertHasErrors('purchaseBalancePayments');

        $this->assertNull(Vehicle::where('vehicle_number', '32라8085')->first(), '거부됐으면 차량도 안 만들어진다');

        // 메모로 구분하면 정당한 두 행 — 통과
        $rows[array_key_last($rows)]['note'] = '2차';
        $c->set('purchaseBalancePayments', $rows)->call('save')->assertHasNoErrors();
        $v = Vehicle::where('vehicle_number', '32라8085')->first();
        $this->assertSame(2, PurchaseBalancePayment::where('vehicle_id', $v->id)->count());
    }

    /** ✅ 오탐 없음 — 저장하고 계속 → 다시 수정 → 저장, 그리고 야간 캐시 재계산(raw update) 뒤 저장은 모두 통과. */
    public function test_normal_sequences_are_not_mistaken_for_stale_forms(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $c = $this->openWithNewRow($v, 'finalPayments', 'addFinalPayment', '1000', '2026-09-20');
        $c->call('saveAndContinue', 'basic')->assertHasNoErrors();
        $this->assertSame(1, FinalPayment::where('vehicle_id', $v->id)->count());

        // 같은 패널에서 이어서 수정·저장 — 지문이 재로드로 갱신돼 있어야 한다
        $c->set('transport_fee_str', '500')->call('saveAndContinue', 'basic')->assertHasNoErrors();
        $this->assertSame(500.0, (float) $v->fresh()->transport_fee);
        $this->assertSame(1, FinalPayment::where('vehicle_id', $v->id)->count(), '같은 행이 또 들어가면 안 된다');

        // 열어 둔 사이 야간 배치가 캐시를 raw 로 갱신해도(updated_at 무변경) 옛 폼으로 보지 않는다
        $d = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $v->fresh()->refreshCaches();
        $d->set('transport_fee_str', '600')->set('userConfirmedDocCheckMismatch', true)->call('save')->assertHasNoErrors();
        $this->assertSame(600.0, (float) $v->fresh()->transport_fee);
    }

    /** 신규 등록을 같은 인스턴스에서 두 번 save 해도 1행 (재로드가 id 를 채운다) — 종전 동작 보존. */
    public function test_double_save_in_one_instance_on_create(): void
    {
        [, $admin] = $this->fixture();
        $this->actingAs($admin);
        $sm = Salesman::first();

        $c = Volt::test('erp.vehicles.index')->call('openCreate')
            ->set('vehicle_number', '32라8085')->set('salesman_id_str', (string) $sm->id)->set('buyer_undecided', true)
            ->set('purchase_price_str', '20000000')->set('purchase_date', '2026-09-23')
            ->call('addPurchasePayment');
        $rows = $c->get('purchaseBalancePayments');
        $idx = array_key_last($rows);
        $rows[$idx]['amount'] = '19200000';
        $rows[$idx]['payment_date'] = '2026-09-23';
        $c->set('purchaseBalancePayments', $rows)->set('userConfirmedDocCheckMismatch', true)->call('save')->assertHasNoErrors();
        $c->instance()->save();

        $v = Vehicle::where('vehicle_number', '32라8085')->first();
        $this->assertSame(1, PurchaseBalancePayment::where('vehicle_id', $v->id)->where('amount', 19_200_000)->count());
    }

    /** 두 창이 같은 차량번호로 신규 등록 — 차량번호 유일 가드가 두 번째를 막는다(종전 동작 보존). */
    public function test_two_tabs_creating_the_same_vehicle(): void
    {
        [, $admin] = $this->fixture();
        $this->actingAs($admin);
        $sm = Salesman::first();
        $mk = function () use ($sm) {
            $c = Volt::test('erp.vehicles.index')->call('openCreate')
                ->set('vehicle_number', '151어6415')->set('salesman_id_str', (string) $sm->id)->set('buyer_undecided', true)
                ->set('purchase_price_str', '5000000')->set('purchase_date', '2026-09-11')
                ->call('addPurchasePayment');
            $rows = $c->get('purchaseBalancePayments');
            $idx = array_key_last($rows);
            $rows[$idx]['amount'] = '1000000';
            $rows[$idx]['payment_date'] = '2026-09-11';

            return $c->set('purchaseBalancePayments', $rows)->set('userConfirmedDocCheckMismatch', true);
        };
        $a = $mk();
        $b = $mk();
        $a->call('save')->assertHasNoErrors();
        $b->call('save')->assertHasErrors();

        $vs = Vehicle::withTrashed()->where('vehicle_number', '151어6415')->get();
        $this->assertCount(1, $vs);
        $this->assertSame(1, PurchaseBalancePayment::whereIn('vehicle_id', $vs->pluck('id'))->where('amount', 1_000_000)->count());
    }
}
