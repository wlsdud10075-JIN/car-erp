<?php

namespace Tests\Feature;

use App\Models\DomesticFeeStatement;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 📄 월정산 엑셀 (jin 2026-11-04 (나)) — 사람별 지급(검차·관리 포함)·급여 항목 + 종전 차량 정산 / 내수직원 월정산 엑셀.
 * 받은 파일을 직접 열어 본다 — 응답이 200 이어도 시트·숫자가 비면 의미가 없다.
 */
class PayoutBatchExportTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0108888'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now(),
        ]);
    }

    private function open($response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'pbx').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /** 사내직원 1명(정산 1건) + 검차직원(급여만)으로 10월 월정산을 올린다. */
    private function monthBatch(): array
    {
        $manager = $this->user('manager');
        $sm = Salesman::create(['name' => '김영업', 'is_active' => true, 'type' => 'employee']);
        $v = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'salesman_id' => $sm->id]);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-05', 'attributed_month' => '2026-10-01',
        ]);
        $insp = Salesman::create(['name' => '최검차', 'is_active' => true, 'type' => 'inspector']);
        PayrollEntry::create(['salesman_id' => $insp->id, 'month' => '2026-10', 'label' => '기본급', 'amount' => 2_740_000, 'sort' => 1]);

        return [SettlementPayoutBatch::submitForMonth($manager, '2026-10'), $manager];
    }

    public function test_month_batch_excel_has_people_payroll_and_vehicle_sheets(): void
    {
        [$batch, $manager] = $this->monthBatch();

        $res = $this->actingAs($manager)->get(route('erp.payout-batches.export', $batch));
        $res->assertOk();
        $this->assertStringContainsString(rawurlencode('사내직원월정산').'_2026-10', (string) $res->headers->get('Content-Disposition'));
        $ss = $this->open($res);

        $titles = array_map(fn ($w) => $w->getTitle(), $ss->getAllSheets());
        $this->assertSame(['사람별 지급', '급여 항목', '요약'], array_slice($titles, 0, 3));

        $people = $ss->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $byName = collect($people)->keyBy(1);
        $this->assertSame(2_740_000, (int) $byName['최검차'][12], '검차직원도 사람별 지급에 — 지급합계 = 급여');
        $this->assertSame(100_000, (int) $byName['김영업'][5], '사내직원 정산금');
        $bd = $batch->breakdownForDisplay();
        $this->assertSame(
            array_sum(array_column($bd['people'], 'payout')),
            (int) $byName['김영업'][12] + (int) $byName['최검차'][12],
            '엑셀 지급합계 = 화면 카드 합'
        );

        // 유형별 묶음 — 머리줄 · 소계 · 합계(= 소계들의 합)
        $col0 = array_column($people, 0);
        $this->assertContains('■ 사내직원 (1명)', $col0);
        $this->assertContains('■ 검차직원 (1명)', $col0);
        $this->assertSame(2_740_000, (int) $byName['검차직원 소계'][12]);
        $this->assertSame(2_840_000, (int) $byName['합계'][12], '합계 = 소계들의 합');

        // 급여 항목 = 사람마다 카드(이름·유형 → 항목·금액 → 합계). 급여가 없는 사람은 「급여 미입력」.
        $items = $ss->getSheetByName('급여 항목')->toArray(null, true, false, false);
        $flat = array_merge(...$items);
        $this->assertContains('최검차 · 검차직원', $flat);
        $this->assertContains('김영업 · 사내직원', $flat);
        $this->assertContains('급여 미입력', $flat);
        $card = collect($items)->first(fn ($r) => in_array('기본급', $r, true));
        $this->assertSame(2_740_000, (int) $card[array_search('기본급', $card, true) + 1]);

        $this->assertDatabaseHas('export_logs', ['user_id' => $manager->id, 'target' => 'settlements', 'scope' => 'payout_batch']);
    }

    public function test_domestic_batch_excel_follows_the_fee_sheet_and_is_gated(): void
    {
        $viewer = $this->user('manager');
        Setting::query()->updateOrCreate(['key' => 'domestic_payout_viewers'], ['value' => json_encode([$viewer->id]), 'type' => 'string']);
        Setting::flushRiskParamMemo();
        $sm = Salesman::create(['name' => '박내수', 'type' => 'domestic_staff', 'is_active' => true]);
        DomesticFeeStatement::create(['salesman_id' => $sm->id, 'month' => '2026-10',
            'headers' => ['건수', '회사수익', '총 평가사수수료', '총 회사수익'], 'values' => [10, 5_282_500, 183_700, 5_098_800], 'payout' => 183_700]);
        PayrollEntry::create(['salesman_id' => $sm->id, 'month' => '2026-10', 'label' => DomesticFeeStatement::PAYROLL_LABEL, 'amount' => 183_700, 'sort' => 0, 'is_custom' => true]);
        $ceo = $this->user('admin', '관리', '대표');
        $batch = SettlementPayoutBatch::submitForDomestic($viewer, '2026-10', ['대표' => $ceo->id]);

        // 지정 안 된 관리 = 내수 화면을 못 보므로 엑셀도 못 받는다
        $this->actingAs($this->user('user', '관리'))->get(route('erp.payout-batches.export', $batch))->assertForbidden();

        $res = $this->actingAs($viewer)->get(route('erp.payout-batches.export', $batch));
        $res->assertOk();
        $ss = $this->open($res);
        $this->assertSame('내수직원', $ss->getSheet(0)->getTitle());
        $rows = $ss->getSheet(0)->toArray(null, true, false, false);
        $this->assertSame(['No.', '이름', '건수', '회사수익', '총 평가사수수료', '총 회사수익', '지급합계'], $rows[0]);
        $this->assertSame('박내수', $rows[1][1]);
        $this->assertSame(183_700, (int) $rows[1][6]);

        // 승인 링크에도 엑셀 버튼(종전엔 내수만 빠져 있었다)
        $this->get($batch->approvalLinkFor($ceo))->assertOk()->assertSee('전체 내역 엑셀 내려받기');
    }

    public function test_sales_role_cannot_download_a_month_batch(): void
    {
        [$batch] = $this->monthBatch();
        $this->actingAs($this->user('user', '영업'))->get(route('erp.payout-batches.export', $batch))->assertForbidden();
        $this->get('/erp/payout-batches')->assertForbidden();   // 화면과 같은 게이트
    }

    public function test_the_screen_shows_the_excel_button(): void
    {
        [, $manager] = $this->monthBatch();
        $this->actingAs($manager)->get('/erp/payout-batches')->assertOk()->assertSee('data-payout-export', false);
    }
}
