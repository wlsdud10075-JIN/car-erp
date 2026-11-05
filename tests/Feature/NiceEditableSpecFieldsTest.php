<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\DocValue;
use App\Services\Documents\Mappings\ClearanceSetMapping;
use App\Services\Documents\Mappings\DeregistrationCertificateMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 **기본정보 NICE 칸 교체** (jin 2026-10-02 «필요없는 건 빼고, 필요한 건 넣자 — 수정할 수 있어야 해»).
 *
 * 실측(10-02, 삭제 안 된 차량 중 NICE 조회된 차 · ssancarerp 1,125대 / heymanerp 289대):
 *   - 색상·변속기·구동방식·축거 = 자동 채움 0 · 서류 소비 0 → **화면에서 뺀다**(컬럼·수기값은 보존).
 *   - 제원관리번호·형식·최대출력·기통수·검사 시작/종료 = nice_raw 100% · 통관 SET/말소증이 찍는데
 *     화면엔 없어 못 고쳤다 → **전용 컬럼 + 편집 칸**, 서류는 「컬럼 우선 · raw 폴백」.
 *
 * 🔑 「마지막 한 걸음」(§8 #105) — 조회가 칸을 채우는지가 아니라 **저장해서 DB 에 남는지**까지 본다.
 */
class NiceEditableSpecFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private const RAW = [
        'engineSpec' => '4/1332',
        'resValidPeriod' => '2026-08-13 ~ 2028-08-12  주행거리:104938',
        'resSpecControlNo' => 'A041-00012-0000-1219',
        'fomNm' => 'I3W13-5D',
        'maxPower' => '152/5500',
        'mtrsFomNm' => 'H5H', 'resMotorType' => 'H5H',
    ];

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function vehicle(array $extra = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '12가1234', 'sales_channel' => 'export', 'currency' => 'USD',
            'nice_reg_owner_name' => '홍길동',
        ], $extra));
    }

    /** 📐 정적 — 죽은 4칸의 바인딩은 사라지고, 새 6칸의 바인딩은 있다. */
    public function test_panel_drops_dead_inputs_and_binds_the_new_ones(): void
    {
        $blade = file_get_contents(base_path(self::PANEL));

        foreach (['nice_reg_color', 'nice_spec_transmission', 'nice_spec_drive_type', 'nice_spec_wheelbase_str'] as $dead) {
            $this->assertStringNotContainsString('wire:model="'.$dead.'"', $blade, "죽은 칸 {$dead} 이 아직 화면에 있다");
            $this->assertStringNotContainsString('public string $'.$dead, $blade, "죽은 칸 {$dead} 프로퍼티가 남아 있다");
        }
        // 기본정보 색상·배기량 입력칸(jin 2026-10-02 2차) — 컬럼·프로퍼티는 남기고 **입력칸만** 없다(조회가 cc 를 계속 채운다).
        foreach (['color', 'cc_str'] as $hidden) {
            $this->assertStringNotContainsString('wire:model="'.$hidden.'"', $blade, "기본정보 {$hidden} 입력칸이 아직 있다");
        }
        $this->assertStringContainsString('wire:model="nice_spec_displacement_str"', $blade, '제원 배기량은 남긴다');
        $this->assertStringContainsString('wire:model="nice_spec_maker"', $blade, '제원 제조사는 남긴다');
        foreach (['nice_spec_control_no', 'nice_spec_form_name', 'nice_spec_max_power', 'nice_spec_cylinders_str', 'nice_inspection_start', 'nice_inspection_end'] as $new) {
            $this->assertStringContainsString('wire:model="'.$new.'"', $blade, "새 칸 {$new} 바인딩이 없다");
        }
    }

    /** 🧭 조회가 채우는 칸은 조회 섹션 안에 — 상단 일반 영역엔 차량번호·담당자만 (jin 2026-10-02). */
    public function test_lookup_filled_fields_live_inside_the_nice_sections(): void
    {
        $blade = file_get_contents(base_path(self::PANEL));
        $basic = strpos($blade, "x-show=\"tab === 'basic'\"");
        $reg = strpos($blade, "__('vehicle.panel.sec.nice_reg')", $basic);
        $spec = strpos($blade, "__('vehicle.panel.sec.nice_spec')", $reg);
        $end = strpos($blade, "x-show=\"tab === 'purchase'\"", $spec);
        $pos = fn (string $prop) => strpos($blade, 'wire:model="'.$prop.'"', $basic);

        foreach (['brand', 'model_type', 'year_str', 'mileage_str'] as $prop) {
            $this->assertTrue($reg < $pos($prop) && $pos($prop) < $spec, "{$prop} 는 NICE 등록정보 섹션 안에 있어야 한다");
        }
        $this->assertTrue($spec < $pos('weight_kg_str') && $pos('weight_kg_str') < $end, '중량은 NICE 제원정보 섹션 안에 있어야 한다');
        $this->assertTrue($basic < $pos('salesman_id_str') && $pos('salesman_id_str') < $reg, '담당자는 상단 일반 영역에 남는다');
    }

    /** 🔎 조회 → 새 6칸이 채워지고 → 저장 → DB 에 남는다. */
    public function test_lookup_fills_the_new_fields_and_save_persists_them(): void
    {
        config(['services.nice.provide_url' => 'https://ssancar.test/provide/api/nice-lookup/', 'services.nice.provide_token' => 't']);
        Http::fake(['*' => Http::response(['success' => true, 'message' => 'ok', 'data' => array_merge(self::RAW, [
            'resVehicleIdNo' => 'KMHXX123', 'resFinalOwner' => '홍길동', 'useFuelNm' => '휘발유',
        ])], 200)]);
        $v = $this->vehicle();
        $this->actingAs($this->admin());

        $c = Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('lookupNiceApi')
            ->assertSet('nice_spec_control_no', 'A041-00012-0000-1219')
            ->assertSet('nice_spec_form_name', 'I3W13-5D')
            ->assertSet('nice_spec_max_power', '152/5500')
            ->assertSet('nice_spec_cylinders_str', '4')
            ->assertSet('nice_inspection_start', '2026-08-13')
            ->assertSet('nice_inspection_end', '2028-08-12');

        $c->call('save')->assertHasNoErrors();

        $v->refresh();
        $this->assertSame('A041-00012-0000-1219', $v->nice_spec_control_no);
        $this->assertSame('I3W13-5D', $v->nice_spec_form_name);
        $this->assertSame('152/5500', $v->nice_spec_max_power);
        $this->assertSame(4, (int) $v->nice_spec_cylinders);
        $this->assertSame('2026-08-13', $v->nice_inspection_start->format('Y-m-d'));
        $this->assertSame('2028-08-12', $v->nice_inspection_end->format('Y-m-d'));
        $this->assertSame(self::RAW['fomNm'], $v->nice_raw['fomNm'], 'raw 원본은 그대로 보존');
    }

    /** ✏️ 사람이 고친 값이 저장되고, 서류는 raw 가 아니라 고친 값을 찍는다. */
    public function test_manual_edit_wins_over_raw_in_documents(): void
    {
        $v = $this->vehicle(['nice_raw' => self::RAW]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_spec_control_no', 'B999-00001-0000-0001')
            ->set('nice_spec_cylinders_str', '6')
            ->set('nice_inspection_end', '2029-01-31')
            ->call('save')->assertHasNoErrors();

        $v->refresh();
        $cells = ClearanceSetMapping::config()['cells'];
        $this->assertSame('B999-00001-0000-0001', $cells['G5']($v), '통관 G5 = 고친 제원관리번호');
        $this->assertSame('6', $cells['G12']($v), '통관 G12 = 고친 기통수');
        $this->assertSame('2029-01-31', $cells['I11']($v), '통관 I11 = 고친 검사 종료');
        $this->assertSame('2026-08-13', $cells['I10']($v), '안 고친 검사 시작은 raw 폴백');
        $this->assertSame('152/5500', $cells['G11']($v), '안 고친 최대출력은 raw 폴백');
        $this->assertSame('I3W13-5D', $cells['G4']($v), '안 고친 형식은 raw 폴백');
        $this->assertSame('B999-00001-0000-0001', DeregistrationCertificateMapping::config()['cells']['E9']($v), '말소증 E9 도 같은 출처');
    }

    /**
     * 📄 **마지막 한 걸음** — 패널에서 고친 값이 실제로 만들어진 서류 파일의 셀에 찍히나 (jin 2026-10-02
     * «기본정보에서 다 수정해도 서류에도 다 적용되고 하는거지?»). 매핑 클로저가 아니라 xlsx 를 생성해 읽는다.
     */
    public function test_generated_documents_print_values_edited_in_the_panel(): void
    {
        $v = $this->vehicle(['nice_raw' => self::RAW]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('nice_spec_control_no', 'EDIT-CTRL-0001')
            ->set('nice_spec_form_name', 'EDIT-FORM')
            ->set('nice_spec_max_power', '199/6000')
            ->set('nice_spec_cylinders_str', '8')
            ->set('nice_inspection_start', '2027-01-01')
            ->set('nice_inspection_end', '2029-01-31')
            ->set('nice_spec_displacement_str', '2999')
            ->set('nice_spec_year', '2021')
            ->call('save')->assertHasNoErrors();
        $v->refresh();

        foreach (['system', 'heyman', 'karaba'] as $set) {
            config(['company.template_set' => $set]);
            $list = (new DocumentFiller($v))->spreadsheet('clearance')->getSheetByName('구매리스트');
            $cell = fn (string $c) => (string) $list->getCell($c)->getValue();

            $this->assertSame('EDIT-FORM', $cell('G4'), "{$set} 형식");
            $this->assertSame('EDIT-CTRL-0001', $cell('G5'), "{$set} 제원관리번호");
            $this->assertSame('199/6000', $cell('G11'), "{$set} 최대출력");
            $this->assertSame('8', $cell('G12'), "{$set} 기통수");
            $this->assertSame('2027-01-01', $cell('I10'), "{$set} 검사 시작");
            $this->assertSame('2029-01-31', $cell('I11'), "{$set} 검사 종료");
            $this->assertSame('2999', $cell('G10'), "{$set} 배기량(제원)");
            $this->assertSame('2021', $cell('I5'), "{$set} 연도(제원 연식)");

            $dereg = (new DocumentFiller($v))->spreadsheet('deregistration_certificate');
            $this->assertSame('EDIT-CTRL-0001', (string) $dereg->getSheetByName('말소증')->getCell('E9')->getValue(), "{$set} 말소증 제원관리번호");
            $this->assertSame('EDIT-CTRL-0001', (string) $dereg->getSheetByName('영문말소증')->getCell('E9')->getValue(), "{$set} 영문말소증 Approval No");
        }
    }

    /** 🕳️ 백필 전 차량(컬럼 비고 raw 만) — 서류는 종전 그대로 raw 를 찍는다(순수 확대). */
    public function test_documents_fall_back_to_raw_when_columns_are_empty(): void
    {
        $v = new Vehicle(['nice_raw' => self::RAW]);

        $this->assertSame('A041-00012-0000-1219', DocValue::niceSpecControlNo($v));
        $this->assertSame('I3W13-5D', DocValue::niceFormName($v));
        $this->assertSame('152/5500', DocValue::niceMaxPower($v));
        $this->assertSame('4', DocValue::niceCylinders($v));
        $this->assertSame('2026-08-13', DocValue::niceInspectionStart($v));
        $this->assertSame('2028-08-12', DocValue::niceInspectionEnd($v));
    }

    /** 🔁 백필 — 빈 칸만 채우고, 수기값은 두고, dry-run 은 안 쓰고, 두 번째 실행은 0건. */
    public function test_backfill_fills_only_blank_columns_and_is_idempotent(): void
    {
        $fresh = $this->vehicle(['nice_raw' => self::RAW]);
        $manual = $this->vehicle(['vehicle_number' => '34나5678', 'nice_raw' => self::RAW, 'nice_spec_control_no' => 'MANUAL-1', 'nice_spec_cylinders' => 8]);
        $noRaw = $this->vehicle(['vehicle_number' => '56다9012']);

        $this->artisan('vehicles:sync-nice-spec-columns')->assertSuccessful();
        $this->assertNull($fresh->fresh()->nice_spec_control_no, 'dry-run 은 쓰지 않는다');

        $this->artisan('vehicles:sync-nice-spec-columns --apply')
            ->expectsOutputToContain('채울 차량 2대')
            ->assertSuccessful();

        $fresh->refresh();
        $this->assertSame('A041-00012-0000-1219', $fresh->nice_spec_control_no);
        $this->assertSame('I3W13-5D', $fresh->nice_spec_form_name);
        $this->assertSame('152/5500', $fresh->nice_spec_max_power);
        $this->assertSame(4, (int) $fresh->nice_spec_cylinders);
        $this->assertSame('2026-08-13', $fresh->nice_inspection_start->format('Y-m-d'));
        $this->assertSame('2028-08-12', $fresh->nice_inspection_end->format('Y-m-d'));

        $manual->refresh();
        $this->assertSame('MANUAL-1', $manual->nice_spec_control_no, '수기값은 덮지 않는다');
        $this->assertSame(8, (int) $manual->nice_spec_cylinders);
        $this->assertSame('I3W13-5D', $manual->nice_spec_form_name, '비어 있던 칸만 채운다');

        $this->assertNull($noRaw->fresh()->nice_spec_control_no, 'raw 없는 차는 대상 아님');

        $this->artisan('vehicles:sync-nice-spec-columns --apply')
            ->expectsOutputToContain('채울 차량 0대')
            ->assertSuccessful();
    }

    /**
     * 🚨 A차에서 NICE 조회 → B차를 열어 저장해도 A차 원본이 B차에 안 들어간다 (jin 2026-11-05, 3사 830대가 이렇게 섞였다).
     * 원인 = 편집 열기가 niceRaw 를 안 비웠다. 한 겹 더 = 원본 차대번호 ≠ 이 차 차대번호면 저장하지 않는다.
     */
    public function test_a_lookup_on_one_vehicle_never_lands_on_the_next_one(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message' => 'ok', 'data' => array_merge(self::RAW, [
            'resVehicleIdNo' => 'KNAAAAA111', 'resFinalOwner' => '홍길동',
        ])], 200)]);
        $a = $this->vehicle();
        $b = $this->vehicle(['vehicle_number' => '34나5678', 'nice_reg_vin' => 'WBBBBBB222']);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $a->id)->call('lookupNiceApi')
            ->call('openEdit', $b->id)->call('save')->assertHasNoErrors();

        $this->assertNull($b->fresh()->nice_raw, '편집 열기가 앞 차 원본을 비워야 한다');

        // 저장 가드 — 원본이 남아 있어도 차대번호가 다르면 저장 안 함
        $c = Volt::test('erp.vehicles.index')->call('openEdit', $b->id)
            ->set('niceRaw', ['resVehicleIdNo' => 'KNAAAAA111', 'resSpecControlNo' => 'X'])->call('save')->assertHasNoErrors();
        $this->assertNull($b->fresh()->nice_raw, '차대번호가 다른 원본은 저장하지 않는다');
    }

    /** 정리 명령 — 차대번호가 다른 원본만 비우고, 그 원본에서 온 칸만 비운다(사람이 고친 값·맞는 차는 그대로). 멱등. */
    public function test_clear_command_removes_only_foreign_raw_and_the_columns_it_filled(): void
    {
        $foreign = ['resVehicleIdNo' => 'KNAAAAA111', 'commCarName' => '모닝', 'resSpecControlNo' => 'A011-00048-0140-1414', 'fomNm' => 'TA55BG-L', 'engineSpec' => '3/998'];
        $bad = $this->vehicle(['vehicle_number' => '01부5398', 'nice_reg_vin' => 'WBATX310XJLB58905', 'nice_raw' => $foreign,
            'nice_spec_control_no' => 'A011-00048-0140-1414', 'nice_spec_form_name' => '수기로 고친 형식', 'nice_spec_cylinders' => 3]);
        $ok = $this->vehicle(['vehicle_number' => '08오4540', 'nice_reg_vin' => 'KNAAAAA111', 'nice_raw' => $foreign,
            'nice_spec_control_no' => 'A011-00048-0140-1414']);

        $this->artisan('vehicles:clear-mismatched-nice')->expectsOutputToContain('dry-run · 다른 차 NICE 원본 1대')->assertSuccessful();
        $this->assertNotNull($bad->fresh()->nice_raw, 'dry-run 은 안 바꾼다');

        $this->artisan('vehicles:clear-mismatched-nice --apply')->assertSuccessful();
        $bad->refresh();
        $this->assertNull($bad->nice_raw);
        $this->assertNull($bad->nice_spec_control_no, '그 원본에서 온 제원번호는 비운다');
        $this->assertNull($bad->nice_spec_cylinders);
        $this->assertSame('수기로 고친 형식', $bad->nice_spec_form_name, '사람이 고친 값은 둔다');
        $this->assertSame('A011-00048-0140-1414', $ok->fresh()->nice_spec_control_no, '맞는 차는 그대로');
        $this->assertNotNull($ok->fresh()->nice_raw);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $bad->id, 'action' => 'nice_mismatch_cleared', 'column_name' => 'nice_raw']);

        $this->artisan('vehicles:clear-mismatched-nice --apply')->expectsOutputToContain('다른 차 NICE 원본 0대');
    }
}
