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

    /**
     * 현대글로비스 2026-09분~ 서식(jin 2026-11-05) — 1행 머리글 · F 차량번호 · L 합계(부가세 포함) · 맨 끝 「합 계」 줄.
     * 번호 뒤에 차종이 붙은 칸(`35보9567벤츠`)·대괄호(`[08모5171]`)도 꺼내 읽는다 — 통째 일치였을 땐 그 줄이 조용히 빠졌다.
     */
    public function test_glovis_layout_reads_total_column_and_tolerates_text_around_the_plate(): void
    {
        $this->actingAs($this->admin());
        $this->makeVehicle('168소6394');
        $this->makeVehicle('35보9567');
        $this->makeVehicle('08모5171');

        // A순번 B접수일 C접수자 D출발 E도착 F차량번호 G비고 H탁송료 I실비 J공급가 K부가세 L합계
        $rows = [['순번', '접수일', '접수자', '출발지', '도착지', '차량번호', '비고', '탁송료', '실비', '공급가', 'VAT', '합계']];
        $rows[] = [1, '2026-09-01', '싼카1', '인천', '시흥', '168소6394', '', 30000, 0, 30000, 3000, 33000];
        $rows[] = [2, '2026-09-02', '싼카1', '서울', '시흥', '35보9567벤츠', '', 45000, 0, 45000, 4500, 49500];
        $rows[] = [3, '2026-09-03', '싼카1', '서울', '시흥', '[08모5171]  ', '주유', 30000, 10000, 40000, 4000, 44000];
        $rows[] = [4, '2026-09-04', '싼카1', '서울', '시흥', '1234가56789', '', 1000, 0, 1000, 100, 1100];   // 번호 아님
        $rows[] = ['합 계', '', '', '', '', '', '', 106000, 10000, 116000, 11600, 127600];

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_towing')
            ->set('costImportCompany', 'glovis')
            ->set('costImportFile', $this->uploadXlsx($rows));

        $amounts = collect($c->get('costImportParsed')['matched'])->pluck('amount', 'number');
        $this->assertSame(33000, (int) $amounts['168소6394'], 'L 합계(부가세 포함)');
        $this->assertSame(49500, (int) $amounts['35보9567'], '번호 뒤 차종이 붙어도 읽는다');
        $this->assertSame(44000, (int) $amounts['08모5171'], '대괄호·공백도');
        $this->assertCount(3, $c->get('costImportParsed')['matched'], '합계 줄·숫자에 붙은 가짜 번호 없음');
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

    // ── 보험료 — 데일리 가입이력조회 (jin 2026-10-01) ───────────────────

    /** 데일리 서식 행 — 실제 export 와 같은 열(A일시 B차량번호 C차대번호 D차종 E연식 F기간 G가입금액 …). */
    private function dailyRows(array $body): array
    {
        $head = ['가입일시', '차량번호', '차대번호', '차종', '연식', '가입기간', '가입금액', '보험시작', '보험종료', '가입자', '상태'];
        $rows = [$head];
        foreach ($body as [$plate, $vin, $amount]) {
            $rows[] = ['2026-09-30 15:41:37', $plate, $vin, 'TUCSON', '2016', 1, $amount, '2026.09.30', '2026.09.30', '싼카', '정상'];
        }
        $rows[] = ['', '', '', '', '', '', 3021740, '', '', '', ''];   // 마지막 합계행 — 번호가 비어 skip 돼야 한다

        return $rows;
    }

    private function vehicleWithVin(string $number, ?string $vin): Vehicle
    {
        $v = $this->makeVehicle($number);
        $v->forceFill(['nice_reg_vin' => $vin, 'cost_insurance' => 0])->save();

        return $v;
    }

    /** 🔑 번호 + 차대번호가 함께 맞아야 기입된다. 번호판은 재사용되므로 번호만으로는 다른 차에 들어간다. */
    public function test_daily_insurance_matches_plate_and_vin_together(): void
    {
        $this->actingAs($this->admin());
        $ok = $this->vehicleWithVin('18가5347', 'KMHJ381ABGU124746');
        $this->vehicleWithVin('38나2787', 'VF12RGJ1DHW792358');                   // 같은 번호, 다른 차대
        $noVin = $this->vehicleWithVin('284다1237', null);                          // ERP 에 차대 없음 → 번호만으로
        $renamed = $this->vehicleWithVin('99라9999', 'W1KZF8GB2MA971910');         // 번호판이 바뀐 차 → 차대로

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_insurance')
            ->assertSet('costImportCompany', 'daily')
            ->set('costImportFile', $this->uploadXlsx($this->dailyRows([
                ['18가5347', 'kmhj381abgu124746', 7650],      // 소문자 차대 — 정규화
                ['18가5347', 'KMHJ381ABGU124746', 7650],      // 두 번 가입 → 합산
                ['38나2787', 'XXXXXXXXXXXXXXXXX', 7650],      // 번호는 같은데 차대 불일치 → 미매칭
                ['284다1237', 'KNAG541BBPA218651', 7650],     // ERP 차대 비어 있음 → 번호만으로 매칭
                ['128마1954', 'W1KZF8GB2MA971910', 7650],     // 번호 없음 → 차대로 매칭
                ['77바7777', 'YV1UZ68TCK1245482', 7650],      // 둘 다 없음
            ])));

        $parsed = $c->get('costImportParsed');
        $byNumber = collect($parsed['matched'])->keyBy('number');
        $this->assertSame(15300, (int) $byNumber['18가5347']['amount'], '두 번 가입은 합산');
        $this->assertSame(7650, (int) $byNumber['284다1237']['amount'], 'ERP 차대가 비면 번호만으로 받는다');
        $this->assertSame('vin_only', $byNumber['99라9999']['note'], '번호판이 바뀐 차는 차대로 찾고 표식을 붙인다');
        $this->assertSame('128마1954', $byNumber['99라9999']['file_number']);
        $this->assertArrayNotHasKey('38나2787', $byNumber->toArray(), '차대 불일치는 기입 대상이 아니다');

        $unmatched = collect($parsed['unmatched'])->keyBy('number');
        $this->assertSame('vin_mismatch', $unmatched['38나2787']['reason']);
        $this->assertNull($unmatched['77바7777']['reason']);
        $this->assertCount(2, $unmatched);
        $c->assertSee(__('vehicle.cost_import.reason.vin_mismatch'))
            ->assertSee(__('vehicle.cost_import.note.vin_only', ['plate' => '128마1954']));

        $c->call('applyCostImport');
        $this->assertSame(15300, (int) $ok->fresh()->cost_insurance);
        $this->assertSame(7650, (int) $noVin->fresh()->cost_insurance);
        $this->assertSame(7650, (int) $renamed->fresh()->cost_insurance);
        $this->assertSame(0, (int) Vehicle::where('vehicle_number', '38나2787')->first()->cost_insurance, '차대 불일치 차에 기입됐다');
        $this->assertSame(30000, (int) $ok->fresh()->cost_towing, '보험료 기입이 탁송비를 건드렸다');
    }

    /**
     * 🔀 보험료는 **기존 금액에 더한다**(jin 2026-11-02 «지난번에 덮어쓰기 아니고 + 더해야 한다고 했었는데»).
     * 10-01 구현은 덮어쓰기였다 — ssancarerp 208저2815 가 7,650 → 15,300 으로 「교체」된 것을 보고 드러났다(실제론 실무자 수기 더하기였지만
     * 구조는 덮어쓰기). 같은 파일을 두 번 올리면 두 배가 되므로 파일 지문으로 막고, 알고도 올릴 땐 체크박스.
     */
    public function test_daily_insurance_adds_to_existing_amount_and_blocks_the_same_file_twice(): void
    {
        $this->actingAs($this->admin());
        $v = $this->vehicleWithVin('208저2815', 'KMHJ381ABGU124746');
        $v->forceFill(['cost_insurance' => 7650])->save();   // 지난달 보험료가 이미 있다
        $fresh = $this->vehicleWithVin('18가5347', 'KNAG541BBPA218651');   // 0원

        $rows = $this->dailyRows([
            ['208저2815', 'KMHJ381ABGU124746', 7650],
            ['18가5347', 'KNAG541BBPA218651', 7650],
        ]);
        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_insurance')
            ->set('costImportFile', $this->uploadXlsx($rows));

        // 미리보기: 더하기 안내 + 결과(기존+이번) 칸
        $c->assertSee(__('vehicle.cost_import.result'))
            ->assertSee(__('vehicle.cost_import.accumulate_hint', ['count' => 1]));
        $this->assertStringContainsString('data-cost-result>15,300<', $c->html(), '결과 칸 = 7,650 + 7,650');

        $c->call('applyCostImport');
        $this->assertSame(15300, (int) $v->fresh()->cost_insurance, '기존 7,650 에 7,650 을 더한다(덮어쓰기 금지)');
        $this->assertSame(7650, (int) $fresh->fresh()->cost_insurance, '0원이던 차는 파일 값 그대로');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $v->id, 'action' => 'bulk_cost_applied', 'column_name' => 'cost_insurance']);

        // 같은 파일 재업로드 → 차단(값 불변)
        $c2 = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_insurance')
            ->set('costImportFile', $this->uploadXlsx($rows))
            ->call('applyCostImport');
        $this->assertSame(15300, (int) $v->fresh()->cost_insurance, '같은 파일을 두 번 더하면 안 된다');
        $this->assertSame(7650, (int) $fresh->fresh()->cost_insurance);
        $this->assertTrue($c2->get('showCostImport'), '차단되면 모달이 닫히지 않는다');

        // 알고도 올리기(체크) → 한 번 더 더해진다
        $c2->set('costImportForce', true)->call('applyCostImport');
        $this->assertSame(22950, (int) $v->fresh()->cost_insurance);
        $this->assertSame(15300, (int) $fresh->fresh()->cost_insurance);
    }

    /** 탁송비는 종전대로 덮어쓰기 — 더하기는 보험료에만. */
    public function test_towing_import_still_overwrites(): void
    {
        $this->actingAs($this->admin());
        $v = $this->makeVehicle('12가3456');
        $v->forceFill(['cost_towing' => 30000])->save();

        $c = Volt::test('erp.vehicles.index')->call('openCostImport');
        $this->assertFalse($c->instance()->costImportAccumulates());
        $c->set('costImportParsed', ['matched' => [['id' => $v->id, 'number' => '12가3456', 'model' => '', 'current' => 30000, 'amount' => 50000, 'finalized' => false]], 'unmatched' => []])
            ->call('applyCostImport');
        $this->assertSame(50000, (int) $v->fresh()->cost_towing, '탁송비는 교체');
    }

    /** 보험료 거래처 목록은 데일리뿐 — 붙여넣기는 막힌다(좌표 파서). */
    /**
     * 🚚 디케이로지스 거래명세서 → 캐리비 (jin 2026-11-03) — 13행 머리글 · F 「차대NO」 칸 = 차량번호 · H 「비고」 = 금액(부가세 포함).
     *   덮어쓰기(jin «덮어쓰기») · 같은 차 두 줄은 합산 · 합계·회사정보 줄 skip · G(공급가액)를 읽지 않는다.
     */
    public function test_dk_logis_statement_overwrites_carry_cost_from_column_h(): void
    {
        $this->actingAs($this->admin());
        $a = $this->makeVehicle('159머1768');
        $a->forceFill(['cost_carry' => 50000])->save();   // 기존 값 — 덮어써야 한다
        $b = $this->makeVehicle('22가3333');
        $this->assertSame(['dk_logis'], Vehicle::COST_IMPORT_COMPANIES['cost_carry']);

        $rows = array_fill(0, 11, []);
        $rows[] = ['합 계 금 액', null, '\ 11,972,906원'];
        $rows[] = ['날짜', '상차지', null, '하차지', '차종', '차대NO', '공급가액', '비  고'];
        $rows[] = ['26-10-16', '오산 K카', null, '라성화물차', 'SONATA', '159머1768', 60000, 66000];
        $rows[] = ['26-10-17', '용인', null, '인천항', 'K5 2.0', '22가3333', 40000, 44000];
        $rows[] = ['26-10-20', '오산 K카', null, '인천항', 'SONATA', '159머1768', 10000, 11000];   // 같은 차 두 번째 줄
        $rows[] = ['합    계', null, null, '', null, null, 110000, 121000];
        $rows[] = ['(주)디케이로지스 TEL:032-710-2100'];

        $c = Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_carry')
            ->assertSet('costImportCompany', 'dk_logis')
            ->set('costImportFile', $this->uploadXlsx($rows));

        $amounts = collect($c->get('costImportParsed')['matched'])->pluck('amount', 'number');
        $this->assertSame(77000, (int) $amounts['159머1768'], 'H열(66,000 + 11,000) — G 공급가액이 아니다');
        $this->assertSame(44000, (int) $amounts['22가3333']);
        $this->assertCount(2, $amounts, '합계·회사정보 줄은 차량이 아니다');

        $c->call('applyCostImport');
        $this->assertSame(77000, (int) $a->fresh()->cost_carry, '기존 50,000 을 덮어쓴다(더하지 않는다)');
        $this->assertSame(44000, (int) $b->fresh()->cost_carry);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $a->id, 'action' => 'bulk_cost_applied', 'column_name' => 'cost_carry']);
    }

    public function test_daily_insurance_rejects_paste_and_other_companies(): void
    {
        $this->actingAs($this->admin());
        $this->assertSame(['daily'], Vehicle::COST_IMPORT_COMPANIES['cost_insurance']);

        Volt::test('erp.vehicles.index')
            ->call('openCostImport')
            ->set('costImportColumn', 'cost_insurance')
            ->set('costImportRaw', '18가5347 7650')
            ->call('parseCostImport')
            ->assertStatus(422);
    }

    /**
     * 🧠 명세서는 **값만** 읽는다 (2026-11-03 ssancarerp 128MB 초과 3건). `IOFactory::load()` 는 서식·빈 칸까지
     *   올려 서식이 넓게 칠해진 명세서에서 실측 758MB/53초(값만 = 60MB/14초). 메모리 초과는 Fatal 이라 catch 로 못 잡는다.
     *   정적 검사 — 기능 테스트의 작은 파일로는 원리상 안 드러난다.
     */
    public function test_statement_parsers_read_values_only(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringNotContainsString('IOFactory::load($this->costImportFile', $src);
        $this->assertMatchesRegularExpression('/function loadCostStatementSheet\(\)[\s\S]{0,600}setReadDataOnly\(true\)/', $src);
    }
}
