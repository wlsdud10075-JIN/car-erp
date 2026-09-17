<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🧹 **감사로그는 「실제로 바뀐 것」만 남는다** (jin 2026-09-17).
 *
 * jin: *「바꾸지 않았는데도 그 값 그대로 쓰는데 기록이 누적되면 너무 많은 기록이 쌓일 것 같고,
 * 찾기도 쉽지가 않아.」* — 실측으로 확인됐다.
 *
 * 📏 **운영 실측 (heymanerp, 고치기 전)**: 감사 32,245행 중 **26,727행(82.9%)** 이
 *    「수치는 같은데 표기만 다른」 기록. 한 번 저장에 9행씩:
 *    `sale_price [12100.00]→[12100]` · `tax_dc [0.00]→[0]` · `exchange_rate [1621.0000]→[1621]`
 *
 * 🔑 원인 = `wasChanged()` 가 마지막에 **`strcmp`** 로 비교한다. DB `decimal(15,2)` 는 `"12100.00"`
 *    문자열로 읽히고 화면은 `12100`(숫자)을 넣는다 ⇒ 다르다고 본다. 이 레포는 같은 함정을 이미 겪어
 *    `Vehicle::guardLedgerLockOnSaving()` 에 정밀 비교를 넣어 뒀는데 **감사 훅만 안 따라왔다**.
 *
 * 🚫 기존 행은 지우지 않는다 — 감사 기록이다. 새로 안 쌓이게만 한다.
 */
class AuditNoiseTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicle(): Vehicle
    {
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1621,
            'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000, 'purchase_date' => '2026-08-01',
            'sale_price' => 12_100, 'sale_date' => '2026-09-01',
        ]);
    }

    /**
     * 🔧 **MySQL 이 돌려주는 모양을 강제로 만든다** — 이게 없으면 이 테스트는 **아무것도 안 지킨다**.
     *
     * 운영 MySQL 은 `decimal(15,2)` 를 `"12100.00"` **문자열**로 주는데 **테스트 SQLite 는 안 그런다**
     * (§8 #36 의 드라이버 차이). 그래서 그냥 재저장하면 증상이 **재현되지 않아** 고치기 전에도 초록이다
     * — 실제로 그렇게 헛통과했고, `AuditLog::isRealChange` 를 옛 `wasChanged` 로 되돌려도 5건 전부
     * 초록이었다(§8 #73). 원본 속성에 그 문자열을 심어 두고 동기화해서 드라이버와 무관하게 재현한다.
     */
    private function withMysqlDecimalStrings(object $model, array $raw): object
    {
        $model->setRawAttributes(array_merge($model->getAttributes(), $raw), true);

        return $model;
    }

    /** 🚨 실사고 재현 — DB 가 돌려주는 `"12100.00"` 을 그대로 다시 넣어도 기록이 안 남아야 한다. */
    public function test_resaving_the_same_decimal_values_records_nothing(): void
    {
        $this->actingAs($this->actor());
        $v = $this->vehicle();
        AuditLog::query()->delete();   // 생성분 제외 — 이 테스트는 「재저장」만 본다

        $fresh = $this->withMysqlDecimalStrings(Vehicle::find($v->id), [
            'sale_price' => '12100.00', 'exchange_rate' => '1621.0000', 'tax_dc' => '0.00',
            'commission' => '0.00', 'transport_fee' => '0.00', 'auto_loading' => '0.00',
            'sale_other_costs' => '0.00', 'savings_used' => '0.00', 'export_declaration_amount' => '12100.00',
        ]);
        // 화면이 하는 일 그대로: 읽은 값을 float 로 되넣는다 (값은 안 바꾼다)
        foreach (['sale_price', 'exchange_rate', 'tax_dc', 'commission', 'transport_fee',
            'auto_loading', 'sale_other_costs', 'savings_used', 'export_declaration_amount'] as $col) {
            $fresh->{$col} = (float) $fresh->{$col};
        }
        $fresh->save();

        $rows = AuditLog::query()->get(['column_name', 'old_value', 'new_value']);
        $this->assertCount(0, $rows,
            '값을 안 바꿨는데 감사로그가 쌓였다: '.$rows->map(
                fn ($r) => "{$r->column_name} [{$r->old_value}]→[{$r->new_value}]"
            )->implode(' · '));
    }

    /** ✅ 진짜로 바꾸면 남는다 — 조용해진 게 아니라 **가짜만** 걸러졌다는 증거. */
    public function test_a_real_change_is_still_recorded(): void
    {
        $this->actingAs($this->actor());
        $v = $this->vehicle();
        AuditLog::query()->delete();

        $v->sale_price = 12_500;
        $v->save();

        $rows = AuditLog::query()->where('column_name', 'sale_price')->get();
        $this->assertCount(1, $rows, '진짜 변경이 안 남았다');
        // ⚠️ 표기는 드라이버마다 다르다 — MySQL 은 `12100.00`, SQLite 는 `12100`(§8 #36).
        //    그래서 **수치로** 비교한다. 이 테스트가 보는 것은 「남았나」와 「어느 값에서 어느 값으로」다.
        $this->assertEqualsWithDelta(12100, (float) $rows->first()->old_value, 0.001);
        $this->assertEqualsWithDelta(12500, (float) $rows->first()->new_value, 0.001);
    }

    /** 💴 **저장 가능한 최소 단위(0.01)의 변화는 반드시 남는다** — 오차 범위를 넓게 잡으면 돈이 조용히 바뀐다. */
    public function test_the_smallest_storable_change_is_not_swallowed(): void
    {
        $this->actingAs($this->actor());
        $v = $this->vehicle();
        AuditLog::query()->delete();

        $v->sale_price = 12_100.01;
        $v->save();

        $this->assertSame(1, AuditLog::query()->where('column_name', 'sale_price')->count(),
            '0.01 변화가 삼켜졌다 — 허용 오차가 너무 넓다');
    }

    /** 판정 자체의 경계 — 숫자·빈값·문자열. */
    public function test_the_predicate_itself(): void
    {
        // 표기만 다른 숫자 = 같다
        $this->assertFalse(AuditLog::valuesDiffer('12100.00', 12100));
        $this->assertFalse(AuditLog::valuesDiffer('0.00', 0));
        $this->assertFalse(AuditLog::valuesDiffer('1621.0000', 1621));
        // 빈 값끼리 = 같다
        $this->assertFalse(AuditLog::valuesDiffer(null, ''));
        $this->assertFalse(AuditLog::valuesDiffer('', null));
        // 진짜 차이 = 다르다
        $this->assertTrue(AuditLog::valuesDiffer('12100.00', 12100.01));
        $this->assertTrue(AuditLog::valuesDiffer(null, 0), 'null 과 0 은 구분해야 한다 — 미입력과 0 원은 다르다');
        $this->assertTrue(AuditLog::valuesDiffer('MV A', 'MV B'));
        $this->assertTrue(AuditLog::valuesDiffer('', 'MV B'));
    }

    /** 🧾 잔금·정산도 같은 판정을 쓴다 — 네 모델이 갈리면 한쪽에만 소음이 남는다(§8 #45). */
    public function test_payments_and_settlements_share_the_predicate(): void
    {
        $this->actingAs($this->actor());
        $v = $this->vehicle();

        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 12_100,
            'payment_date' => '2026-09-02', 'confirmed_at' => now(),
        ]);
        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 5_000_000,
            'payment_date' => '2026-08-02', 'confirmed_at' => now(),
        ]);
        $st = Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'pending',
        ]);
        AuditLog::query()->delete();

        // 같은 값 되넣기 — MySQL 표기를 강제로 만든 뒤 float 로
        $fp2 = $this->withMysqlDecimalStrings(FinalPayment::find($fp->id), ['amount' => '12100.00']);
        $fp2->amount = (float) $fp2->amount;
        $fp2->save();

        $pbp2 = $this->withMysqlDecimalStrings(PurchaseBalancePayment::find($pbp->id), ['amount' => '5000000.00']);
        $pbp2->amount = (float) $pbp2->amount;
        $pbp2->save();

        $st2 = $this->withMysqlDecimalStrings(Settlement::find($st->id), ['settlement_ratio' => '50.00']);
        $st2->settlement_ratio = (float) $st2->settlement_ratio;
        $st2->save();

        $rows = AuditLog::query()->get(['auditable_type', 'column_name', 'old_value', 'new_value']);
        $this->assertCount(0, $rows,
            '잔금·정산에 소음이 남았다: '.$rows->map(
                fn ($r) => class_basename($r->auditable_type).".{$r->column_name} [{$r->old_value}]→[{$r->new_value}]"
            )->implode(' · '));
    }
}
