<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShippingDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 수출신고번호·컨테이너번호 일괄 기입 (jin 2026-09-08) — 차량관리 「선택 N대」.
 *
 * 선적요청 묶음 화면에만 있던 기능을 선택 기반으로 옮긴 것이라 그쪽과 **같은 규칙**을 지켜야 한다:
 *   (1) 빈 칸은 「안 건드림」 — 한쪽만 채워도 되고, 이 도구로 값을 비울 수는 없다.
 *   (2) 대상은 사람이 고른 것이지만 **차량별 재인가**는 매번 한다(SKILLS §8 #26).
 *
 * 그리고 선적요청과 **다른** 것 하나 — 여기 대상은 묶음이 아니라 임의 선택이라 값이 섞일 수 있다.
 *   (3) 기존 값이 2종 이상인 칸을 채우려면 사람이 명시로 확인해야 한다(선박명 일괄에서 배운 규칙).
 */
class BulkVehicleNumberEntryTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(string $number, array $attrs = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => $number, 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
        ], $attrs));
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    public function test_writes_both_numbers_to_every_selected_vehicle(): void
    {
        $a = $this->vehicle('BN-1');
        $b = $this->vehicle('BN-2');
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', '12345-67-8901234')
            ->set('bulkNumContainer', '6.09_A RORO 12-33_5')
            ->call('applyBulkNumber');

        foreach ([$a, $b] as $v) {
            $v->refresh();
            $this->assertSame('12345-67-8901234', $v->export_declaration_number);
            $this->assertSame('6.09_A RORO 12-33_5', $v->container_number);
        }
    }

    /** (1) 빈 칸은 그대로 둔다 — 한쪽만 채우는 운영(신고번호만 먼저)이 실재한다. */
    public function test_a_blank_field_leaves_the_existing_value_alone(): void
    {
        $v = $this->vehicle('BN-3', ['container_number' => 'TEMU1234567']);
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', 'D-1')
            ->call('applyBulkNumber');

        $v->refresh();
        $this->assertSame('D-1', $v->export_declaration_number);
        $this->assertSame('TEMU1234567', $v->container_number, '빈 칸이 기존 값을 지웠다');
    }

    /** (3) 기존 값이 섞였는데 확인 없이 누르면 **아무것도 안 바뀐다**(경고만 뜨고 제자리). */
    public function test_mixed_existing_values_block_until_the_person_confirms(): void
    {
        $a = $this->vehicle('BN-4', ['export_declaration_number' => 'OLD-A']);
        $b = $this->vehicle('BN-5', ['export_declaration_number' => 'OLD-B']);
        $this->actingAs($this->clearanceUser());

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', 'NEW-1')
            ->call('applyBulkNumber');

        $this->assertSame('OLD-A', $a->refresh()->export_declaration_number);
        $this->assertSame('OLD-B', $b->refresh()->export_declaration_number);

        // 확인 체크 후엔 덮인다
        $c->set('bulkNumAck', true)->call('applyBulkNumber');
        $this->assertSame('NEW-1', $a->refresh()->export_declaration_number);
        $this->assertSame('NEW-1', $b->refresh()->export_declaration_number);
    }

    /**
     * 채우지 않은 칸이 섞여 있다고 막지 않는다 — 막으면 「컨테이너만 찍고 싶은데 신고번호가 섞여서
     * 못 누르는」 상태가 된다. 실제 차단은 **채운 칸**이 섞였을 때만.
     */
    public function test_a_field_left_blank_does_not_block_even_when_its_values_are_mixed(): void
    {
        $a = $this->vehicle('BN-6', ['export_declaration_number' => 'OLD-A']);
        $b = $this->vehicle('BN-7', ['export_declaration_number' => 'OLD-B']);
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->set('bulkNumContainer', 'TEMU7777777')
            ->call('applyBulkNumber');

        $this->assertSame('TEMU7777777', $a->refresh()->container_number);
        $this->assertSame('OLD-A', $a->export_declaration_number, '안 채운 칸이 덮였다');
    }

    /** 빈 값은 「다름」으로 세지 않는다 — 처음 채우는 게 주 용도라 매번 확인을 요구하면 아무도 안 읽는다. */
    public function test_empty_existing_values_are_not_counted_as_a_conflict(): void
    {
        $a = $this->vehicle('BN-8', ['export_declaration_number' => 'ONLY-ONE']);
        $b = $this->vehicle('BN-9');   // 비어 있음
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', 'NEW-2')
            ->call('applyBulkNumber');   // ack 없이

        $this->assertSame('NEW-2', $a->refresh()->export_declaration_number);
        $this->assertSame('NEW-2', $b->refresh()->export_declaration_number);
    }

    /** (2) 스코프 밖 차량은 건너뛴다 — 담당 밖 차에는 값이 안 들어간다. */
    public function test_a_vehicle_outside_the_users_scope_is_skipped(): void
    {
        // role='관리' = 본인 팀만. 통관 권한은 있으나 스코프가 좁은 유일한 조합이라 이걸로 검증한다.
        $manager = User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $salesUser = User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(), 'manager_user_id' => $manager->id,
        ]);
        $mine = Salesman::create(['user_id' => $salesUser->id, 'name' => 'MINE', 'type' => 'employee', 'is_active' => true]);
        $other = Salesman::create(['name' => 'OTHER', 'type' => 'employee', 'is_active' => true]);
        $a = $this->vehicle('BN-10', ['salesman_id' => $mine->id]);
        $b = $this->vehicle('BN-11', ['salesman_id' => $other->id]);
        $user = $manager;

        $result = app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::whereIn('id', [$a->id, $b->id]),
            ['export_declaration_number' => 'SCOPE-1'],
            $user,
            '테스트',
        );

        $this->assertSame('SCOPE-1', $a->refresh()->export_declaration_number);
        $this->assertNull($b->refresh()->export_declaration_number, '스코프 밖 차량에 값이 들어갔다');
        $this->assertSame(1, $result['applied']);
        $this->assertCount(1, $result['skipped']);

        // 미리보기도 같은 판정을 써야 한다 — 안 그러면 「대상 2대」라고 해놓고 1대만 바뀐다(SKILLS §8 #67).
        $this->actingAs($manager);
        $preview = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->get('bulkNumPreview');
        $this->assertSame(1, $preview['count']);
        $this->assertSame(1, $preview['no_scope']);
    }

    /** 통관 권한이 없으면 화면 액션이 403 — 단건 화면과 같은 권한(구멍 만들지 않기). */
    public function test_a_user_without_clearance_access_cannot_open_it(): void
    {
        $v = $this->vehicle('BN-12');
        $this->actingAs(User::factory()->create([
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]));

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v->id])
            ->call('openBulkNumber')
            ->assertStatus(403);
    }

    /**
     * 감사 — 세관에 신고한 번호가 수십 대 단위로 덮이므로 컬럼별 old→new 가 남아야 한다
     * (`export_declaration_number` 를 AUDITED_COLUMNS 에 등재한 이유).
     */
    public function test_every_change_is_audited(): void
    {
        $v = $this->vehicle('BN-13', ['export_declaration_number' => 'OLD-X']);
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', 'NEW-X')
            ->set('bulkNumAck', true)
            ->call('applyBulkNumber');

        $this->assertTrue(
            AuditLog::where('auditable_id', $v->id)->where('column_name', 'export_declaration_number')->exists(),
            '수출신고번호 변경이 감사에 안 남았다'
        );
        $this->assertTrue(
            AuditLog::where('auditable_id', $v->id)->where('action', 'bulk_shipping_date_applied')->exists(),
            '일괄 기입 출처가 안 남았다'
        );
    }

    /** 재적용해도 감사가 부풀지 않는다 — 값이 같으면 unchanged 로 건너뛴다. */
    public function test_reapplying_the_same_value_changes_nothing(): void
    {
        $v = $this->vehicle('BN-14', ['container_number' => 'TEMU1111111']);

        $result = app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::whereIn('id', [$v->id]),
            ['container_number' => 'TEMU1111111'],
            $this->clearanceUser(),
            '테스트',
        );

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['unchanged']);
    }

    /**
     * 같은 컬럼을 두 방식이 동시에 노리면 결과가 사람의 예상과 갈린다 — 예외로 막는다.
     * (컨테이너번호 「전체 기입」 ↔ 「접두어 치환」)
     */
    public function test_full_container_value_and_prefix_replace_cannot_be_combined(): void
    {
        $v = $this->vehicle('BN-15', ['container_number' => '6.08_G RORO 1-1']);

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::whereIn('id', [$v->id]),
            ['container_number' => 'TEMU2222222'],
            $this->clearanceUser(),
            '테스트',
            ['from' => '6.08_G', 'to' => '6.09_A'],
        );
    }

    /** 선택은 유지된다 — 번호를 찍은 그 묶음에 곧바로 면장을 올리는 흐름(서류 업로드와 의도적으로 다름). */
    public function test_the_selection_survives_so_the_document_can_be_uploaded_next(): void
    {
        $v = $this->vehicle('BN-16');
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$v->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', 'KEEP-1')
            ->call('applyBulkNumber')
            ->assertSet('shipDocIds', [$v->id]);
    }

    /** 봉인 — FIELDS 밖 컬럼은 예외. 지정한 필드 밖으로 번지지 않는다. */
    public function test_columns_outside_the_whitelist_are_refused(): void
    {
        $v = $this->vehicle('BN-17');

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::whereIn('id', [$v->id]),
            ['sale_price' => '1'],
            $this->clearanceUser(),
            '테스트',
        );
    }
}
