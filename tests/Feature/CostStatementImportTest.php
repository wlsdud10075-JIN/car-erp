<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 탁송비 명세서 일괄 기입 도구(차량목록 모달) — 붙여넣기 파싱 → 차량번호 매칭 → 일괄 기입.
 */
class CostStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function makeVehicle(string $number): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 1000000,
            'cost_towing' => 30000,
            'dhl_request' => false,
        ]);
    }

    public function test_parse_matches_by_vehicle_number_and_takes_total_column(): void
    {
        $this->actingAs($this->admin());
        $v1 = $this->makeVehicle('393어3064');
        $v2 = $this->makeVehicle('14주4848');

        // 위카 명세서 붙여넣기 형태: 순번 날짜 출발 도착 차량번호 차종 공급가 유류비 합계
        $raw = "1  2026-06-01  부천  시흥  393어3064  SM6  25,000  10,000  35,000\n"
            ."2  2026-06-04  수원  시흥  14주4848  AUDI  45,000  20,000  65,000\n"
            .'3  2026-06-04  수원  시흥  999없9999  UNKNOWN  30,000  30,000';

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportRaw', $raw)
            ->call('parseCostImport');

        $parsed = $c->get('costImportParsed');
        $this->assertCount(2, $parsed['matched']);
        $this->assertCount(1, $parsed['unmatched']);
        // 합계(마지막 숫자) 를 금액으로
        $amounts = collect($parsed['matched'])->pluck('amount', 'number');
        $this->assertSame(35000, (int) $amounts['393어3064']);
        $this->assertSame(65000, (int) $amounts['14주4848']);
        $this->assertSame('999없9999', $parsed['unmatched'][0]['number']);
    }

    public function test_apply_overwrites_cost_towing_on_matched_vehicles(): void
    {
        $this->actingAs($this->admin());
        $v1 = $this->makeVehicle('393어3064');
        $v2 = $this->makeVehicle('14주4848');

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportRaw', "393어3064  35000\n14주4848  65000")
            ->call('parseCostImport')
            ->call('applyCostImport')
            ->assertSet('showCostImport', false);

        $this->assertSame(35000, (int) $v1->fresh()->cost_towing);
        $this->assertSame(65000, (int) $v2->fresh()->cost_towing);
    }

    public function test_xlsx_upload_parses_and_matches(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle('393어3064');

        // 위카 레이아웃(E=차량번호, J=합계) 유사 xlsx 생성.
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->fromArray([
            ['', '', '', '', '차량번호', '차종', '공급가', '유류비', '합계'],
            ['1', '2026-06-01', '부천', '시흥', '393어3064', 'SM6', 25000, 10000, 35000],
        ], null, 'A1');
        \Illuminate\Support\Facades\Storage::fake('livewire-tmp');
        $path = tempnam(sys_get_temp_dir(), 'wika').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = UploadedFile::fake()->createWithContent('wika.xlsx', file_get_contents($path));

        // 파일 선택만으로 자동 파싱(updatedCostImportFile) → 미리보기 → 일괄 기입. 「파일 읽기」 별도 호출 없이.
        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportFile', $file)
            ->assertSet('costImportParsed.matched.0.number', '393어3064')   // 자동 파싱됨
            ->call('applyCostImport');

        $this->assertSame(35000, (int) $v->fresh()->cost_towing);
        @unlink($path);
    }

    public function test_sales_role_cannot_open_tool(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->assertStatus(403);
    }
}
