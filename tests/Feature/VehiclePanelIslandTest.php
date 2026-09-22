<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportIslands\SupportIslands;
use Livewire\Volt\Volt;
use Livewire\Volt\VoltServiceProvider;
use Tests\TestCase;

use function Livewire\store;

/**
 * 🏝️ 차량관리 편집 패널 = Livewire 4 섬(island) (jin 2026-09-22 «섬방식 시도해보자»).
 *
 * 실측(ssancarerp 100행) 패널 닫기 INP 2,248ms — 패널 안 조작 하나에 100행이 통째로 morph 됐다.
 * 섬으로 감싸면 패널에서 시작한 액션은 패널 조각만 다시 그린다. 예외 = DB 를 썼으면 전체(목록 최신화).
 *
 * ⚠️ 이 부류는 기능 테스트로 「빨라졌다」를 못 잰다 — 여기서 지키는 것은
 *   ① Volt 에서 섬이 실제로 컴파일·렌더된다(조각 마커가 HTML 에 있다)
 *   ② 쓰기 없는 패널 액션 → 섬 조각만 / 쓰기 있는 패널 액션 → 전체 렌더(skipRender 해제)
 *   ③ 패널이 여는 모달이 전부 섬 안에 있다(밖에 있으면 패널 액션이 root 를 건너뛰어 모달이 안 뜬다)
 */
class VehiclePanelIslandTest extends TestCase
{
    use RefreshDatabase;

    private const BLADE = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    /**
     * ⏱️ 섬 precompiler 는 Volt 추출 「뒤」에 와야 한다 — 앞이면 PHP 클래스 372KB 까지 훑어 컴파일이 66초.
     * (AppServiceProvider::moveIslandPrecompilerAfterVolt — 훅 배열은 등록 순서대로 돈다.)
     */
    public function test_the_island_precompiler_runs_after_volt_extracts_the_template(): void
    {
        $compiler = app('blade.compiler');
        $callbacks = (new \ReflectionProperty($compiler, 'prepareStringsForCompilationUsing'))->getValue($compiler);
        $order = [];
        foreach ($callbacks as $i => $cb) {
            $scope = $cb instanceof \Closure ? (new \ReflectionFunction($cb))->getClosureScopeClass()?->getName() : null;
            if ($scope !== null) {
                $order[$scope] = $i;
            }
        }
        $this->assertArrayHasKey(SupportIslands::class, $order, '섬 precompiler 가 등록돼 있지 않다');
        $this->assertArrayHasKey(VoltServiceProvider::class, $order, 'Volt 추출 precompiler 가 등록돼 있지 않다');
        $this->assertGreaterThan(
            $order[VoltServiceProvider::class],
            $order[SupportIslands::class],
            '섬 precompiler 가 Volt 추출보다 먼저 돈다 — 차량관리 컴파일이 66초로 돌아간다'
        );
    }

    /** ① 섬이 Volt 단일파일에서 컴파일돼 조각 마커와 함께 렌더된다. */
    public function test_the_panel_island_is_compiled_and_rendered_in_volt(): void
    {
        $this->actingAs($this->admin());
        $html = Volt::test('erp.vehicles.index')->html();

        $this->assertTrue(str_contains($html, 'FRAGMENT:'), '섬 조각 마커가 없다 — @island 가 Volt 에서 컴파일되지 않았다');
        $this->assertTrue(str_contains($html, 'name=panel'), '「panel」 섬이 렌더되지 않았다');
    }

    /** 패널을 열면(목록에서 시작 = 전체 렌더) 섬 안의 패널 내용이 나온다 — 종전 동작 보존. */
    public function test_opening_the_panel_still_renders_its_content(): void
    {
        $this->actingAs($this->admin());
        $v = Vehicle::create(['vehicle_number' => '12가3456', 'purchase_price' => 0]);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('showPanel', true)
            ->assertSee('12가3456');
    }

    /** ② 쓰기 없는 패널 액션 → 섬 조각만(skipRender 유지). */
    public function test_a_read_only_panel_action_renders_only_the_island(): void
    {
        $this->actingAs($this->admin());
        $c = Volt::test('erp.vehicles.index');
        $inst = $c->instance();

        $inst->boot();                       // 요청 시작 = 쓰기 플래그 리셋
        $this->assertFalse($inst::islandWriteSeen());

        $inst->skipRender();                 // SupportIslands::call 이 하는 순서 그대로
        $inst->renderIsland('panel');

        $this->assertTrue((bool) store($inst)->get('skipRender', false), 'root 렌더가 다시 켜졌다 — 쓰기가 없는데 전체를 그린다');
        $this->assertTrue($inst->hasRenderedIslandFragments(), '섬 조각이 만들어지지 않았다');
    }

