<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🚢 **「이미 떠났나」 판정을 넓혔다** (jin 2026-09-14).
 *
 * 그 전 규칙은 「출고일 **또는** B/L 파일」뿐이었다. 그런데
 *   - 출고일은 **거래완료가 되어야** 자동으로 찍히고
 *   - 거래완료는 **B/L 파일**을 요구한다
 * ⇒ **두 문이 결국 같은 하나에 물려 있어서**, 파일을 안 올리는 회사에선 선적후 미수가
 *    **구조적으로 영원히 0** 이었다(실측 ssancarerp 미수 459대 중 0 · karabaerp 0).
 *
 * 새 규칙 = 출고일 · B/L 파일 · **B/L 발행일(과거)** · **선적일(과거)** 중 하나라도.
 * 🚫 `bl_number` 는 **일부러 안 쓴다** — 자유 입력칸이라 빈 문자열·`-`·「인천항에 있음」 같은
 *    한글 메모가 실제로 들어 있다(ssancarerp 실측 237 중 120이 빈 문자열). 날짜 컬럼만 쓴다.
 *
 * ⚠️ **순수 확대여야 한다** — 전에 선적후였던 차가 선적전으로 가면 채권 유예·알림톡 대상이
 *    조용히 줄어든다(SKILLS §8 #55 의 그 사고: 「넓히랬는데 좁아지는」 배포).
 */
class DepartedPivotWideningTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function vehicle(array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.str_pad((string) (1000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1400,
            'dhl_request' => false, 'buyer_id' => $b->id, 'salesman_id' => $s->id,
            'sale_price' => 10000, 'sale_date' => '2026-09-01',
        ], $attrs));
    }

    /** 넷 중 하나라도 있으면 떠난 것으로 본다. */
    public function test_each_new_signal_marks_the_vehicle_as_departed(): void
    {
        $cases = [
            '출고일' => ['warehouse_out_date' => '2026-09-01'],
            'B/L 파일' => ['bl_document' => 'vehicles/bl.pdf'],
            'B/L 발행일' => ['bl_issue_date' => '2026-09-01'],
            '선적일(과거)' => ['shipping_date' => '2026-09-01'],
        ];

        foreach ($cases as $label => $attrs) {
            $v = $this->vehicle($attrs);

            $this->assertTrue(Vehicle::query()->departed()->whereKey($v->id)->exists(),
                "{$label} 만 있는 차가 선적후로 안 잡힌다");
            $this->assertTrue($v->fresh()->isDeparted(), "{$label} — 인스턴스 판정이 스코프와 다르다");
            $this->assertFalse(Vehicle::query()->notDeparted()->whereKey($v->id)->exists(),
                "{$label} — notDeparted 가 여집합이 아니다");
        }
    }

    /** 🚫 **미래 선적일은 아직 안 떠난 것** — 배를 미리 잡아 둔 차가 오늘 선적후가 되면 안 된다. */
    public function test_a_future_shipping_date_does_not_count_as_departed(): void
    {
        $v = $this->vehicle(['shipping_date' => now()->addDays(7)->toDateString()]);

        $this->assertFalse(Vehicle::query()->departed()->whereKey($v->id)->exists(),
            '다음 주에 실을 차가 벌써 선적후로 잡힌다');
        $this->assertFalse($v->fresh()->isDeparted());
        $this->assertTrue(Vehicle::query()->notDeparted()->whereKey($v->id)->exists());
    }

    /** 오늘 선적한 차는 떠난 것이다 — 날짜 경계(00:00:00 저장)에 걸려 놓치면 안 된다. */
    public function test_today_counts_as_departed(): void
    {
        $v = $this->vehicle(['shipping_date' => now()->toDateString()]);

        $this->assertTrue(Vehicle::query()->departed()->whereKey($v->id)->exists(),
            '오늘 선적한 차를 놓쳤다 — 날짜 경계 비교를 확인할 것');
        $this->assertTrue($v->fresh()->isDeparted());
    }

    /**
     * 🚨 **순수 확대** — 옛 규칙(출고일 또는 B/L 파일)이 뽑던 차는 **한 대도 빠지면 안 된다.**
     *    넓히는 변경에서 새 조건이 조용히 깎는 사고가 실재했다(§8 #55).
     */
    public function test_widening_never_drops_a_previously_departed_vehicle(): void
    {
        $this->vehicle(['warehouse_out_date' => '2026-08-01']);
        $this->vehicle(['bl_document' => 'vehicles/x.pdf']);
        $this->vehicle(['warehouse_out_date' => '2026-08-01', 'shipping_date' => now()->addDays(30)->toDateString()]);
        $this->vehicle(['bl_document' => 'vehicles/y.pdf', 'shipping_date' => now()->addDays(30)->toDateString()]);
        $this->vehicle([]);   // 아무 근거 없음 — 선적전이 맞다

        // 옛 규칙을 테스트 안에서 직접 질의한다(코드가 바뀌어도 이 단언은 옛 집합을 지킨다).
        $old = Vehicle::query()
            ->where(fn ($q) => $q->whereNotNull('warehouse_out_date')->orWhereNotNull('bl_document'))
            ->pluck('id');
        $new = Vehicle::query()->departed()->pluck('id');

        $this->assertNotEmpty($old, '전제가 안 선다 — 옛 규칙으로 뽑히는 차가 없다');
        $this->assertEmpty($old->diff($new)->all(),
            '옛 규칙으로 선적후였던 차가 빠졌다 — 확대가 아니라 이동이 됐다');
    }

    /** `departed` 와 `notDeparted` 는 정확한 여집합이어야 한다 — 겹치거나 새면 분류가 틀어진다. */
    public function test_the_two_scopes_partition_every_vehicle(): void
    {
        $this->vehicle(['warehouse_out_date' => '2026-08-01']);
        $this->vehicle(['bl_issue_date' => '2026-09-01']);
        $this->vehicle(['shipping_date' => '2026-09-01']);
        $this->vehicle(['shipping_date' => now()->addDays(5)->toDateString()]);
        $this->vehicle([]);

        $all = Vehicle::count();
        $a = Vehicle::query()->departed()->count();
        $b = Vehicle::query()->notDeparted()->count();

        $this->assertSame($all, $a + $b, '두 스코프의 합이 전체와 다르다 — 겹치거나 빠진 차가 있다');
    }

    /**
     * 🚫 **조건을 옮겨 적지 말 것** — 관리자 대시보드가 예전엔 그렇게 적혀 있어서
     *    판정이 바뀔 때마다 화면과 대시보드가 갈렸다(§8 #45).
     */
    public function test_the_admin_dashboard_does_not_inline_the_rule(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/admin/dashboard.blade.php'));

        $this->assertStringContainsString('Vehicle::departedFrom(', $src,
            '대시보드가 단일 출처를 안 쓴다');
        $this->assertStringNotContainsString('blank($r->warehouse_out_date) && blank($r->bl_document)', $src,
            '대시보드가 아직 조건을 옮겨 적고 있다');

        // 그 판정이 보는 칸을 select 에서 빼면 늘 null 이라 조용히 「선적전」이 된다(§8 #83).
        foreach (['bl_issue_date', 'shipping_date'] as $col) {
            $this->assertStringContainsString("'{$col}'", $src,
                "대시보드 select 에 {$col} 가 없다 — 판정이 조용히 틀어진다");
        }
    }
}
