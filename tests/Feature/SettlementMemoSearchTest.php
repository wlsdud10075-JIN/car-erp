<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 정산 목록 검색 — 차량번호 **또는 메모** (jin 2026-08-21).
 *
 * 왜 메모까지 찾나: karaba 에서 한 차를 딜러 둘이 같이 진행하는 경우가 있어, 메모에
 * `#동반#` 같은 키워드를 적어두고 그 건만 골라 반타작 처리한다.
 *
 * 🚨 여기서 잡는 것 두 가지 —
 *   ① **다른 필터가 새지 않는가.** 맨 `orWhere` 를 붙이면 상태·담당자 필터가 통째로
 *      무력화돼 «관리 탭인데 남의 정산이 뜨는» 형태가 된다. 조용히 틀린다.
 *   ② **화면과 엑셀이 같은 것을 뽑는가.** 조건을 두 곳에 옮겨 적으면 갈리는 순간
 *      "화면엔 보이는데 엑셀엔 없는" 상태가 되고 눈으로는 못 잡는다(SKILLS §8 #44).
 */
class SettlementMemoSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function settlement(string $plate, ?string $note, string $status = 'pending'): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => $plate,
            'sales_channel' => 'export',
            'purchase_date' => '2026-08-01',
            'dhl_request' => false,
        ]);

        return Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => Salesman::create([
                'name' => 'S'.++$this->c, 'is_active' => true, 'type' => 'freelance',
            ])->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => $status,
            'note' => $note,
        ]);
    }

    /** 메모의 키워드로 찾을 수 있다. */
    public function test_finds_by_memo_keyword(): void
    {
        $hit = $this->settlement('11가1111', '#동반# 김딜러와 반반');
        $this->settlement('22나2222', '일반 건');

        $ids = Settlement::query()->searchTerm('#동반#')->pluck('id')->all();

        $this->assertSame([$hit->id], $ids);
    }

    /** 차량번호 검색은 그대로 동작한다(기존 동작 보존). */
    public function test_still_finds_by_plate(): void
    {
        $hit = $this->settlement('33다3333', null);
        $this->settlement('44라4444', '#동반#');

        $ids = Settlement::query()->searchTerm('33다')->pluck('id')->all();

        $this->assertSame([$hit->id], $ids);
    }

    /**
     * 🚨 **다른 필터가 새지 않는다.**
     * 메모 조건을 클로저로 안 감싸면 `orWhere` 가 상태 필터를 통째로 무력화한다 —
     * 검색만 하면 확정·지급 건까지 우르르 뜨는데, 그게 정상처럼 보여서 아무도 못 잡는다.
     */
    public function test_does_not_leak_past_other_filters(): void
    {
        $pending = $this->settlement('55마5555', '#동반# 대기', 'pending');
        $this->settlement('66바6666', '#동반# 확정', 'confirmed');

        $ids = Settlement::query()
            ->where('settlement_status', 'pending')
            ->searchTerm('#동반#')
            ->pluck('id')->all();

        $this->assertSame([$pending->id], $ids, '상태 필터가 검색에 밀려 무력화됐다');
    }

    /** 빈 검색어는 아무것도 거르지 않는다. */
    public function test_blank_term_is_a_no_op(): void
    {
        $this->settlement('77사7777', null);
        $this->settlement('88아8888', '#동반#');

        $this->assertSame(2, Settlement::query()->searchTerm('  ')->count());
    }

    /**
     * 🚨 **화면과 엑셀이 같은 것을 뽑는다.**
     * 조건을 컨트롤러에 옮겨 적으면 갈리는 순간 "화면엔 보이는데 엑셀엔 없는" 상태가 된다.
     * 그래서 둘 다 `searchTerm` 하나만 쓰는지 정적으로 확인한다 —
     * 값이 갈려도 양쪽 화면은 정상 렌더되므로 기능 테스트로는 원리상 못 잡는다.
     */
    public function test_screen_and_export_share_one_source(): void
    {
        foreach ([
            'resources/views/livewire/erp/settlements/index.blade.php',
            'app/Http/Controllers/SettlementExportController.php',
        ] as $path) {
            $src = file_get_contents(base_path($path));

            $this->assertStringContainsString('searchTerm(', $src, "{$path} 가 공용 검색 scope 를 안 쓴다");

            // ⚠️ 「vehicle_number 가 나오면 실패」로 두면 **오탐**이 난다 — 정산 화면에는
            //    용도가 다른 차량 검색(정산 대상 차량 고르기, `$vehicleSearch`)이 따로 있다.
            //    오탐이 나는 가드는 곧 무시당해 무용지물이 되므로(SKILLS §8 #39),
            //    **목록 검색어(`$search`)가 차량번호와 함께 쓰인 경우**만 잡는다.
            $this->assertSame(0, preg_match('/vehicle_number[^;]{0,80}\$(this->)?search\b/', $src),
                "{$path} 에 목록 검색 조건이 다시 박혔다 — 갈리면 눈으로 못 잡는다");
        }
    }

    /** 엑셀 다운로드가 메모 검색어로도 동작한다(라우트 레벨 확인). */
    public function test_export_route_accepts_the_memo_term(): void
    {
        $hit = $this->settlement('99자9999', '#동반# 엑셀');
        $this->settlement('10차1010', '무관');

        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);

        $this->actingAs($admin)
            ->get(route('erp.settlements.export', ['q' => '#동반#']))
            ->assertOk();

        // 라우트가 200 이면 쿼리는 통과한 것 — 실제 필터링은 위 scope 테스트가 검증한다.
        $this->assertNotNull($hit->fresh());
    }

    /**
     * 🚨 **실제 화면 경로로 넣을 수 있어야 한다.**
     *
     * 반타작 흐름은 「기타공제로 절반 빼기 → 월배치 제출」이고, 배치 제출 대상은 **확정된** 정산이다.
     * 즉 jin 이 손대야 하는 시점은 `confirmed_at` 이 이미 찍힌 뒤다. 회계 가드가 그 시점에
     * `other_deduction` 을 막으면 **산수는 맞는데 넣을 수가 없는** 기능이 된다.
     *
     * 모델을 직접 `saveQuietly` 로 고치는 테스트는 이걸 절대 못 잡는다 — 가드와 validation 을
     * 통째로 우회하기 때문이다(SKILLS §8 #43 — 운영 경로를 그대로 타는 테스트를 따로 둔다).
     */
    public function test_finance_can_split_on_a_confirmed_settlement_through_the_screen(): void
    {
        $st = $this->settlement('12타1212', null, 'confirmed');
        $st->forceFill(['confirmed_at' => now()])->saveQuietly();

        $finance = User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
        $this->actingAs($finance);

        Volt::test('erp.settlements.index')
            ->call('openEdit', $st->id)
            ->set('other_deduction', 500_000)
            ->set('note', '#동반# 김딜러와 반반')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $st->fresh();
        $this->assertSame(500_000.0, (float) $fresh->other_deduction, '확정 후 기타공제를 못 넣는다 — 반타작이 통째로 막힌다');
        $this->assertStringContainsString('#동반#', (string) $fresh->note);

        // 그리고 그 메모로 실제로 검색된다 — 넣는 것과 찾는 것이 이어져야 기능이 성립한다.
        $this->assertSame([$st->id], Settlement::query()->searchTerm('#동반#')->pluck('id')->all());
    }
}
