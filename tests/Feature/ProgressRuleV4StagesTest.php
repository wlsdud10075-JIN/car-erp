<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4 진행상태 4단계 — 실무 입력 순서 (jin 2026-10-20). 구 2026-10-16 판을 대체한다.
 *
 *   선적중   = 반입지 또는 수출신고서
 *   선적완료 = 선적일                     (반입지·신고서가 있을 때만)
 *   통관중   = 선적일 + ETA                (반입지·신고서가 있을 때만) — 🚢 운항중과 함께 보인다
 *   통관완료 = 통관 체크                   (단독)
 *   거래완료 = B/L 파일
 *
 * 근거 실측(ssancarerp 감사로그): 반입지→신고서 406:2 · 신고서→선적일 471:16 · 선적일→B/L 32:0.
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
        $this->assertSame('판매중', $this->vehicle()->progress_status, '반입지·신고서 없으면 판매 단계');
        $this->assertSame('선적중', $this->vehicle(['bl_loading_location' => '평택'])->progress_status);
        $this->assertSame('선적중', $this->vehicle(['export_declaration_document' => 'vehicles/decl.pdf'])->progress_status, '신고서만 있어도 선적중');
        $this->assertSame('선적중', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf'])->progress_status);
        $this->assertSame('선적완료', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf', 'shipping_date' => '2026-10-20'])->progress_status);
        $this->assertSame('통관중', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf', 'shipping_date' => '2026-10-20', 'eta_date' => '2026-11-20'])->progress_status);
        $this->assertSame('통관완료', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf', 'shipping_date' => '2026-10-20', 'eta_date' => '2026-11-20', 'is_export_cleared' => true])->progress_status);
        $this->assertSame('거래완료', $this->vehicle(['bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf', 'shipping_date' => '2026-10-20', 'eta_date' => '2026-11-20', 'is_export_cleared' => true, 'bl_document' => 'vehicles/bl.pdf'])->progress_status);
    }

    /** 통관완료는 통관 체크 단독 — 앞 단계 칸이 비어도 통관완료(10-16 과 같음). */
    public function test_cleared_is_reachable_with_the_check_alone(): void
    {
        $this->assertSame('통관완료', $this->vehicle(['is_export_cleared' => true])->progress_status);
        $this->assertSame('통관완료', $this->vehicle(['is_export_cleared' => true, 'bl_loading_location' => '평택'])->progress_status);
    }

    /**
     * 선적일·ETA 는 반입지나 신고서가 있을 때만 단계로 친다(jin 2026-10-20 「추천 좋네」) — 엑셀 적재로 선적일·ETA 만
     * 들어간 판매 단계 차(ssancarerp 128대, 미수 남은 판매중 46대)가 통관중으로 튀지 않게.
     */
    public function test_shipping_date_and_eta_count_only_after_loading_or_declaration(): void
    {
        $this->assertSame('판매중', $this->vehicle(['shipping_date' => '2026-10-20'])->progress_status, '선적일만 = 판매 단계');
        $this->assertSame('판매중', $this->vehicle(['shipping_date' => '2026-10-20', 'eta_date' => '2026-11-20'])->progress_status, '선적일+ETA 만 = 판매 단계');
        $this->assertSame('선적중', $this->vehicle(['bl_loading_location' => '평택', 'eta_date' => '2026-11-20'])->progress_status, 'ETA 만으로는 안 넘어간다(선적일 필요)');
        $this->assertSame('선적완료', $this->vehicle(['export_declaration_document' => 'vehicles/decl.pdf', 'shipping_date' => '2026-10-20'])->progress_status, '신고서 + 선적일 = 선적완료(반입지 무관)');
        $this->assertSame('통관중', $this->vehicle(['bl_loading_location' => '평택', 'shipping_date' => '2026-10-20', 'eta_date' => '2026-11-20'])->progress_status, '반입지 + 선적일 + ETA = 통관중(신고서 무관)');
    }

    /** 통관중 = 운항 뱃지와 함께 — 체크하면 통관완료 + 운항중, ETA 가 지나면 통관완료 + 도착예정(jin 2026-10-20). */
    public function test_clearing_comes_with_the_sailing_badge(): void
    {
        $v = $this->vehicle([
            'bl_loading_location' => '평택', 'export_declaration_document' => 'vehicles/decl.pdf',
            'shipping_date' => now()->subDays(5)->toDateString(), 'eta_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->assertSame('통관중', $v->progress_status);
        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->sailing_status);

        $v->update(['is_export_cleared' => true]);
        $this->assertSame('통관완료', $v->fresh()->progress_status);
        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $v->fresh()->sailing_status);
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