    /** ② 쓰기 있는 패널 액션 → 섬 대신 전체 렌더(목록 최신화). 캐시 테이블 쓰기는 쓰기로 안 센다. */
    public function test_a_writing_panel_action_falls_back_to_a_full_render(): void
    {
        $this->actingAs($this->admin());
        $v = Vehicle::create(['vehicle_number' => '12가3456', 'purchase_price' => 0]);
        $c = Volt::test('erp.vehicles.index');
        $inst = $c->instance();

        // 캐시 테이블(편집 잠금 하트비트 자리)만 쓴 요청 = 쓰기 아님
        $inst->boot();
        \DB::table('cache')->insert(['key' => 'island-probe', 'value' => '1', 'expiration' => time() + 60]);
        $this->assertFalse($inst::islandWriteSeen(), '캐시 테이블 쓰기를 쓰기로 셌다 — 닫기·하트비트마다 전체를 그리게 된다');

        // 실제 데이터 쓰기 = 전체 렌더
        $inst->boot();
        Vehicle::whereKey($v->id)->update(['memo_purchase' => 'x']);
        $this->assertTrue($inst::islandWriteSeen());

        $inst->skipRender();
        $inst->renderIsland('panel');

        $this->assertFalse((bool) store($inst)->get('skipRender', false), '쓰기가 있었는데 root 렌더가 skip 된 채다 — 목록이 옛 값으로 남는다');
        $this->assertFalse($inst->hasRenderedIslandFragments(), '전체를 그리는데 섬 조각까지 따로 보냈다(이중 렌더)');
    }

    /**
     * 검증 실패 = 쓰기 없는 패널 액션 → 섬만 다시 그린다. 그때 칸 아래 오류 문구가 섬 조각에 실려야 한다.
     * (SupportValidation 이 renderIsland 훅에서 $errors 를 공유한다 — 이게 빠지면 토스트만 뜨고 칸은 조용히 비어 있다.)
     */
    public function test_validation_messages_reach_the_island_fragment(): void
    {
        $this->actingAs($this->admin());
        $v = Vehicle::create(['vehicle_number' => '12가3456', 'purchase_price' => 0]);
        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $inst = $c->instance();

        $inst->boot();
        $inst->addError('vehicle_number', '섬 검증 문구');
        $inst->skipRender();
        $inst->renderIsland('panel');

        $fragments = $inst->getRenderedIslandFragments();
        $this->assertNotEmpty($fragments);
        $this->assertTrue(str_contains($fragments[0], '섬 검증 문구'), '검증 문구가 섬 조각에 없다 — 저장 실패 시 칸 아래가 비어 보인다');
    }

    /** ③ 패널이 여는 모달은 전부 섬 안에. 밖에 있으면 패널 액션이 root 를 건너뛰어 모달이 안 뜬다(예외 0·조용히). */
    public function test_every_panel_opened_modal_lives_inside_the_island(): void
    {
        $blade = file_get_contents(base_path(self::BLADE));
        $open = strpos($blade, "@island(name: 'panel'");
        $close = strpos($blade, '@endisland');
        $this->assertNotFalse($open);
        $this->assertNotFalse($close);
        $this->assertGreaterThan($open, $close);

        // 패널(또는 패널 안 액션)이 켜는 플래그들 — 각 @if 가 섬 범위 안이어야 한다
        $flags = [
            'showPanel', 'showMailModal', 'showWonbuModal', 'showDocCheckModal', 'showFutureDateModal',
            'showSaveConfirmModal', 'showTransferRequestModal', 'showTransferVoidModal', 'quickAddOpen',
            'showPurchaseGate', 'showDeleteGate', 'showSignModal',
        ];
        $outside = [];
        foreach ($flags as $flag) {
            $pos = false;
            foreach (["@if(\${$flag}", "@if (\${$flag}"] as $needle) {
                $pos = strpos($blade, $needle);
                if ($pos !== false) {
                    break;
                }
            }
            $this->assertNotFalse($pos, "\${$flag} 의 @if 를 못 찾았다");
            if ($pos < $open || $pos > $close) {
                $outside[] = $flag;
            }
        }
        $this->assertSame([], $outside, '섬 밖에 있는 패널 모달: '.implode(', ', $outside));
    }
}
