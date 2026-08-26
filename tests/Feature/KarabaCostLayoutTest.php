<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Support\ColumnLabel;
use App\Support\KarabaCostRemap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * karaba 비용 칸 정리 (jin 2026-08-26).
 *
 * ① 매입탭 비용을 3사 공통 10칸으로 (주차료 신설). karaba 12칸 레이아웃 폐기.
 * ② karaba 라벨 — 기타비1=점검비 / 기타비2=기타비 (화면·감사로그 단일 출처).
 * ③ 데이터 이관 — cost_extra1(주차료 50,000) → cost_parking, cost_inspection(점검비 80,000) → cost_extra1.
 *
 * 🔑 이 파일이 지키는 불변식은 하나다: **cost_total 이 안 움직인다** = 마진·정산이 안 움직인다.
 * 🚫 그리고 이관은 karaba 에서만 돈다 — heymanerp 는 cost_extra1 에 진짜 기타비1이 들어 있다.
 */
class KarabaCostLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function asKaraba(): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
        $this->flushSettingMemo();
    }

    private function flushSettingMemo(): void
    {
        if (method_exists(Setting::class, 'flushParamMemo')) {
            Setting::flushParamMemo();
        }
    }

    protected function tearDown(): void
    {
        $this->flushSettingMemo();
        parent::tearDown();
    }

    /** 운영 karabaerp 실측 모양 — 주차료 50,000 전대, 점검비 80,000 일부. */
    private function karabaVehicle(int $extra1 = 50000, int $inspection = 80000): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'KRB-'.fake()->unique()->numberBetween(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'KRW', 'dhl_request' => false,
            'purchase_price' => 5_000_000,
            'cost_extra1' => $extra1,
            'cost_inspection' => $inspection,
        ]);
    }

    public function test_cost_total_does_not_move_when_costs_are_remapped(): void
    {
        $this->asKaraba();
        $v = $this->karabaVehicle();
        $before = (int) $v->cost_total;

        KarabaCostRemap::run();

        $v->refresh();
        $this->assertSame($before, (int) $v->cost_total, '이관으로 비용 합계가 움직임 — 마진이 틀어진다');
        $this->assertSame(50000, (int) $v->cost_parking, '주차료가 제자리를 못 찾음');
        $this->assertSame(80000, (int) $v->cost_extra1, '점검비가 기타비1(=karaba 점검비) 칸으로 안 옴');
        $this->assertSame(0, (int) $v->cost_inspection, '옛 점검비 칸이 안 비워짐');
    }

    public function test_remap_is_skipped_for_non_karaba_companies(): void
    {
        // heymanerp 의 cost_extra1 은 진짜 기타비1이다 — 옮기면 그 회사 회계가 틀어진다.
        Setting::query()->updateOrCreate(['key' => 'company_template_set'], ['value' => 'heyman', 'type' => 'string']);
        $this->flushSettingMemo();
        $v = $this->karabaVehicle(614818, 0);

        $result = KarabaCostRemap::run();

        $v->refresh();
        $this->assertTrue($result['skipped']);
        $this->assertSame(614818, (int) $v->cost_extra1, 'karaba 아닌 회사의 기타비1이 옮겨짐');
        $this->assertSame(0, (int) $v->cost_parking);
    }

    public function test_remap_does_not_run_twice(): void
    {
        // 두 번 돌면 「기타비1에 든 점검비」가 주차료로 밀려 두 항목이 뒤섞인다.
        $this->asKaraba();
        $v = $this->karabaVehicle();

        KarabaCostRemap::run();
        $second = KarabaCostRemap::run();

        $v->refresh();
        $this->assertTrue($second['skipped'], '재실행이 막히지 않음');
        $this->assertSame(50000, (int) $v->cost_parking);
        $this->assertSame(80000, (int) $v->cost_extra1);
    }

    public function test_remap_writes_an_audit_trail(): void
    {
        $this->asKaraba();
        $v = $this->karabaVehicle();

        KarabaCostRemap::run();

        $cols = DB::table('audit_logs')
            ->where('auditable_id', $v->id)
            ->pluck('column_name')->all();
        foreach (['cost_parking', 'cost_extra1', 'cost_inspection'] as $c) {
            $this->assertContains($c, $cols, "$c 이관이 감사로그에 안 남음");
        }
    }

    public function test_cost_breakdown_always_sums_to_cost_total(): void
    {
        // 「표의 합계는 맞는데 줄을 더하면 안 맞는」 화면 방지 — 입력칸 없는 레거시 컬럼도 드러난다.
        $v = $this->karabaVehicle(50000, 80000);
        $v->cost_performance = 12345;   // 입력칸이 없는 컬럼에 값이 남아 있는 상황
        $v->save();

        $rows = $v->costBreakdown();

        $this->assertSame((int) $v->cost_total, array_sum($rows), '분해 합계 ≠ cost_total');
        $this->assertArrayHasKey('cost_performance', $rows, '입력칸 없는 잔액이 화면에서 사라짐');
    }

    public function test_breakdown_hides_legacy_columns_when_zero(): void
    {
        $v = $this->karabaVehicle(50000, 0);
        $rows = $v->costBreakdown();

        $this->assertSame(Vehicle::DISPLAY_COST_FIELDS, array_keys($rows), '0인 레거시 칸이 표에 남음');
    }

    public function test_karaba_renames_extra_costs_everywhere(): void
    {
        $this->asKaraba();

        $this->assertSame('점검비', Vehicle::costLabel('cost_extra1'));
        $this->assertSame('기타비', Vehicle::costLabel('cost_extra2'));
        // 감사로그도 같은 이름이어야 한다 — 갈리면 화면과 로그가 다른 칸처럼 보인다.
        $this->assertSame('점검비', ColumnLabel::column('Vehicle', 'cost_extra1'));
        $this->assertSame('기타비', ColumnLabel::column('Vehicle', 'cost_extra2'));
    }

    public function test_other_companies_keep_the_plain_labels(): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_template_set'], ['value' => 'heyman', 'type' => 'string']);
        $this->flushSettingMemo();

        $this->assertSame('기타비1', Vehicle::costLabel('cost_extra1'));
        $this->assertSame('기타비1', ColumnLabel::column('Vehicle', 'cost_extra1'));
    }

    public function test_karaba_fixed_costs_are_the_three_agreed_amounts(): void
    {
        $this->asKaraba();
        $d = Vehicle::defaultPurchaseCosts();

        $this->assertSame(17300, $d['cost_deregistration'], '말소비');
        $this->assertSame(80000, $d['cost_extra1'], '점검비 — karaba 는 기타비1 칸을 점검비로 쓴다');
        $this->assertSame(50000, $d['cost_parking'], '주차료');
    }

    public function test_bulk_cost_whitelist_matches_the_visible_fields(): void
    {
        // 화면에 없는 칸을 일괄로 채우면 아무도 못 본다(입력칸 없이 cost_total 에만 더해지는 그 함정).
        $this->assertSame(Vehicle::DISPLAY_COST_FIELDS, Vehicle::BULK_COST_FIELDS);
        $this->assertCount(10, Vehicle::DISPLAY_COST_FIELDS);
        $this->assertContains('cost_parking', Vehicle::DISPLAY_COST_FIELDS);
    }

    public function test_purchase_tab_renders_ten_cost_inputs_from_the_single_source(): void
    {
        // 정적 검사 — 칸 목록을 blade 에 다시 적으면 여기서 잡는다(목록이 갈리면 저장이 조용히 빠진다).
        $blade = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringContainsString('Vehicle::DISPLAY_COST_FIELDS as $costCol', $blade);
        $this->assertStringNotContainsString("__('vehicle.field.cost_extra1')", $blade,
            '비용 라벨을 직접 부르면 karaba 이름이 안 따라온다 — Vehicle::costLabel 을 쓸 것');
    }
}
