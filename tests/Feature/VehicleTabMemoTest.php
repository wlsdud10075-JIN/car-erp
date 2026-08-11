<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량 편집 **탭별 메모 5칸** (jin 2026-08-11).
 *
 * 제보는 "메모를 적으면 차량수정 탭 전체에 공유된다"였는데, 실제로는 공유 버그가 아니라
 * **하단 메모칸 하나가 탭 컨테이너 밖에 있어** 8개 탭 어디서든 같은 박스가 보인 것이었다.
 * 라벨엔 그 사정이 없어(그냥 「메모」) "탭마다 따로 쓰는 칸"으로 읽혔다.
 *
 * 그래서 ①탭별 칸 5개를 만들고 ②하단 공통 칸은 **살려 두되** 라벨에 「공통」을 박았다.
 * 지우면 운영에 쌓인 기존 메모가 통째로 사라진다.
 */
class VehicleTabMemoTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'MEMO-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
        ]);
    }

    public function test_every_tab_memo_column_exists_and_is_fillable(): void
    {
        $v = new Vehicle;
        foreach (Vehicle::TAB_MEMOS as $tab => $col) {
            $this->assertTrue(Schema::hasColumn('vehicles', $col), "{$col} 컬럼이 없다");
            $this->assertContains($col, $v->getFillable(), "{$col} 이 fillable 이 아니다 — 저장이 조용히 무시된다");
        }
    }

    /** 🔑 핵심 — 한 탭에 쓴 메모가 다른 탭 칸으로 새지 않는다. */
    public function test_tab_memos_are_independent(): void
    {
        $v = $this->vehicle();

        $values = [];
        foreach (array_keys(Vehicle::TAB_MEMOS) as $i => $tab) {
            $values[$tab] = "{$tab} 전용 메모 ".$i;
        }

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('tabMemos', $values)
            ->set('memo', '차량 전체 공통 한마디')
            ->call('save');

        $v->refresh();
        foreach (Vehicle::TAB_MEMOS as $tab => $col) {
            $this->assertSame($values[$tab], $v->{$col}, "{$tab} 탭 메모가 저장되지 않았다");
        }
        $this->assertSame('차량 전체 공통 한마디', $v->memo, '공통 메모가 탭 메모에 덮였다');

        // 값이 서로 달라야 한다 — 하나라도 겹치면 같은 컬럼에 물린 것이다(제보된 그 증상).
        $stored = collect(Vehicle::TAB_MEMOS)->map(fn ($col) => $v->{$col})->all();
        $this->assertCount(count($stored), array_unique($stored), '탭 메모가 서로 같은 값이다 — 한 컬럼을 공유하고 있다');
    }

    /** 패널을 다시 열면 각 탭 값이 제자리로 돌아온다(저장은 됐는데 안 보이는 일 방지). */
    public function test_reopening_loads_each_tab_memo_back(): void
    {
        $v = $this->vehicle();
        $v->update(collect(Vehicle::TAB_MEMOS)->mapWithKeys(fn ($col, $tab) => [$col => "{$tab}!"])->all());

        $c = Volt::actingAs($this->admin())->test('erp.vehicles.index')->call('openEdit', $v->id);

        foreach (array_keys(Vehicle::TAB_MEMOS) as $tab) {
            $this->assertSame("{$tab}!", $c->get('tabMemos')[$tab] ?? null, "{$tab} 탭 메모가 화면에 안 돌아왔다");
        }
    }

    /** 빈 칸은 null 로 저장한다 — 빈 문자열이 남으면 "메모 있음"으로 보인다. */
    public function test_blank_tab_memo_is_stored_as_null(): void
    {
        $v = $this->vehicle();
        $v->update(['memo_purchase' => '지울 메모']);

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('tabMemos.purchase', '   ')
            ->call('save');

        $this->assertNull($v->refresh()->memo_purchase);
    }

    /**
     * 🚫 **공통 `memo` 를 지우지 말 것** — 운영에 쌓인 메모가 통째로 사라진다.
     * 탭 메모를 넣었다고 없애는 리팩터가 나오면 여기서 걸린다.
     */
    public function test_shared_memo_column_survives(): void
    {
        $this->assertTrue(Schema::hasColumn('vehicles', 'memo'));
        $this->assertNotContains('memo', Vehicle::TAB_MEMOS, '공통 memo 가 탭 메모 목록에 섞였다');

        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));
        $this->assertStringContainsString("__('vehicle.field.memo_common')", $src,
            '하단 공통 메모 라벨이 사라졌다 — 「공통」 표기가 없으면 탭 메모와 헷갈린다(제보된 원인).');
    }

    /**
     * 🇰🇷 감사로그는 컬럼명을 **원문 그대로** 찍는다 — 한글 라벨이 없으면 관리자 화면에
     * `memo_purchase` 가 그대로 노출된다(SKILLS §8 #41).
     */
    public function test_audited_and_labelled_in_korean(): void
    {
        foreach (Vehicle::TAB_MEMOS as $col) {
            $this->assertContains($col, Vehicle::AUDITED_COLUMNS, "{$col} 이 감사 대상이 아니다");
            $label = ColumnLabel::column('vehicles', $col);
            $this->assertMatchesRegularExpression('/[가-힣]/u', $label, "{$col} 의 감사 라벨이 한글이 아니다: {$label}");
        }
    }

    /** 값이 바뀌면 감사 기록이 남는다(실제 경로 — 컬럼 등재만으론 도는지 알 수 없다). */
    public function test_change_is_recorded(): void
    {
        $v = $this->vehicle();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('tabMemos.sale', '바이어가 선적 늦춰달라고 함')
            ->call('save');

        $this->assertTrue(
            AuditLog::where('auditable_type', Vehicle::class)
                ->where('auditable_id', $v->id)
                ->where('column_name', 'memo_sale')
                ->exists(),
            '탭 메모 변경이 감사로그에 안 남았다'
        );
    }
}
