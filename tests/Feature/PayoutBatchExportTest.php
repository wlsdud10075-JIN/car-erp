<?php

namespace Tests\Feature;

use App\Models\DomesticFeeStatement;
use App\Models\FinalPayment;
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

        // jin 2026-11-06 수정본 형식 — B1 제목·B2 부제·4행 머리(B열부터)·구분 세로 병합·소계·합계·요약(수식)
        $people = $ss->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $this->assertSame('2026년 10월 사람별 지급 내역', $people[0][1]);
        $this->assertSame('귀속월 2026-10 · 단위: 원', $people[1][1]);
        $hdr = $people[3];
        $this->assertSame(['구분', 'No.', '이름', '정산 건수', '급여', '정산금', '헤이맨 정산금', '인센티브', '조정', '이월', '예치금 반환',
            '퇴직연금 몫(따로)', '지급합계(송금)', '총마진', '발송비', '회사이익'], array_slice($hdr, 1), '보류가 없는 달은 jin 파일과 같은 17열');
        $byName = collect($people)->keyBy(3);
        $pay = array_search('지급합계(송금)', $hdr, true);   // 열 번호를 박지 않는다 — 보류 달엔 열이 끼어든다
        $this->assertSame(2_740_000, (int) $byName['최검차'][$pay], '검차직원도 사람별 지급에 — 지급합계 = 급여');
        $this->assertSame(100_000, (int) $byName['김영업'][array_search('정산금', $hdr, true)], '사내직원 정산금');
        $bd = $batch->breakdownForDisplay();
        $this->assertSame(
            array_sum(array_column($bd['people'], 'payout')),
            (int) $byName['김영업'][$pay] + (int) $byName['최검차'][$pay],
            '엑셀 지급합계 = 화면 카드 합'
        );

        // 유형별 묶음 — B열 구분(병합 첫 칸) · C열 소계 · 합계(= 소계들의 합) · 묶음 사이 빈 줄 없음
        $colB = array_column($people, 1);
        $colC = array_column($people, 2);
        $this->assertContains('사내직원', $colB);
        $this->assertContains('검차직원', $colB);
        $this->assertNotContains('■ 사내직원 (1명)', array_column($people, 0), '머리줄은 없다');
        $bySub = collect($people)->keyBy(2);
        $this->assertSame(2_740_000, (int) $bySub['검차직원 소계'][$pay]);
        $totalRow = collect($people)->first(fn ($r) => ($r[1] ?? null) === '합계');
        $this->assertSame(2_840_000, (int) $totalRow[$pay], '합계 = 소계들의 합');
        $iSub = array_search('사내직원 소계', $colC, true);
        $this->assertSame('검차직원', $colB[$iSub + 1], '소계 바로 다음 줄이 다음 묶음');
        // 요약 — 라벨 C / 값 G, 수식이 화면 totals 와 같다
        $byLabel = collect($people)->keyBy(2);
        $this->assertSame((int) $bd['totals']['transfer_total'], (int) $byLabel['송금 총액 (퇴직연금 몫 포함)'][6]);
        $this->assertSame((int) $bd['totals']['contribution_sum'], (int) $byLabel['회사 기여 합'][6]);
        $this->assertSame((int) $bd['totals']['common_labor'], (int) $byLabel['공통 인건비 (검차·업무관리·관리·재무)'][6]);
        $this->assertSame((int) $bd['totals']['company_net'], (int) $byLabel['회사 순이익'][6]);
        $sheet = $ss->getSheetByName('사람별 지급');
        $this->assertStringStartsWith('=', (string) $sheet->getCell('G'.(array_search('회사 순이익', array_column($people, 2), true) + 1))->getValue(), '요약은 수식');
        $this->assertSame('305496', $sheet->getStyle('B4')->getFill()->getStartColor()->getRGB());
        $this->assertSame('E5', $sheet->getFreezePane());

        // 급여 항목 = 한 사람 한 줄(구분·No.·이름·항목 열·합계·비고). 급여가 없는 사람은 비고 「급여 미입력」. 프리랜서 없음.
        $items = $ss->getSheetByName('급여 항목')->toArray(null, true, false, false);
        $this->assertSame('2026년 10월 급여 항목', $items[0][1]);
        $ih = $items[3];
        $this->assertSame(['구분', 'No.', '이름', '기본급', '합계', '비고'], array_slice($ih, 1), '그 달에 값 있는 항목 열만');
        $iname = collect($items)->keyBy(3);
        $this->assertSame(2_740_000, (int) $iname['최검차'][array_search('기본급', $ih, true)]);
        $this->assertSame(2_740_000, (int) $iname['최검차'][array_search('합계', $ih, true)]);
        $this->assertSame('급여 미입력', $iname['김영업'][array_search('비고', $ih, true)]);
        $itotal = collect($items)->first(fn ($r) => ($r[1] ?? null) === '합계');
        $this->assertSame((int) $totalRow[array_search('급여', $hdr, true)], (int) $itotal[array_search('합계', $ih, true)], '급여 항목 합계 = 사람별 지급 급여 합');
        $this->assertNotContains('최검차 · 검차직원', array_merge(...$items), '카드형은 폐기');

        $this->assertDatabaseHas('export_logs', ['user_id' => $manager->id, 'target' => 'settlements', 'scope' => 'payout_batch']);
    }

    /**
     * 💱 환차를 정산에서 뺀 달 — 엑셀 「회사 순이익」에도 환차손익(회사)이 들어가 화면 카드와 같아야 한다 (jin 2026-11-20).
     *   사람별 기여 열에는 환차가 없어서, 요약 수식에 그 항이 없으면 카드와 엑셀이 환차만큼 갈린다.
     */
    public function test_company_fx_is_in_the_excel_company_net_when_fx_is_excluded(): void
    {
        Setting::updateOrCreate(['key' => 'settlement_fx_excluded_from_'.Setting::companyTemplateSet()], ['value' => '2026-10', 'type' => 'string']);
        Setting::flushRiskParamMemo();
        $manager = $this->user('manager');
        $sm = Salesman::create(['name' => '환차프리', 'is_active' => true, 'type' => 'freelance']);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'dhl_request' => false,
            'currency' => 'EUR', 'exchange_rate' => 1716, 'sale_price' => 7837, 'transport_fee' => 1159,
            'sale_date' => '2026-08-01', 'purchase_date' => '2026-07-01', 'purchase_price' => 13_200_000, 'selling_fee' => 440_000,
        ]);
        foreach ([5000, 3996] as $amt) {
            FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => $amt, 'exchange_rate' => 1718, 'payment_date' => '2026-08-05', 'confirmed_at' => now()]);
        }
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-10-05', 'attributed_month' => '2026-10-01',
        ]);
        $batch = SettlementPayoutBatch::submitForMonth($manager, '2026-10');

        $bd = $batch->breakdownForDisplay();
        $fx = (int) $bd['totals']['company_fx'];
        $this->assertSame((int) round(8996 * (1718 - 1716)), $fx, '전제 — 이 배치엔 회사 몫 환차가 있다');

        $people = $this->open($this->actingAs($manager)->get(route('erp.payout-batches.export', $batch)))
            ->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $byLabel = collect($people)->keyBy(2);
        $this->assertSame($fx, (int) $byLabel['환차손익(회사)'][6]);
        $this->assertSame((int) $bd['totals']['company_net'], (int) $byLabel['회사 순이익'][6], '엑셀 회사 순이익 = 화면 카드');
    }

    /**
     * 🚪 같은 달에 승인된 퇴사 정산(따로 결재)의 회사 몫을 월정산 카드·엑셀의 회사 순이익에 합친다 (jin 2026-11-22).
     *   월결산 알림톡은 퇴사 정산을 그 달에 넣는다 — 카드만 빠져 있어 같은 달 두 숫자가 갈렸다(ssancarerp 10월 319,152원).
     *   박제는 안 건드리고 표시할 때 더한다. 급여가 두 번 빠지지 않게 퇴사 카드의 「회사 기여 합」을 쓴다(company_net 아님).
     */
    public function test_the_month_card_and_excel_include_the_same_months_leaver_payout(): void
    {
        [$batch, $manager] = $this->monthBatch();
        $this->assertSame(0, $batch->leaverCompanyNet(), '퇴사 정산이 없으면 0 — 종전 출력 그대로');

        $mk = fn (string $status, string $month) => SettlementPayoutBatch::create([
            'kind' => SettlementPayoutBatch::KIND_LEAVER, 'month' => $month, 'submitter_id' => $manager->id, 'submitter_rank' => 1, 'current_level' => 3,
            'status' => $status, 'total_payout' => 19_153, 'settlement_count' => 3, 'submitted_at' => now(), 'decided_at' => now(),
            'breakdown_snapshot' => ['people' => [], 'totals' => ['contribution_sum' => 319_152, 'common_labor' => 2_740_000, 'company_net' => -2_420_848]],
        ]);
        $mk(SettlementPayoutBatch::STATUS_APPROVED, '2026-10');
        $mk(SettlementPayoutBatch::STATUS_REJECTED, '2026-10');   // 반려된 퇴사 정산은 안 센다
        $mk(SettlementPayoutBatch::STATUS_APPROVED, '2026-09');   // 다른 달도 안 센다

        $this->assertSame(319_152, $batch->leaverCompanyNet(), '그 달 승인분의 회사 기여 합만 — 급여가 다시 빠진 company_net 이 아니다');

        $bd = $batch->breakdownForDisplay();
        $html = (string) $this->blade('<x-payout.totals :totals="$t" :leaver-net="$n" />', ['t' => $bd['totals'], 'n' => $batch->leaverCompanyNet()]);
        $this->assertStringContainsString('data-leaver-net', $html);
        $this->assertStringContainsString('data-company-net-final>'.((int) $bd['totals']['company_net'] + 319_152 < 0 ? '−' : '').'₩'.number_format(abs((int) $bd['totals']['company_net'] + 319_152)), $html);

        $people = $this->open($this->actingAs($manager)->get(route('erp.payout-batches.export', $batch)))
            ->getSheetByName('사람별 지급')->toArray(null, true, false, false);
        $byLabel = collect($people)->keyBy(2);
        $this->assertSame(319_152, (int) $byLabel['퇴사 정산 회사 몫(같은 달, 따로 결재)'][6]);
        $this->assertSame((int) $bd['totals']['company_net'] + 319_152, (int) $byLabel['회사 순이익(추가 지급·퇴사 정산 반영)'][6], '엑셀 = 화면 카드');
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
