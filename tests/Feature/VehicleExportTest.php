<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 차량 데이터 export (2026-06-29 라운드테이블 조건부 GO).
 *   - PII 마스킹(RRN 880717-*******·성명 김*진·주소 시군구까지) — 평문 미노출
 *   - formula injection 무력화(모든 셀 비-수식 TYPE)
 *   - admin/super 한정(비admin 403) + export_logs 감사 기록
 */
class VehicleExportTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(array $attrs = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '88가1234', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'nice_reg_owner_name' => '김혜진',
            'nice_reg_owner_rrn' => '880717-1234567',
            'nice_reg_owner_addr' => '경기도 수원시 권선구 권선로 308-5',
        ], $attrs));
    }

    /** @return string[] export xlsx 의 모든 셀 값 + 수식셀 존재 여부 */
    private function loadCells(string $content): array
    {
        $path = sys_get_temp_dir().'/export_'.uniqid().'.xlsx';
        file_put_contents($path, $content);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $values = [];
        $hasFormula = false;
        foreach ($sheet->getRowIterator() as $r) {
            foreach ($r->getCellIterator() as $cell) {
                $values[] = (string) $cell->getValue();
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $hasFormula = true;
                }
            }
        }
        @unlink($path);

        return ['flat' => implode('|', $values), 'hasFormula' => $hasFormula];
    }

    public function test_admin_export_masks_pii_and_logs(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle();

        $res = $this->actingAs($admin)->get(route('erp.vehicles.export'));
        $res->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        ['flat' => $flat] = $this->loadCells($res->streamedContent());

        $this->assertStringContainsString('880717-*******', $flat, 'RRN 마스킹 형식 누락');
        $this->assertStringNotContainsString('880717-1234567', $flat, '평문 RRN 노출');
        $this->assertStringNotContainsString('1234567', $flat, 'RRN 뒷자리 노출');
        $this->assertStringContainsString('김*진', $flat, '성명 마스킹 누락');
        $this->assertStringContainsString('경기도 수원시 권선구 ***', $flat, '주소 마스킹 누락');
        $this->assertStringNotContainsString('권선로', $flat, '상세주소 노출');

        $this->assertDatabaseHas('export_logs', [
            'user_id' => $admin->id, 'target' => 'vehicles', 'scope' => 'all', 'row_count' => 1,
        ]);
    }

    /** 평문 RRN 뒤 7자리(-\d{7}) 패턴이 파일 어디에도 없어야 (마스킹 누락 회귀 가드) */
    public function test_export_never_emits_unmasked_rrn(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle();

        $res = $this->actingAs($admin)->get(route('erp.vehicles.export'))->assertOk();
        ['flat' => $flat] = $this->loadCells($res->streamedContent());

        $this->assertDoesNotMatchRegularExpression('/-\d{7}/', $flat, '마스킹 안 된 RRN 뒤 7자리 노출');
    }

    public function test_export_neutralizes_formula_injection(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle(['vehicle_number' => '00가1', 'brand' => '=HYPERLINK("http://x")', 'nice_reg_vin' => 'FX1']);

        $res = $this->actingAs($admin)->get(route('erp.vehicles.export'))->assertOk();
        ['flat' => $flat, 'hasFormula' => $hasFormula] = $this->loadCells($res->streamedContent());

        $this->assertFalse($hasFormula, '수식 셀 존재 — formula injection 무력화 실패');
        $this->assertStringContainsString('=HYPERLINK("http://x")', $flat, '값이 텍스트로 보존돼야(무손실)');
    }

    public function test_export_respects_selected_columns(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle();

        $res = $this->actingAs($admin)
            ->get(route('erp.vehicles.export', ['cols' => 'vehicle_number,brand']))->assertOk();
        ['flat' => $flat] = $this->loadCells($res->streamedContent());

        $this->assertStringContainsString('차량번호', $flat);
        $this->assertStringContainsString('브랜드', $flat);
        $this->assertStringNotContainsString('진행상태', $flat, '선택 안 한 컬럼 노출');
        $this->assertStringNotContainsString('주민/법인번호(마스킹)', $flat, '선택 안 한 PII 컬럼 노출');
    }

    /** 알 수 없는 컬럼 key 는 화이트리스트 교집합에서 제외(클라이언트 우회 차단) */
    public function test_export_ignores_unknown_columns(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle();

        $res = $this->actingAs($admin)
            ->get(route('erp.vehicles.export', ['cols' => 'evilcol,vehicle_number']))->assertOk();
        ['flat' => $flat] = $this->loadCells($res->streamedContent());

        $this->assertStringContainsString('차량번호', $flat);
        $this->assertStringNotContainsString('evilcol', $flat);
        $this->assertStringNotContainsString('브랜드', $flat, '선택 안 한 컬럼이 나옴');
    }

    /** 정산 그룹(총마진·정산상태 등) 포함 — 정산 row 없어도 크래시 없이 빈칸 */
    public function test_export_includes_settlement_group(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $this->vehicle();   // 정산 없음

        $res = $this->actingAs($admin)->get(route('erp.vehicles.export'))->assertOk();
        ['flat' => $flat] = $this->loadCells($res->streamedContent());

        $this->assertStringContainsString('총마진', $flat);
        $this->assertStringContainsString('정산상태', $flat);
        $this->assertStringContainsString('실지급액', $flat);
    }

    public function test_non_admin_cannot_export(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);

        $this->actingAs($sales)->get(route('erp.vehicles.export'))->assertStatus(403);
    }
}
