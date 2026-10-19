<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📄 차량관리 「말소완료」 = 말소일이 찍힌 차 전부 (jin 2026-10-19).
 *   «말소완료가 된 것 중에 서류준비를 해야 하는데 토글이 말소완료 태그만 불러온다 — 판매중·판매완료로 따로 찾아 들어가야 한다».
 *   순수 확대 — 캐시가 말소완료인 차는 말소일이 없어도 그대로 남는다(§8 #55). 제외(빨강)는 정확히 그 여집합.
 */
class DeregisteredFilterWideningTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,int> 번호 => id */
    private function seedVehicles(): array
    {
        $rows = [
            'CACHE-ONLY' => ['말소완료', null],          // 캐시만(말소일 없음) — 종전에도 나오던 차
            'SALE-DATE' => ['판매중', '2026-10-01'],
            'DONE-DATE' => ['거래완료', '2026-09-01'],
            'SALE-NODATE' => ['판매중', null],           // 말소 안 됨 — 안 나와야
            'BUY-NODATE' => ['매입완료', null],
        ];
        $ids = [];
        foreach ($rows as $no => [$cache, $date]) {
            $v = Vehicle::create(['vehicle_number' => $no, 'sales_channel' => 'export']);
            DB::table('vehicles')->where('id', $v->id)->update(['progress_status_cache' => $cache, 'deregistration_date' => $date]);
            $ids[$no] = $v->id;
        }

        return $ids;
    }

    private function numbers($q): array
    {
        return $q->orderBy('vehicle_number')->pluck('vehicle_number')->all();
    }

    public function test_deregistered_includes_every_stage_with_a_deregistration_date(): void
    {
        $this->seedVehicles();

        $this->assertSame(['CACHE-ONLY', 'DONE-DATE', 'SALE-DATE'], $this->numbers(Vehicle::query()->progressStage('말소완료')));
        $this->assertSame(['SALE-DATE', 'SALE-NODATE'], $this->numbers(Vehicle::query()->progressStage('판매중')), '다른 단계는 종전대로 캐시 그대로');
    }

    public function test_excluding_deregistered_is_the_exact_complement(): void
    {
        $this->seedVehicles();

        $in = $this->numbers(Vehicle::query()->progressStage('말소완료'));
        $out = $this->numbers(Vehicle::query()->notProgressStages(['말소완료']));
        $this->assertSame(['BUY-NODATE', 'SALE-NODATE'], $out);
        $this->assertSame([], array_intersect($in, $out));
        $this->assertCount(Vehicle::count(), array_merge($in, $out), '포함 ∪ 제외 = 전체 — 같은 pill 이 방향에 따라 다른 뜻이 되지 않는다');
    }

    public function test_the_vehicle_list_pill_shows_the_wide_set(): void
    {
        $this->seedVehicles();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]));

        Volt::test('erp.vehicles.index')->set('dateFrom', '')->set('dateTo', '')->call('cycleProgress', '말소완료')
            ->assertSee('SALE-DATE')->assertSee('DONE-DATE')->assertSee('CACHE-ONLY')
            ->assertDontSee('SALE-NODATE');
    }

    public function test_excel_export_uses_the_same_scopes_as_the_screen(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/VehicleExportController.php'));
        $this->assertStringContainsString('->progressStage($progress)', $src, '내보내기가 캐시 컬럼을 직접 보면 화면과 다른 집합이 나간다');
        $this->assertStringContainsString('->notProgressStages($exclude)', $src);
    }
}
