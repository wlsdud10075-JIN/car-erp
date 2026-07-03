<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('livewire-tmp');
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

    /** xlsx 배열 → 업로드 파일 헬퍼. */
    private function uploadXlsx(array $rows): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray($rows, null, 'A1');
        Storage::fake('livewire-tmp');
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.xlsx';
        (new Xlsx($ss))->save($path);
        $file = UploadedFile::fake()->createWithContent('stmt.xlsx', file_get_contents($path));
        @unlink($path);

        return $file;
    }

    /** 구천육 서식(R2~, 번호 J열, 금액 F+G) — 앞공백 plate 매칭 + 중복 차번호 합산 + 비고 오염 차단. */
    public function test_gucheonyuk_layout_sums_duplicates_and_handles_whitespace_plate(): void
    {
        $this->actingAs($this->admin());
        $this->makeVehicle('134수1302');
        $this->makeVehicle('222머2974');

        $file = $this->uploadXlsx([
            ['NO', '날짜', '고객', '출발', '경유', '탁송비', '주유', '합계', '차종', '차량번호', '비고'],
            [1, '2026-06-15', '내수', 'x', 'y', 60000, 20000, '=SUM(F2:G2)', '레이', ' 134수1302', ''],   // 앞 공백 plate
            [2, '2026-06-15', '내수', 'x', 'y', 40000, 20000, '=SUM(F3:G3)', 'K3', '222머2974', ''],
            [3, '2026-06-16', '경매', 'x', 'y', 25000, 0, '=SUM(F4:G4)', '팰리', '134수1302', '312로4687 로교환'],  // 중복 + 비고 차번호꼴
        ]);

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportCompany', 'gucheonyuk')
            ->set('costImportFile', $file);

        $amounts = collect($c->get('costImportParsed')['matched'])->pluck('amount', 'number');
        $this->assertSame(105000, (int) $amounts['134수1302']);   // (60000+20000) + (25000+0) 합산
        $this->assertSame(60000, (int) $amounts['222머2974']);    // 공백 plate 매칭
        $this->assertArrayNotHasKey('312로4687', $amounts->toArray());  // 비고 차번호꼴 오염 없음
    }

    /** 현대A1 서식(R13~, 번호 M열, 금액 I+J) — 차종 숫자(Q5→5) 오파싱 차단 + 취소/재진행 2행 합산. */
    public function test_hyundai_a1_layout_ignores_model_digits_and_sums_cancel_row(): void
    {
        $this->actingAs($this->admin());
        $this->makeVehicle('303보2774');
        $this->makeVehicle('64러4771');

        $rows = array_fill(0, 12, array_fill(0, 14, ''));   // R1~R12 요약/헤더 (start=13이라 무시돼야)
        $rows[0][1] = '싼카 탁송내역';
        // A번호 B요청ID C요청일 D배정 E발주 F출발 G경유 H도착 I탁송 J추가 K합계 L내용 M차량번호 N차종
        $rows[] = [1, '56681781054246', '2026-06-10', '2026-06-10', '싼카', '대구', '', '시흥', 125000, 60000, '=SUM(I13:J13)', '주유비', '303보2774', '아우디 Q5'];
        $rows[] = [23, 'x', '2026-06-19', '2026-06-19', '싼카', '서울', '', '시흥', 0, 10000, '', '취소비', '64러4771', '폭스바겐'];
        $rows[] = [26, 'y', '2026-06-19', '2026-06-19', '싼카', '서울', '', '시흥', 31000, 0, '', '', '64러4771', '폭스바겐'];

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportCompany', 'hyundai_a1')
            ->set('costImportFile', $file = $this->uploadXlsx($rows));

        $amounts = collect($c->get('costImportParsed')['matched'])->pluck('amount', 'number');
        $this->assertSame(185000, (int) $amounts['303보2774']);   // I+J, 차종 'Q5'의 5 안 잡힘
        $this->assertSame(41000, (int) $amounts['64러4771']);     // (0+10000) + (31000+0) 합산
        $this->assertCount(2, $c->get('costImportParsed')['matched']);  // 요약행 오파싱 없음
    }

    /** 좌표 파서 회사는 붙여넣기 금지(422) — 셀 위치 고정이라 xlsx 전용. */
    public function test_coordinate_company_rejects_paste(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportCompany', 'gucheonyuk')
            ->set('costImportRaw', '134수1302 50000')
            ->call('parseCostImport')
            ->assertStatus(422);
    }

    /** 대상비용을 면허비로 전환하면 거래처가 기본값(뮤추얼)으로 리셋 — 현대A1 stale 방지. */
    public function test_switching_column_resets_company_default(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportCompany', 'hyundai_a1')
            ->set('costImportColumn', 'cost_license')
            ->assertSet('costImportCompany', 'mutual');
    }

    /** 성지 면허비는 업로드/붙여넣기 대신 선적요청 딥링크만 노출. */
    public function test_seongji_license_shows_deeplink_and_blocks_paste(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_license')
            ->set('costImportCompany', 'seongji')
            ->assertSee(__('vehicle.cost_import.seongji_goto'))
            ->set('costImportRaw', '134수1302 50000')
            ->call('parseCostImport')
            ->assertStatus(422);
    }

    /** 성지 딥링크(tab=cost)로 선적요청 진입 시 2차 비용 탭이 열린다. */
    public function test_shipping_request_tab_deeplink_opens_cost_tab(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index', ['tab' => 'cost'])
            ->assertSet('viewTab', 'cost');
    }
}
