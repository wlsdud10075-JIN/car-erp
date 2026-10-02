<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
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
}
