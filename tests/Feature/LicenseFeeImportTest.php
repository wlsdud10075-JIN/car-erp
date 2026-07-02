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
 * 면허비 명세서 일괄 기입 — 통관 면허비 월명세서(2행 1레코드) → 수출신고번호 묶음 매칭 → 합계 n/1 분배.
 */
class LicenseFeeImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function makeVehicle(string $number, string $decl): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $number,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 1000000,
            'export_declaration_number' => $decl,
            'cost_license' => 11000,
            'dhl_request' => false,
        ]);
    }

    /** 6월분 구조 xlsx 생성: 15/16행 = 신고번호 060019X(수량4·합계9,900), 17/18행 = 미매칭 060402X. */
    private function licenseFile(): UploadedFile
    {
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->fromArray([
            ['번호', '신고번호', '', '화주', '품명', '', '', 'BL', '수수료', '부가세'],        // 13
            ['수리일자', '', '업무', '실화주', '수량', '중량', '', '과세금액', '신고금액', '합계'],  // 14
            ['1', '44475-26-060019X', '', '헤이맨', 'MERCEDES S63', '', '', 'JUN.01', '9,000', '900'],   // 15 (홀: 신고번호/품명)
            ['', '2026-06-01', '수출', '헤이맨', '4', '8,435', '', '81,001,751', '53,745', '9,900'],      // 16 (짝: 수량4/합계9,900)
            ['2', '44475-26-060402X', '', '헤이맨', 'RENAULT SM6', '', '', 'JUN.05', '9,000', '900'],    // 17 (미매칭)
            ['', '2026-06-05', '수출', '헤이맨', '5', '9,345', '', '30,079,647', '19,958', '9,900'],      // 18
        ], null, 'A13');

        $path = tempnam(sys_get_temp_dir(), 'lic').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = UploadedFile::fake()->createWithContent('license.xlsx', file_get_contents($path));
        @unlink($path);

        return $file;
    }

    public function test_license_import_matches_by_declaration_and_splits_n1(): void
    {
        $this->actingAs($this->admin());
        // 060019X 를 4대가 공유 (1대는 끝 공백 포맷 — 정규화 매칭 확인).
        $a = $this->makeVehicle('91하9001', '44475-26-060019X');
        $b = $this->makeVehicle('91하9002', '44475-26-060019X');
        $c = $this->makeVehicle('91하9003', '44475-26-060019X ');   // 끝 공백
        $d = $this->makeVehicle('91하9004', '44475-26-060019X');

        $comp = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_license')
            ->set('costImportFile', $this->licenseFile());   // 자동 파싱(updatedCostImportFile)

        $parsed = $comp->get('costImportParsed');
        $this->assertSame('license', $parsed['mode']);
        $this->assertCount(4, $parsed['matched']);          // 060019X 4대
        $this->assertCount(1, $parsed['unmatched']);        // 060402X (ERP 없음)
        $this->assertSame('44475-26-060402X', $parsed['unmatched'][0]['number']);
        // 묶음 요약: 060019X 수량4 = 매칭4 (불일치 없음), 대당 2,475
        $this->assertCount(1, $parsed['groups']);
        $this->assertSame(2475, $parsed['groups'][0]['per']);
        $this->assertFalse($parsed['groups'][0]['mismatch']);

        $comp->call('applyCostImport')->assertSet('showCostImport', false);

        foreach ([$a, $b, $c, $d] as $v) {
            $this->assertSame(2475, (int) $v->fresh()->cost_license);
        }
    }

    public function test_qty_mismatch_flags_and_splits_by_file_qty(): void
    {
        $this->actingAs($this->admin());
        // 파일 수량은 4인데 ERP엔 3대만 존재 → 대당 = 9,900/4 = 2,475 (수량 기준), 불일치 경고.
        $a = $this->makeVehicle('91하9001', '44475-26-060019X');
        $b = $this->makeVehicle('91하9002', '44475-26-060019X');
        $c = $this->makeVehicle('91하9003', '44475-26-060019X');

        $comp = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_license')
            ->set('costImportFile', $this->licenseFile());

        $parsed = $comp->get('costImportParsed');
        $this->assertCount(3, $parsed['matched']);
        $this->assertSame(2475, $parsed['groups'][0]['per']);   // 수량(4) 기준 — 매칭수(3) 아님
        $this->assertTrue($parsed['groups'][0]['mismatch']);

        $comp->call('applyCostImport');
        foreach ([$a, $b, $c] as $v) {
            $this->assertSame(2475, (int) $v->fresh()->cost_license);
        }
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
