<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DomesticFeeStatement;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Services\Payout\BatchPayoutBreakdown;
use App\Services\Payroll\DomesticFeeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 🧾 내수직원 (jin 2026-11-03 «검차직원과 같아 · 내가 주는 엑셀 업로드 … 월정산에 나타나게, 검차처럼»).
 *
 * - 사용자 관리: 역할 「내수」 = 로그인 없음(이메일·비밀번호 없이) → Salesman(type=domestic_staff), 급여만.
 * - 사용자 관리 필터: 검차직원·내수직원 칩(jin «검차직원이 없는 거 같아»).
 * - 사내직원관리 「평가수수료 업로드」: 딜러수수료 탭의 「평가사 ~ 합계」 표 → 이름으로 내수직원 연결 → 표 한 줄 + 송금액(총 평가사수수료).
 * - 월정산: 검차직원 바로 아래 · 공통 인건비 · 합계 줄 = 엑셀 합계 행.
 */
class DomesticStaffTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['컨설팅비', '회사컨설팅비', '건수', '평가사수수료', '매도비', '회사수익', '클레임 외', '총 평가사수수료', '총 회사수익'];

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 실측 양식 축소판 — 위쪽에 차량 목록(행이 달마다 밀린다), 아래에 평가사 표(G열부터), 합계 줄. */
    private function sheet(array $people, ?array $total = null, string $tab = '딜러수수료_2026년10월'): string
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet()->setTitle($tab);
        $ws->fromArray(['3172', '제네시스 G80', '139수1609', '강영일', 475000], null, 'G4');
        $ws->fromArray(array_merge(['평가사'], self::HEADERS), null, 'G9');
        $r = 10;
        foreach ($people as $name => $vals) {
            $ws->fromArray(array_merge([$name], $vals), null, 'G'.$r++);
        }
        $sum = $total ?? array_map(fn ($i) => array_sum(array_column(array_values($people), $i)), array_keys(self::HEADERS));
        $ws->fromArray(array_merge(['합계'], $sum), null, 'G'.$r);
        $ss->createSheet()->setTitle('김진기')->setCellValue('J1826', 1);
        $path = tempnam(sys_get_temp_dir(), 'dfee').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function people(): array
    {
        return [
            '김진기' => [4750000, 2232500, 10, 2517500, 3050000, 5282500, 183700, 2701200, 5098800],
            '이기용' => [10578000, 3424000, 25, 6441500, 8000000, 11424000, 47070, 6488570, 11376930],
        ];
    }

    private function domestic(string $name): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => 'domestic_staff', 'is_active' => true]);
    }

    public function test_domestic_staff_is_created_without_login_and_mirrored_as_payroll_only(): void
    {
        $this->actingAs($this->admin());
        $c = Volt::test('admin.users.index')->call('openCreate');
        foreach (['name' => '김진기', 'permission' => 'user', 'role' => User::DOMESTIC_STAFF_ROLE] as $k => $v) {
            $c->set($k, $v);
        }
        $c->call('save')->assertHasNoErrors();

        $u = User::where('name', '김진기')->firstOrFail();
        $this->assertNull($u->email);
        $this->assertFalse($u->canAccessErp(), '내수는 ROLES 밖 — ERP 가 열리면 안 된다');
        $sm = Salesman::where('user_id', $u->id)->firstOrFail();
        $this->assertSame('domestic_staff', $sm->type);
        $this->assertTrue($sm->isPayrollOnly());
        $this->assertSame(0, Salesman::query()->sales()->whereKey($sm->id)->count(), '담당자 드롭다운에 섞이면 안 된다');
    }

    public function test_user_filter_has_inspector_and_domestic_chips(): void
    {
        $this->actingAs($this->admin());
        User::factory()->create(['name' => '오검차', 'permission' => 'user', 'role' => User::INSPECTOR_ROLE, 'email' => null]);
        User::factory()->create(['name' => '김평가', 'permission' => 'user', 'role' => User::DOMESTIC_STAFF_ROLE, 'email' => null]);

        $c = Volt::test('admin.users.index');
        $c->assertSeeHtml("pickRole('검차')")->assertSeeHtml("pickRole('내수')");
        $c->call('pickRole', '검차')->assertSet('roleFilter', '검차');
        $this->assertSame(['오검차'], collect($c->instance()->users->items())->pluck('name')->all());
        $c->call('pickRole', '내수')->assertSet('roleFilter', '내수');
        $this->assertSame(['김평가'], collect($c->instance()->users->items())->pluck('name')->all());
    }

    public function test_importer_reads_the_table_by_labels_and_checks_the_total(): void
    {
        $r = (new DomesticFeeImporter)->parse($this->sheet($this->people()));

        $this->assertSame('2026-10', $r['month']);
        $this->assertSame(self::HEADERS, $r['headers']);
        $this->assertSame(['김진기', '이기용'], array_column($r['people'], 'name'));
        $this->assertSame(2701200, $r['people'][0]['payout'], '송금액 = 총 평가사수수료');
        $this->assertSame([], $r['errors']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame(9189770, $r['total'][7]);

        $bad = (new DomesticFeeImporter)->parse($this->sheet($this->people(), array_fill(0, 9, 1)));
        $this->assertNotEmpty($bad['warnings'], '합계 줄과 다르면 경고');

        $wrongTab = (new DomesticFeeImporter)->parse($this->sheet($this->people(), null, '요약'));
        $this->assertNotEmpty($wrongTab['errors'], '딜러수수료 탭이 없으면 오류');
    }

    public function test_upload_writes_the_row_and_the_payout_for_matched_staff_only(): void
    {
        $admin = $this->admin();
        $kim = $this->domestic('김진기');
        $this->domestic('딴사람');
        Salesman::create(['name' => '이기용', 'type' => 'employee', 'is_active' => true]);   // 동명 사내직원 — 연결 후보가 아니다

        $file = UploadedFile::fake()->createWithContent('26년10월 평가수수료정산.xlsx', file_get_contents($this->sheet($this->people())));
        $c = Volt::actingAs($admin)->test('erp.salesmen.index')->call('openDomesticImport')->set('domesticFile', $file);

        $rowKim = $c->get('domesticPreview')['people'][0]['row'];
        $rowLee = $c->get('domesticPreview')['people'][1]['row'];
        $this->assertSame((string) $kim->id, $c->get('domesticMatches')[$rowKim]);
        $this->assertSame('', $c->get('domesticMatches')[$rowLee], '사내직원 이기용과는 연결하지 않는다');

        $c->call('applyDomesticImport');

        $st = DomesticFeeStatement::where('salesman_id', $kim->id)->where('month', '2026-10')->firstOrFail();
        $this->assertSame(self::HEADERS, $st->headers);
        $this->assertSame(2701200, $st->payout);
        $this->assertSame(2701200, PayrollEntry::totalFor($kim->id, '2026-10'));
        $this->assertSame(1, AuditLog::where('auditable_type', Salesman::class)->where('auditable_id', $kim->id)->where('column_name', 'payroll_2026-10')->count());
        $this->assertSame(1, DomesticFeeStatement::count());
    }

    public function test_upload_rejects_a_forged_match_outside_domestic_staff(): void
    {
        $admin = $this->admin();
        $emp = Salesman::create(['name' => '김진기', 'type' => 'employee', 'is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('f.xlsx', file_get_contents($this->sheet($this->people())));
        $c = Volt::actingAs($admin)->test('erp.salesmen.index')->call('openDomesticImport')->set('domesticFile', $file);
        $row = $c->get('domesticPreview')['people'][0]['row'];
        $c->set("domesticMatches.$row", (string) $emp->id)->call('applyDomesticImport');

        $this->assertSame(0, DomesticFeeStatement::count());
        $this->assertNull(PayrollEntry::totalFor($emp->id, '2026-10'), '사내직원 급여를 평가수수료로 덮으면 안 된다');
    }

    /**
     * 사내직원관리 패널 — 내수직원은 18항목 입력칸 대신 엑셀 머리글 항목·금액만(jin 2026-11-03 «헤더 부분만 항목들에 나오고 그 금액들»).
     * 마지막으로 올린 달을 열고, 패널 저장이 업로드 값을 덮지 않는다(값은 업로드로만).
     */
    public function test_panel_shows_only_the_sheet_items_and_saving_keeps_them(): void
    {
        $admin = $this->admin();
        $kim = $this->domestic('김진기');
        $vals = $this->people()['김진기'];
        DomesticFeeStatement::create(['salesman_id' => $kim->id, 'month' => '2026-10', 'headers' => self::HEADERS, 'values' => $vals, 'payout' => $vals[7], 'source_file' => '10월.xlsx']);
        PayrollEntry::replaceFor($kim->id, '2026-10', [['label' => DomesticFeeStatement::PAYROLL_LABEL, 'amount' => $vals[7], 'is_custom' => true]]);

        $c = Volt::actingAs($admin)->test('erp.salesmen.index')->call('openEdit', $kim->id);
        $c->assertSet('payrollMonth', '2026-10');
        $html = $c->html();
        $this->assertStringContainsString('data-domestic-panel', $html);
        $this->assertStringNotContainsString('data-pay-amount', $html, '사내직원용 18항목 입력칸이 나오면 안 된다');
        $this->assertMatchesRegularExpression('/data-domestic-panel-item="컨설팅비"[\s\S]{0,200}4,750,000/u', $html);
        $this->assertMatchesRegularExpression('/data-domestic-panel-item="총 평가사수수료"[\s\S]{0,200}2,701,200/u', $html);

        $c->call('save');
        $this->assertSame($vals[7], PayrollEntry::totalFor($kim->id, '2026-10'), '패널 저장이 업로드 값을 지우거나 바꾸면 안 된다');
    }

    public function test_batch_puts_domestic_right_after_inspector_as_common_labor_with_a_total_row(): void
    {
        $i = Salesman::create(['name' => '오검차', 'type' => 'inspector', 'is_active' => true]);
        $staff = Salesman::create(['name' => '박재무', 'type' => 'staff', 'is_active' => true]);
        PayrollEntry::replaceFor($i->id, '2026-10', [['label' => '기본급', 'amount' => 2_000_000]]);
        PayrollEntry::replaceFor($staff->id, '2026-10', [['label' => '기본급', 'amount' => 3_000_000]]);
        foreach ($this->people() as $name => $vals) {
            $sm = $this->domestic($name);
            DomesticFeeStatement::create(['salesman_id' => $sm->id, 'month' => '2026-10', 'headers' => self::HEADERS, 'values' => $vals, 'payout' => $vals[7]]);
            PayrollEntry::replaceFor($sm->id, '2026-10', [['label' => DomesticFeeStatement::PAYROLL_LABEL, 'amount' => $vals[7], 'is_custom' => true]]);
        }
        $submitter = User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
        $batch = SettlementPayoutBatch::create([
            'month' => '2026-10', 'submitter_id' => $submitter->id, 'submitter_rank' => 2, 'current_level' => 3,
            'status' => 'pending', 'total_payout' => 0, 'settlement_count' => 0, 'submitted_at' => now(),
        ]);

        $b = BatchPayoutBreakdown::forBatch($batch);
        $this->assertSame(['inspector', 'domestic_staff', 'domestic_staff', 'staff'], array_column($b['people'], 'type'), '검차직원 바로 아래 내수직원');
        $this->assertSame(2_000_000 + 2_701_200 + 6_488_570 + 3_000_000, $b['totals']['common_labor']);
        $this->assertSame(2_000_000 + 2_701_200 + 6_488_570 + 3_000_000, $b['totals']['transfer_total']);

        $kim = collect($b['people'])->firstWhere('name', '김진기');
        $this->assertSame(2_701_200, $kim['payout']);
        $this->assertSame('총 평가사수수료', $kim['domestic_fee'][7]['label']);

        $total = $b['domestic_fee_total'];
        $this->assertSame(2, $total['count']);
        $this->assertEquals(9_189_770, $total['items'][7]['value'], '합계 줄 = 엑셀 합계 행(총 평가사수수료)');
        $this->assertEquals(35, $total['items'][2]['value'], '건수도 더한다');

        $html = $this->blade('<x-payout.domestic-fee-total :total="$t" />', ['t' => $total]);
        $html->assertSee('내수직원 합계 (2명)')->assertSee('9,189,770');
    }
}
