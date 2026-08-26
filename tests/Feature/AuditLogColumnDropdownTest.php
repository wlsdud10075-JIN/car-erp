<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 감사로그 「컬럼 전체」 드롭박스 (jin 2026-08-26).
 *
 * 🚨 `column_name` 에 **컬럼명이 아닌 값**을 넣는 액션이 있다 —
 *    차량 삭제는 ★차량번호★ 를, 챗봇은 질문 유형을 적는다.
 *    그대로 두면 **차를 지울 때마다·질문할 때마다 목록이 하나씩 늘어난다.**
 *    운영 실측(2026-08-26): 87종 중 9종이 차량번호였다.
 *
 * 🔑 행 표시에서는 빼지 않는다 — 그 값이 어느 차였는지 알려주는 유일한 단서다.
 *    대신 대상 열이 삭제된 차량 번호를 보여주게 해서 **애초에 그럴 이유를 없앴다**.
 */
class AuditLogColumnDropdownTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicle(string $number): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => '영업'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $number, 'sales_channel' => 'export', 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
        ]);
    }

    /** 🚨 차량번호가 컬럼 목록에 들어가면 안 된다 — 지울 때마다 늘어난다. */
    public function test_deleted_vehicle_numbers_do_not_enter_the_column_dropdown(): void
    {
        $v = $this->vehicle('114마1731');
        foreach (['114마1731', '184저1259', '374저1182'] as $num) {
            AuditLog::create([
                'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
                'action' => 'vehicle_deleted_with_reason',
                'column_name' => $num, 'new_value' => '중복 등록',
            ]);
        }
        // 비교군 — 진짜 컬럼은 남아 있어야 한다
        AuditLog::create([
            'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
            'action' => 'updated', 'column_name' => 'sale_price', 'old_value' => '0', 'new_value' => '100',
        ]);

        $this->actingAs($this->admin());
        // 드롭다운 목록은 #[Computed] 라 뷰 데이터가 아니다 — 컴포넌트에서 직접 뽑는다.
        //   (화면 문자열로 검사하면 안 된다 — 차량번호는 행의 「대상」 열에도 나오기 때문이다.)
        $cols = Volt::test('admin.audit-logs.index')->instance()->distinctColumns();

        foreach (['114마1731', '184저1259', '374저1182'] as $num) {
            $this->assertArrayNotHasKey($num, $cols, "차량번호가 컬럼 목록에 있다: {$num}");
        }
        $this->assertArrayHasKey('sale_price', $cols, '진짜 컬럼까지 사라졌다');
    }

    /**
     * 대상 열이 **삭제된 차량**의 번호도 보여줘야 한다.
     * 이게 안 되면 `#170` 으로만 떠서, 차량번호를 column_name 에 적을 이유가 다시 생긴다.
     */
    public function test_the_target_column_still_names_a_deleted_vehicle(): void
    {
        $v = $this->vehicle('198허2457');
        AuditLog::create([
            'auditable_type' => Vehicle::class, 'auditable_id' => $v->id,
            'action' => 'vehicle_deleted_with_reason',
            'column_name' => '198허2457', 'new_value' => '테스트 삭제',
        ]);
        $v->delete();   // soft delete

        $this->actingAs($this->admin());
        $page = Volt::test('admin.audit-logs.index');

        $page->assertSeeText('198허2457');
    }

    /**
     * 🔒 목록에서 빼는 액션은 **한 곳에 모아 둔다.** 새 액션이 같은 형태로 들어오면
     *    여기에 추가하라는 신호가 되게, 상수 자체를 검사한다.
     */
    public function test_non_column_actions_are_declared_in_one_place(): void
    {
        $src = file_get_contents(resource_path('views/livewire/admin/audit-logs/index.blade.php'));

        $this->assertStringContainsString('NON_COLUMN_ACTIONS', $src);
        $this->assertStringContainsString("'vehicle_deleted_with_reason'", $src);
        $this->assertStringContainsString("'assistant_query'", $src);
        // 목록을 안 쓰고 다시 하드코딩하는 형태로 되돌아가면 실패한다.
        $this->assertStringContainsString('whereNotIn(\'action\', self::NON_COLUMN_ACTIONS)', $src);
    }
}
