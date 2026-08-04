<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 차등정산 on/off · 승계 바이어 — 화면 배선 + 권한 (jin 2026-08-04).
 * 둘 다 정산 금액을 바꾸므로 canApprove() — [관리] 이상(role 관리 · 업무관리자 · 최고관리자 · 시스템관리자) — 만 수정 가능해야 한다.
 */
class SettlementTierScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::create([
            'name' => '대표', 'email' => 'boss@t.test', 'password' => bcrypt('x'),
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function salesUser(): User
    {
        return User::create([
            'name' => '영업', 'email' => 'sales@t.test', 'password' => bcrypt('x'),
            'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(),
        ]);
    }

    /**
     * 「[관리] 이상」 = role 관리 · 업무관리자(manager) · 최고관리자(admin) · 시스템관리자(super).
     * jin 2026-08-04 지적 — 최고관리자는 당연히 되고 업무관리자도 포함이다. 넷 다 되는지 박제한다.
     */
    public static function approverProvider(): array
    {
        return [
            '시스템관리자(super)' => ['super', null],
            '최고관리자(admin)' => ['admin', null],
            '업무관리자(manager)' => ['manager', null],
            'role 관리' => ['user', '관리'],
        ];
    }

    #[DataProvider('approverProvider')]
    public function test_every_management_level_can_toggle_tier(string $permission, ?string $role): void
    {
        $u = User::create([
            'name' => '관리자'.$permission, 'email' => $permission.'@t.test', 'password' => bcrypt('x'),
            'permission' => $permission, 'role' => $role ?? '관리', 'email_verified_at' => now(),
        ]);
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);

        Volt::actingAs($u)->test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('per_unit_tier_enabled', true)
            ->call('save');

        $this->assertTrue((bool) $sm->fresh()->per_unit_tier_enabled, $permission.' 은 tier 를 켤 수 있어야 한다');
    }

    public function test_admin_can_toggle_tier_and_change_is_audited(): void
    {
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);
        $this->assertFalse((bool) $sm->per_unit_tier_enabled);

        Volt::actingAs($this->admin())->test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->assertSet('per_unit_tier_enabled', false)
            ->set('per_unit_tier_enabled', true)
            ->call('save');

        $this->assertTrue((bool) $sm->fresh()->per_unit_tier_enabled);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Salesman::class,
            'auditable_id' => $sm->id,
            'column_name' => 'per_unit_tier_enabled',
            'new_value' => '1',
        ]);
    }

    /** 🚨 영업이 프로퍼티를 직접 주입해도 tier 가 켜지면 안 된다 (화면 비노출 ≠ 방어). */
    public function test_sales_user_cannot_toggle_tier_by_injecting_property(): void
    {
        $sm = Salesman::create(['name' => '무사백', 'type' => 'employee', 'is_active' => true]);

        Volt::actingAs($this->salesUser())->test('erp.salesmen.index')
            ->call('openEdit', $sm->id)
            ->set('per_unit_tier_enabled', true)
            ->call('save');

        $this->assertFalse((bool) $sm->fresh()->per_unit_tier_enabled, '영업은 tier 를 못 켠다');
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Salesman::class, 'column_name' => 'per_unit_tier_enabled',
        ]);
    }

    public function test_admin_can_mark_buyer_inherited(): void
    {
        $prev = Salesman::create(['name' => '퇴사자', 'type' => 'employee', 'is_active' => false]);
        $buyer = Buyer::create(['name' => 'VILLA KOHA']);

        Volt::actingAs($this->admin())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->assertSet('is_inherited', false)
            ->set('is_inherited', true)
            ->set('inherited_from_salesman_id_str', (string) $prev->id)
            ->set('inherited_at', '2026-08-04')
            ->call('save');

        $fresh = $buyer->fresh();
        $this->assertTrue((bool) $fresh->is_inherited);
        $this->assertSame($prev->id, $fresh->inherited_from_salesman_id);
        $this->assertSame('2026-08-04', $fresh->inherited_at?->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Buyer::class, 'column_name' => 'is_inherited', 'new_value' => '1',
        ]);
    }

    /** 승계 해제 시 원담당자·승계일도 함께 비워진다 — "ON 일 때만 부속 정보 존재" 불변식. */
    public function test_unmarking_inherited_clears_companion_fields(): void
    {
        $prev = Salesman::create(['name' => '퇴사자', 'type' => 'employee', 'is_active' => false]);
        $buyer = Buyer::create([
            'name' => 'KOHA', 'is_inherited' => true,
            'inherited_from_salesman_id' => $prev->id, 'inherited_at' => '2026-08-04',
        ]);

        Volt::actingAs($this->admin())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('is_inherited', false)
            ->call('save');

        $fresh = $buyer->fresh();
        $this->assertFalse((bool) $fresh->is_inherited);
        $this->assertNull($fresh->inherited_from_salesman_id);
        $this->assertNull($fresh->inherited_at);
    }

    public function test_sales_user_cannot_mark_buyer_inherited(): void
    {
        $buyer = Buyer::create(['name' => 'KOHA2']);

        Volt::actingAs($this->salesUser())->test('erp.buyers.index')
            ->call('openEdit', $buyer->id)
            ->set('is_inherited', true)
            ->call('save');

        $this->assertFalse((bool) $buyer->fresh()->is_inherited);
    }

    /** 감사로그 화면이 영문 컬럼명을 그대로 뱉지 않아야 한다 (SKILLS §8 #41). */
    public function test_new_columns_have_korean_labels(): void
    {
        $this->assertSame('차등 정산(tier) 적용', ColumnLabel::column(Salesman::class, 'per_unit_tier_enabled'));
        $this->assertSame('승계받은 바이어', ColumnLabel::column(Buyer::class, 'is_inherited'));
    }
}
