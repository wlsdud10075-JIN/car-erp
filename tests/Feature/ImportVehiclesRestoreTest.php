<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * vehicles:import — 소프트삭제 차량이 VIN 매칭되면 restore (2026-06-11).
 * 버그: 매칭이 withTrashed 라 삭제 차량을 잡는데 갱신만 하고 부활을 안 해 차량이 live 에서 묻혔음(운영 실측).
 */
class ImportVehiclesRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(string $plate, string $vin, string $salesman, int $price): string
    {
        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        $sh->setTitle('수출차량매입-2026');
        // 헤더(2행) — 라벨은 참고용, import 는 컬럼 letter 기준
        $sh->setCellValue('B2', '구입일자');
        $sh->setCellValue('D2', '차량번호');
        $sh->setCellValue('I2', '차대번호');
        $sh->setCellValue('J2', '담당자');
        $sh->setCellValue('P2', '구입금액');
        // 데이터(3행)
        $sh->setCellValue('B3', '2026-01-01');
        $sh->setCellValue('D3', $plate);
        $sh->setCellValue('I3', $vin);
        $sh->setCellValue('J3', $salesman);
        $sh->setCellValue('P3', $price);

        $path = sys_get_temp_dir().'/import_restore_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    public function test_soft_deleted_vehicle_is_restored_when_matched_by_vin(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);

        // 소프트삭제된 차량 (VIN=TESTVIN123, 옛 번호판·옛 매입가)
        $v = Vehicle::create([
            'vehicle_number' => 'OLD-PLATE', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'nice_reg_vin' => 'TESTVIN123', 'purchase_price' => 1_000_000,
        ]);
        $v->delete();
        $this->assertTrue(Vehicle::withTrashed()->find($v->id)->trashed(), '사전조건: 소프트삭제 상태');
        $this->assertSame(0, Vehicle::count());

        // 같은 VIN 의 엑셀 1행 import
        $path = $this->makeFixture('NEW-PLATE', 'TESTVIN123', 'TESTMAN', 2_000_000);
        $this->artisan('vehicles:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        // 복구됨(live) + 데이터 갱신
        $fresh = Vehicle::withTrashed()->find($v->id);
        $this->assertFalse($fresh->trashed(), '소프트삭제 차량이 복구 안 됨(묻힘)');
        $this->assertSame(1, Vehicle::count(), 'live 차량 1대여야');
        $this->assertSame(2_000_000, (int) $fresh->purchase_price, '매입가 갱신 안 됨');
        // 중복 생성 안 함 (같은 id 유지)
        $this->assertSame(1, Vehicle::withTrashed()->count(), '중복 차량 생성됨');
    }
}
