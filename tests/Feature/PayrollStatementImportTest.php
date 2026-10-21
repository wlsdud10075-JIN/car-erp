<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Payroll\PayrollStatementImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

/**
 * 급여대장 xls 업로드 (jin 2026-10-14). 🚫 실제 급여대장은 픽스처로 두지 않는다(실명·급여) — 같은 3줄 레이아웃을 테스트가 만든다.
 */
class PayrollStatementImportTest extends TestCase
{
    use RefreshDatabase;

    /** 실제 양식과 같은 모양: 제목 H1 · 헤더 6~8행 · 사람마다 3줄 · 마지막 「합계」 */
    private function sheet(array $people, string $title = '2026년 09월분  급여대장'): string
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setCellValue('H1', $title);
        $ws->setCellValue('A5', '인 적 사 항');
        $ws->fromArray(['사원번호', '성  명', '기본급', '상여', '식대', '자가운전', '연장근로', '복리', '기타수당', '국민연금', '건강보험'], null, 'A6');
        $ws->fromArray(['입 사 일', '직  급', '전월소급', '연차수당', '판매수당', '영업지원', '헤이딜러', 'ERP이용수당', null, '국민연금지원금'], null, 'A7');
        $ws->fromArray(['퇴 사 일', '부  서', null, null, null, null, null, null, '지급합계', '소득세정산'], null, 'A8');
        $r = 9;
        foreach ($people as $p) {
            $ws->setCellValue("A{$r}", $p['no']);
            $ws->setCellValue("B{$r}", $p['name']);
            foreach ($p['row1'] as $col => $v) {
                $ws->setCellValue("{$col}{$r}", $v);
            }
            $ws->setCellValue('J'.$r, 99_999);   // 공제 — 읽히면 안 된다
            $ws->setCellValue('A'.($r + 1), '2026-01-01');
            $ws->setCellValue('B'.($r + 1), $p['position'] ?? '');
            foreach ($p['row2'] as $col => $v) {
                $ws->setCellValue("{$col}".($r + 1), $v);
            }
            $ws->setCellValue('B'.($r + 2), $p['dept'] ?? '해외영업');
            $ws->setCellValue('I'.($r + 2), $p['sheet_total']);
            $ws->setCellValue('O'.($r + 2), 123);   // 차인지급액 — 무시
            $r += 3;
        }
        $ws->setCellValue("A{$r}", '합계 ('.count($people).'명)');
        $path = tempnam(sys_get_temp_dir(), 'pay').'.xls';
        (new Xls($ss))->save($path);

