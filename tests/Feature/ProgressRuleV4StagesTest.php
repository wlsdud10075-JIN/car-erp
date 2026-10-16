<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4 진행상태 4단계 조건 재정의 (jin 2026-10-16 «국내에서 선적기획 → 통관진행 → 운항 → 도착»).
 *
 *   선적중   = 반입지
 *   선적완료 = 반입지 + 선적일            (가안 — 수출신고서 무관)
 *   통관중   = 수출신고서 업로드
 *   통관완료 = 수출통관완료 체크          (구: + B/L 파일이라 도달 불가, 3사 0대)
 *   거래완료 = B/L 파일
 *
 * 출항 이후(운항중·도착예정)는 진행상태가 아니라 운항 뱃지(sailing_status)다.
 * 실측(배포 전, 3사): 통관중→통관완료 ssancarerp 858 · heymanerp 47 · karaba 149 / 선적완료→통관중 52·2·0.
 */
class ProgressRuleV4StagesTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.random_int(1000, 9999), 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'purchase_price' => 1_000_000,
            'sale_price' => 10_000, 'sale_date' => '2026-10-01',
        ], $attrs));
    }

    public function test_the_four_stages_follow_the_redefined_fields(): void
    {
        $this->assertSame('판매중', $this->vehicle()->progress_status, '반입지 없으면 판매 단계');
        $this->assertSame('선적중', $this->vehicle(['bl_loading_location' => '평택'])->progress_status);
        $this->assertSame('선적완료', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20'])->progress_status);
        $this->assertSame('통관중', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20', 'export_declaration_document' => 'vehicles/decl.pdf'])->progress_status);
        $this->assertSame('통관완료', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20', 'export_declaration_document' => 'vehicles/decl.pdf', 'is_export_cleared' => true])->progress_status);
        $this->assertSame('거래완료', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20', 'export_declaration_document' => 'vehicles/decl.pdf', 'is_export_cleared' => true, 'bl_document' => 'vehicles/bl.pdf'])->progress_status);
    }

    /** 통관완료는 이제 도달 가능하다 — 통관 체크만으로 (구 규칙은 B/L 파일도 요구해 영영 빈 칸이었다). */
    public function test_cleared_is_reachable_with_the_check_alone(): void
    {
        $this->assertSame('통관완료', $this->vehicle(['is_export_cleared' => true])->progress_status);
        $this->assertSame('통관완료', $this->vehicle(['is_export_cleared' => true, 'bl_loading_location' => '평택'])->progress_status);
    }

    /** 선적완료는 반입지가 있어야 한다 — 선적일만 있는 차(ssancarerp 138대)는 판매 단계에 남는다. 신고서는 무관(가안). */
    public function test_loaded_needs_the_loading_location_not_the_declaration(): void
    {
        $this->assertSame('판매중', $this->vehicle(['shipping_date' => '2026-10-20'])->progress_status, '반입지 없는 선적일은 선적완료가 아니다');
        $this->assertSame('선적완료', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20'])->progress_status);
        $this->assertSame('통관중', $this->vehicle(['export_declaration_document' => 'vehicles/decl.pdf'])->progress_status, '신고서만 있어도 통관중(반입지 무관)');
        $this->assertSame('통관중', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf'])->progress_status, '구 규칙의 「선적완료」 조합은 이제 통관중');
    }

    /** 출항 이후는 진행상태가 아니다 — 통관완료 + 운항 뱃지. ETA 당일부터 도착예정. */
    public function test_sailing_is_a_separate_axis_on_top_of_cleared(): void
    {
        $v = $this->vehicle([
            'bl_loading_location' => '평택', 'shipping_date' => now()->subDays(5)->toDateString(),
            'export_declaration_document' => 'vehicles/decl.pdf', 'is_export_cleared' => true,
            'eta_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->assertSame('통관완료', $v->progress_status);
        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->sailing_status);

        $v->update(['eta_date' => now()->toDateString()]);
        $this->assertSame('통관완료', $v->fresh()->progress_status, '도착예정이 돼도 진행상태는 안 움직인다');
        $this->assertSame(Vehicle::SAILING_ARRIVED, $v->fresh()->sailing_status, 'ETA 당일부터 도착예정');
    }

    /** 🚫 차량관리 진행상태 pill 에서 v3 라벨(수출통관중·수출통관완료)이 빠졌다 — 3사 캐시 0대. */
    public function test_vehicle_list_pills_no_longer_offer_the_v3_labels(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));
        $this->assertMatchesRegularExpression("/@foreach\\(\\['', '매입중'.*'통관완료', '거래완료'\\] as \\\$val\\)/u", $src);
        $this->assertDoesNotMatchRegularExpression("/@foreach\\(\\['', '매입중'[^\\n]*'수출통관중'/u", $src);
    }
}
