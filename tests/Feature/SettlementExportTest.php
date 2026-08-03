<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 정산 export (귀속월 기준 · 영업담당자별 시트) — jin 2026-08-03.
 *
 * 요청 배경: 차량관리 export 는 행이 차량이고 날짜축에 정산이 없어 "7월 귀속 정산분"을 못 뽑았다.
 */
class SettlementExportTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function financeUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function settlement(string $salesmanName, string $month, array $attrs = []): Settlement
    {
        $sm = Salesman::firstOrCreate(['name' => $salesmanName], ['type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'SE'.++$this->c,
            'nice_reg_vin' => 'VIN'.str_pad((string) $this->c, 6, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'salesman_id' => $sm->id,
        ], $attrs['vehicle'] ?? []));

        return Settlement::create(array_merge([
            'vehicle_id' => $v->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'pending',
            'attributed_month' => $month.'-01',
        ], $attrs['settlement'] ?? []));
    }

    /** @return array{sheets: array<string, string>, hasFormula: bool} 시트명 => 셀값 flat */
    private function loadWorkbook(string $content): array
    {
        $path = sys_get_temp_dir().'/settle_'.uniqid().'.xlsx';
        file_put_contents($path, $content);
        $book = IOFactory::load($path);
        $sheets = [];
        $hasFormula = false;
        foreach ($book->getAllSheets() as $sheet) {
            $values = [];
            foreach ($sheet->getRowIterator() as $r) {
                foreach ($r->getCellIterator() as $cell) {
                    $values[] = (string) $cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $hasFormula = true;
                    }
                }
            }
            $sheets[$sheet->getTitle()] = implode('|', $values);
        }
        @unlink($path);

        return ['sheets' => $sheets, 'hasFormula' => $hasFormula];
    }

    /** 🔑 요청의 본질 — 귀속월로 뽑히고, 담당자별 시트로 나뉜다. */
    public function test_exports_by_attributed_month_split_per_salesman(): void
    {
        $this->settlement('무사백', '2026-07');
        $this->settlement('무사백', '2026-07');
        $this->settlement('조하', '2026-07');
        $this->settlement('무사백', '2026-06');   // 다른 귀속월 → 제외

        $res = $this->actingAs($this->financeUser())
            ->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();
        ['sheets' => $sheets] = $this->loadWorkbook($res->streamedContent());

        $this->assertSame(['요약', '무사백', '조하'], array_keys($sheets), '시트 구성이 요약+담당자별이 아님');
        $this->assertStringContainsString('무사백', $sheets['요약']);
        $this->assertStringContainsString('조하', $sheets['요약']);
        // 6월 귀속 차량(SE4)은 어느 시트에도 없어야
        $this->assertStringNotContainsString('SE4', implode('|', $sheets), '다른 귀속월이 섞임');
    }

    /** 🔑 jin: "차량번호/차대번호는 필히 들어가야 해" */
    public function test_each_sheet_carries_vehicle_number_and_vin(): void
    {
        $this->settlement('무사백', '2026-07');

        $res = $this->actingAs($this->financeUser())
            ->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();
        ['sheets' => $sheets] = $this->loadWorkbook($res->streamedContent());

        $this->assertStringContainsString('차량번호', $sheets['무사백']);
        $this->assertStringContainsString('차대번호', $sheets['무사백']);
        $this->assertStringContainsString('SE1', $sheets['무사백'], '차량번호 값 누락');
        $this->assertStringContainsString('VIN000001', $sheets['무사백'], '차대번호 값 누락');
    }

    /** 실지급액은 확정 전이면 예정값 — 라벨로 구분되어야(급여 확정치로 오독 방지). */
    public function test_payout_column_is_labelled_as_preview(): void
    {
        $this->settlement('무사백', '2026-07');

        $res = $this->actingAs($this->financeUser())
            ->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();
        ['sheets' => $sheets] = $this->loadWorkbook($res->streamedContent());

        $this->assertStringContainsString('실지급액(예정)', $sheets['무사백']);
    }

    /** 담당자 미지정 정산도 유실되지 않고 '미지정' 시트로 간다. */
    public function test_unassigned_settlements_get_their_own_sheet(): void
    {
        $v = Vehicle::create(['vehicle_number' => 'NOSM1', 'sales_channel' => 'export']);
        Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => null,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'pending', 'attributed_month' => '2026-07-01',
        ]);

        $res = $this->actingAs($this->financeUser())
            ->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();
        ['sheets' => $sheets] = $this->loadWorkbook($res->streamedContent());

        $this->assertArrayHasKey('미지정', $sheets);
        $this->assertStringContainsString('NOSM1', $sheets['미지정']);
    }

    /** 화면 목록과 export 가 같은 집합이어야 — 귀속월 판정 단일출처(scopeAttributedMonth) 검증. */
    public function test_screen_and_export_select_the_same_settlements(): void
    {
        $this->settlement('무사백', '2026-07');
        $this->settlement('조하', '2026-07');
        $this->settlement('무사백', '2026-06');
        // attributed_month NULL → confirmed_at 앵커 fallback 경로도 태운다.
        $this->settlement('무사백', '2026-07', ['settlement' => [
            'attributed_month' => null, 'settlement_status' => 'confirmed', 'confirmed_at' => '2026-07-20',
        ]]);

        $scopeIds = Settlement::query()->attributedMonth('2026-07')->orderBy('id')->pluck('id')->all();

        // computed 프로퍼티라 viewData 로는 못 꺼낸다 — 인스턴스에서 직접 평가.
        $screenIds = Volt::actingAs($this->financeUser())
            ->test('erp.settlements.index')
            ->set('monthFilter', '2026-07')
            ->instance()->settlements->pluck('id')->sort()->values()->all();

        $this->assertSame($scopeIds, $screenIds, '화면 monthScope() 와 모델 scopeAttributedMonth() 가 다른 집합을 고름');
    }

    /** 정산 접근 권한 없는 role(영업)은 차단 — 마진 노출 방지 */
    public function test_sales_role_cannot_export_settlements(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->settlement('무사백', '2026-07');

        $this->actingAs($sales)->get(route('erp.settlements.export', ['month' => '2026-07']))
            ->assertForbidden();
    }

    /** export_logs 감사 기록 */
    public function test_export_is_logged(): void
    {
        $user = $this->financeUser();
        $this->settlement('무사백', '2026-07');

        $this->actingAs($user)->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();

        $this->assertDatabaseHas('export_logs', [
            'user_id' => $user->id, 'target' => 'settlements', 'row_count' => 1,
        ]);
    }

    /** 시트명 금지문자·31자·중복 처리 (엑셀 제약 — 위반 시 파일이 안 열린다) */
    public function test_sheet_names_are_sanitised(): void
    {
        $this->settlement('김/영업[A]', '2026-07');
        $this->settlement(str_repeat('가', 40), '2026-07');

        $res = $this->actingAs($this->financeUser())
            ->get(route('erp.settlements.export', ['month' => '2026-07']))->assertOk();
        ['sheets' => $sheets] = $this->loadWorkbook($res->streamedContent());

        foreach (array_keys($sheets) as $name) {
            $this->assertLessThanOrEqual(31, mb_strlen($name), "시트명 31자 초과: {$name}");
            $this->assertDoesNotMatchRegularExpression('/[\\\\\/\?\*\[\]:]/u', $name, "시트명 금지문자: {$name}");
        }
        $this->assertSame(count($sheets), count(array_unique(array_keys($sheets))), '시트명 중복');
    }
}