        return $path;
    }

    private function samplePeople(): array
    {
        return [
            // 1줄째 2항목 + 2줄째 1항목(판매수당) → 합 4,583,333
            ['no' => '23', 'name' => '김연아', 'position' => '사원', 'row1' => ['C' => 2_300_000, 'E' => 200_000], 'row2' => ['E' => 2_083_333], 'sheet_total' => 4_583_333],
            // 음수 전월소급(2줄째 C) 포함
            ['no' => '67', 'name' => '무사백', 'row1' => ['C' => 2_400_000, 'E' => 200_000], 'row2' => ['C' => -240_000], 'sheet_total' => 2_360_000, 'dept' => '해외검차'],
            // 이름이 명부와 다른 사람
            ['no' => '95', 'name' => '칼로스마리오', 'row1' => ['C' => 2_100_000], 'row2' => [], 'sheet_total' => 2_100_000],
            // 검산 틀린 줄
            ['no' => '99', 'name' => '오류씨', 'row1' => ['C' => 1_000_000], 'row2' => [], 'sheet_total' => 1_500_000],
        ];
    }

    public function test_parser_reads_month_items_by_header_label_and_checks_each_total(): void
    {
        $r = (new PayrollStatementImporter)->parse($this->sheet($this->samplePeople()));

        $this->assertSame('2026-09', $r['month']);
        $this->assertSame([], $r['errors']);
        $this->assertCount(4, $r['people']);
        [$kim, $musa, $carlos, $bad] = $r['people'];
        $this->assertSame(['기본급' => 2_300_000, '식대' => 200_000, '판매수당' => 2_083_333], $kim['items'], '같은 E열이 1줄째는 식대, 2줄째는 판매수당');
        $this->assertTrue($kim['ok']);
        $this->assertSame(-240_000, $musa['items']['전월소급']);
        $this->assertSame('해외검차', $musa['dept']);
        $this->assertTrue($musa['ok']);
        $this->assertArrayNotHasKey('국민연금', $kim['items'], '공제는 읽지 않는다');
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('검산 불일치', $bad['error']);
    }

    public function test_matching_prefers_employee_no_then_exact_name(): void
    {
        $byNo = Salesman::create(['name' => '카를로스', 'employee_no' => '95', 'type' => 'employee', 'is_active' => true]);
        $byName = Salesman::create(['name' => '김연아', 'type' => 'employee', 'is_active' => true]);
        Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);   // 동명이인 → 사람이 고른다

        $imp = new PayrollStatementImporter;
        $r = $imp->parse($this->sheet($this->samplePeople()));
        $m = $imp->match($r['people']);

        $this->assertSame($byName->id, $m[9]);
        $this->assertNull($m[12], '동명이인은 자동 매칭하지 않는다');
        $this->assertSame($byNo->id, $m[15], '이름이 달라도 사원번호로 잡는다');
    }

    public function test_apply_writes_only_matched_valid_rows_keeps_other_months_and_remembers_employee_no(): void
    {
        $kim = Salesman::create(['name' => '김연아', 'type' => 'employee', 'is_active' => true]);
        $carlos = Salesman::create(['name' => '카를로스', 'type' => 'employee', 'is_active' => true]);
        PayrollEntry::replaceFor($kim->id, '2026-08', [['label' => '기본급', 'amount' => 1]]);   // 다른 달 — 보존돼야
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무']));

        $c = Volt::test('erp.salesmen.index')->call('openImport')
            ->set('payrollFile', UploadedFile::fake()->createWithContent('09월급여대장.xls', file_get_contents($this->sheet($this->samplePeople()))))
            ->assertSet('importPreview.month', '2026-09')
            ->assertSet('importMatches.9', (string) $kim->id)
            ->assertSet('importMatches.15', '')
            ->set('importMatches.15', (string) $carlos->id)   // 칼로스마리오 → 카를로스 수동 연결
            ->call('applyImport')->assertHasNoErrors();

        $this->assertSame(4_583_333, PayrollEntry::totalFor($kim->id, '2026-09'));
        $this->assertSame(1, PayrollEntry::totalFor($kim->id, '2026-08'), '다른 달은 그대로');
        $this->assertSame(2_100_000, PayrollEntry::totalFor($carlos->id, '2026-09'));
        $this->assertSame('95', $carlos->fresh()->employee_no, '한 번 연결하면 사원번호를 기억한다');
        $this->assertSame('23', $kim->fresh()->employee_no);
        $this->assertNull(Salesman::where('name', '오류씨')->first(), '검산 틀린 줄·미매칭은 아무것도 만들지 않는다');
        $this->assertSame(2, PayrollEntry::where('month', '2026-09')->distinct()->count('salesman_id'));

        // 같은 달 재업로드 = 그 사람들만 덮어쓴다
        PayrollEntry::replaceFor($kim->id, '2026-09', [['label' => '기본급', 'amount' => 5]]);
        Volt::test('erp.salesmen.index')->call('openImport')
            ->set('payrollFile', UploadedFile::fake()->createWithContent('x.xls', file_get_contents($this->sheet($this->samplePeople()))))
            ->call('applyImport');
        $this->assertSame(4_583_333, PayrollEntry::totalFor($kim->id, '2026-09'));
    }

    /**
     * 🔎 판매수당 대조 (jin 2026-10-21) — 미리보기에서 급여대장 판매수당을 ERP 계산(그 달 정산금 + 조정 − 1/12)과 맞춰 본다.
     * 김연아: 급여대장 판매수당 2,083,333. 9월 확정 정산 2,083,333 이면 「일치」, 2,000,000 이면 +83,333.
     */
    public function test_preview_checks_sales_commission_against_the_erp(): void
    {
        $kim = Salesman::create(['name' => '김연아', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(['vehicle_number' => 'CK1', 'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'salesman_id' => $kim->id, 'purchase_price' => 10_000_000, 'purchase_date' => '2026-09-01', 'sale_price' => 12_000_000, 'sale_date' => '2026-09-02']);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 12_000_000, 'payment_date' => '2026-09-05', 'confirmed_at' => now()]);
        $s = Settlement::create(['vehicle_id' => $v->id, 'salesman_id' => $kim->id, 'settlement_type' => 'per_unit', 'per_unit_amount' => 2_083_333,
            'settlement_status' => 'confirmed', 'confirmed_at' => '2026-09-15', 'attributed_month' => '2026-09-01']);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무']));
        $file = fn () => UploadedFile::fake()->createWithContent('09.xls', file_get_contents($this->sheet($this->samplePeople())));

        Volt::test('erp.salesmen.index')->call('openImport')->set('payrollFile', $file())
            ->assertSeeHtml('data-import-commission="9"')
            ->assertSee('판매수당 대조 1명 모두 일치');

        $s->update(['per_unit_amount' => 2_000_000]);
        $html = Volt::test('erp.salesmen.index')->call('openImport')->set('payrollFile', $file())->html();
        $this->assertMatchesRegularExpression('/data-import-commission="9"[\s\S]{0,400}?\+83,333/u', $html);
        $this->assertStringContainsString('판매수당이 ERP 와 다른 사람 1명', $html);
    }

    public function test_sales_cannot_open_the_import(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'type' => 'employee']));
        Volt::test('erp.salesmen.index')->call('openImport')->assertStatus(403);
    }

    public function test_duplicate_links_are_refused(): void
    {
        $kim = Salesman::create(['name' => '김연아', 'type' => 'employee', 'is_active' => true]);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리']));

        Volt::test('erp.salesmen.index')->call('openImport')
            ->set('payrollFile', UploadedFile::fake()->createWithContent('x.xls', file_get_contents($this->sheet($this->samplePeople()))))
            ->set('importMatches.15', (string) $kim->id)
            ->call('applyImport')
            ->assertDispatched('notify', type: 'error');

        $this->assertNull(PayrollEntry::totalFor($kim->id, '2026-09'));
    }
}
