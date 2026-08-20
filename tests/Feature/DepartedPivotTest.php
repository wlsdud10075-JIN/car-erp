<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkReceivableStatus;
use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 「이미 떠났나」 판정 단일 출처 (jin 2026-08-20).
 *
 * 사고: 2026-07-18 에 채권 선적전/후 pivot 을 출고일로 통일했는데, 5일 뒤 `cfd17f6` 에서
 *   "거래완료면 출고일 미입력이어도 재고 아님" 으로 재고 규칙만 바꾸고 채권을 안 고쳤다.
 *   ⇒ B/L 이 먼저 나온 차는 재고 화면에서 사라져 **출고일을 찍을 경로가 없어졌고**
 *      (실측 heymanerp 92대), 그 차들이 이미 떠났는데 「선적전 미수」로 남았다(11대 881만원).
 *   jin: "출고일은 재고관리에서밖에 못 찍는데 거기에 안 나온다니까?"
 *
 * 해결 2단 — ① B/L 발급(거래완료) 시 출고일을 선적일로 자동 채움 ② 판정 자체를 「출고일 또는 B/L」로.
 */
class DepartedPivotTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function sold(string $number, int $salePrice, int $paid = 0): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B-'.$number, 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export',
            'sale_price' => $salePrice,
            'sale_date' => now()->subMonths(3)->toDateString(),
            'purchase_date' => now()->subMonths(4)->toDateString(),
            'buyer_id' => $buyer->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
        ]);
        if ($paid > 0) {
            $v->finalPayments()->create([
                'amount' => $paid, 'type' => 'balance',
                'payment_date' => now()->subMonths(2)->toDateString(), 'confirmed_at' => now(),
            ]);
        }

        return $v->fresh();
    }

    /** 🔒 B/L 이 나오면 출고일이 선적일로 자동 채워진다 — 사람이 찍을 화면이 없어도 비지 않는다. */
    public function test_bl_upload_fills_warehouse_out_date_from_shipping_date(): void
    {
        $v = $this->sold('11가1111', 10_000_000);
        $v->update(['shipping_date' => now()->subDays(20)->toDateString()]);
        $this->assertNull($v->fresh()->warehouse_out_date, '아직 B/L 전이라 비어 있어야 한다');

        $v->update(['bl_document' => 'bl/abc.pdf']);

        $v = $v->fresh();
        $this->assertSame('거래완료', $v->progress_status);
        $this->assertSame(
            now()->subDays(20)->format('Y-m-d'),
            $v->warehouse_out_date?->format('Y-m-d'),
            'B/L 발급 시 출고일이 선적일로 자동 채워져야 한다'
        );
    }

    /** ⚠️ 사람이 넣은 출고일을 저장할 때마다 덮으면 안 된다(SKILLS §8 #38 도장류 규칙). */
    public function test_existing_warehouse_out_date_is_never_overwritten(): void
    {
        $v = $this->sold('22가2222', 10_000_000);
        $v->update([
            'shipping_date' => now()->subDays(20)->toDateString(),
            'warehouse_out_date' => now()->subDays(30)->toDateString(),   // 실무자가 정확한 날짜를 넣음
            'bl_document' => 'bl/abc.pdf',
        ]);

        $v->update(['vessel_name' => 'MV TEST']);   // 무관한 저장

        $this->assertSame(
            now()->subDays(30)->format('Y-m-d'),
            $v->fresh()->warehouse_out_date?->format('Y-m-d'),
            '사람이 넣은 출고일이 선적일로 덮였다'
        );
    }

    /** 선적일이 없으면 자동으로 못 채운다 — 그 경우를 위해 판정에 B/L 안전망이 남아 있어야 한다. */
    public function test_departed_scope_catches_bl_without_warehouse_out_date(): void
    {
        $v = $this->sold('33가3333', 10_000_000);
        // 선적일 없이 B/L 만 — 자동 채움이 안 걸린다
        $v->update(['bl_document' => 'bl/x.pdf']);
        $this->assertNull($v->fresh()->warehouse_out_date, '선적일이 없어 자동 채움이 안 된 상태');

        $this->assertSame(1, Vehicle::query()->departed()->count(), 'B/L 만 있어도 떠난 것으로 봐야 한다');
        $this->assertSame(0, Vehicle::query()->notDeparted()->count());
    }

    /**
     * 🔑 핵심 — 이미 떠난 차의 미수는 **선적후**로 잡힌다. 화면·대시보드·알림톡 세 곳 모두.
     * 예전엔 출고일만 봐서 「선적전 미수」로 남았다.
     */
    public function test_departed_unpaid_counts_as_after_shipping_everywhere(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold('44가4444', 10_000_000, 2_000_000);   // 미수 800만
        // ⚠️ updateQuietly — 미완납 차의 B/L 발행은 G1 게이트가 막는다(정상 규칙).
        //    운영엔 우회 승인·게이트 도입 전 데이터로 이런 차가 실재한다(heymanerp 11대) → 그 상태를 재현한다.
        $v->updateQuietly(['bl_document' => 'bl/y.pdf']);       // 선적일 없이 B/L 만
        $v->refreshCaches();

        // ① scope
        $this->assertSame(0, Vehicle::query()->action('receivable_before_shipping')->count());
        $this->assertSame(1, Vehicle::query()->action('receivable_after_shipping')->count());

        // ② 채권관리 화면 탭
        $counts = Volt::test('erp.receivables.index')->instance()->classificationCounts;
        $this->assertSame(0, $counts['before_shipping'], '화면이 옛 판정(출고일만)을 쓰고 있다');
        $this->assertSame(1, $counts['after_shipping']);

        // ③ 관리자 대시보드
        $cls = Volt::test('admin.dashboard')->instance()->receivableKpis()['classification'];
        $this->assertSame(0, $cls['before_shipping']['count'], '대시보드가 옛 판정을 쓰고 있다');
        $this->assertSame(1, $cls['after_shipping']['count']);
        $this->assertSame(8_000_000, $cls['after_shipping']['unpaid']);
    }

    /** 떠난 차는 유예(결제대기) 대상이 아니다 — 출항했으면 즉시 채권. */
    public function test_departed_vehicle_is_never_in_grace(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold('55가5555', 10_000_000);
        // updateQuietly — 미완납 B/L 은 G1 게이트가 막지만, 운영엔 그런 차가 실재한다(위 주석 참조).
        $v->updateQuietly(['sale_date' => now()->toDateString(), 'bl_document' => 'bl/z.pdf']);
        $v->refreshCaches();

        $this->assertSame(0, Vehicle::query()->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->onlyReceivableGrace()->count(), '떠난 차가 결제대기로 잡혔다');
        $this->assertSame(1, Vehicle::query()->action('receivable_after_shipping')->count());
    }

    /** backfill 커맨드 — 기존 데이터 1회 보정. dry-run 은 아무것도 안 바꾼다. */
    public function test_backfill_command_fills_only_completed_vehicles(): void
    {
        $done = $this->sold('66가6666', 10_000_000);
        $done->updateQuietly([
            'shipping_date' => now()->subDays(15)->toDateString(),
            'bl_document' => 'bl/done.pdf',
            'warehouse_out_date' => null,
        ]);
        $notYet = $this->sold('77가7777', 10_000_000);
        $notYet->updateQuietly(['shipping_date' => now()->subDays(10)->toDateString()]);   // B/L 없음

        $this->artisan('vehicles:backfill-warehouse-out-date --dry-run')->assertSuccessful();
        $this->assertNull($done->fresh()->warehouse_out_date, 'dry-run 이 데이터를 바꿨다');

        $this->artisan('vehicles:backfill-warehouse-out-date')->assertSuccessful();
        $this->assertSame(
            now()->subDays(15)->format('Y-m-d'),
            $done->fresh()->warehouse_out_date?->format('Y-m-d')
        );
        $this->assertNull($notYet->fresh()->warehouse_out_date, '거래완료가 아닌 차까지 채웠다');
    }

    /** 채권 현황 알림톡도 같은 판정을 쓴다 — 금액이 정확히 닫혀야 한다. */
    public function test_receivable_status_alimtalk_balances(): void
    {
        $a = $this->sold('81가1111', 10_000_000, 4_000_000);   // 미수 600만, 아직 안 떠남
        $a->refreshCaches();
        $b = $this->sold('82가2222', 20_000_000, 5_000_000);   // 미수 1,500만, 떠남
        $b->updateQuietly(['bl_document' => 'bl/b.pdf']);       // G1 게이트 우회 — 위 주석 참조
        $b->refreshCaches();

        $vars = AlimtalkReceivableStatus::buildVars();

        $this->assertSame('2', $vars['대상대수']);
        $this->assertSame('30,000,000원', $vars['총판매금액']);
        $this->assertStringStartsWith('6,000,000원', $vars['선적전금액']);
        $this->assertStringStartsWith('15,000,000원', $vars['선적후금액']);
        $this->assertStringStartsWith('9,000,000원', $vars['입금액']);
        $this->assertSame('21,000,000원', $vars['미수합계']);
        // 🚫 요약칸은 금액만 — % 가 들어가면 K140 반려(SKILLS §8 #40).
        $this->assertStringNotContainsString('%', $vars['미수합계']);

        // 유예 + 선적전 + 선적후 + 입금 = 총판매금액 (분모가 하나라서 닫힌다)
        $n = fn (string $s): int => (int) str_replace([',', '원'], '', explode(' ', $s)[0]);
        $this->assertSame(
            $n($vars['총판매금액']),
            $n($vars['유예금액']) + $n($vars['선적전금액']) + $n($vars['선적후금액']) + $n($vars['입금액']),
            '네 항목의 합이 총 판매금액과 다르다 — 분모가 하나라는 전제가 깨졌다'
        );
    }
}
