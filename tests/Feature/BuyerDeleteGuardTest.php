<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\SavingsStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BuyerRebindService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 삭제 가드 + 이관 (jin 2026-09-23 «리스트에 넣어줘», SKILLS §8 #110).
 *
 * 실사고: ssancarerp ATLAS #393 이 중복 등록 21분 뒤 삭제됐는데 차량 29대가 그 행을 계속 가리켜
 * 화면·서류에서 바이어가 빈칸이 됐다(정산 마감 차 포함). 여기서는 그 상태를 **만들 수 없게** 막고,
 * 이미 매달린 것은 `buyers:check-dangling` 이 찾아 `--rebind` 로 넘긴다.
 */
class BuyerDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function vehicle(Buyer $buyer, string $column = 'buyer_id', string $number = '12가0001'): Vehicle
    {
        $attrs = ['vehicle_number' => $number, 'sales_channel' => 'export', 'purchase_price' => 1_000_000];
        if ($column === 'buyer_id') {
            $attrs += ['buyer_id' => $buyer->id, 'sale_price' => 5000, 'sale_date' => '2026-09-01', 'currency' => 'USD', 'exchange_rate' => 1400];
        } else {
            $attrs[$column] = $buyer->id;
        }

        return Vehicle::create($attrs);
    }

    // ── 모델 가드 ───────────────────────────────────────────────────────

    public function test_a_buyer_with_vehicles_cannot_be_deleted_by_a_logged_in_user(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'ATLAS GROUP', 'is_active' => true]);
        $this->vehicle($buyer);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('차량 1대');
        $buyer->delete();
    }

    /** 통관·B/L 바이어 칸만 가리키는 차도 참조다 — `Buyer::vehicles()`(buyer_id) 만 세면 놓친다. */
    public function test_export_and_bl_buyer_columns_count_as_references(): void
    {
        $this->actingAs($this->admin());
        foreach (['export_buyer_id', 'bl_buyer_id'] as $col) {
            $buyer = Buyer::create(['name' => "REF {$col}", 'is_active' => true]);
            $this->vehicle($buyer, $col, '12가'.substr(md5($col), 0, 4));

            try {
                $buyer->delete();
                $this->fail("{$col} 참조가 있는데 삭제됐다");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('차량 1대', $e->getMessage());
            }
            $this->assertNotNull(Buyer::find($buyer->id), "{$col}: 삭제가 막히지 않았다");
        }
    }

    public function test_a_buyer_with_money_ledger_rows_cannot_be_deleted_even_without_vehicles(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'RICH', 'is_active' => true]);
        SavingsStatus::create(['buyer_id' => $buyer->id, 'currency' => 'USD', 'transaction_type' => 'EARNED', 'savings' => 100, 'balance' => 100]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('적립금');
        $buyer->delete();
    }

    public function test_a_clean_buyer_still_deletes(): void
    {
        $this->actingAs($this->admin());
        $buyer = Buyer::create(['name' => 'CLEAN', 'is_active' => true]);

        $buyer->delete();

        $this->assertSoftDeleted('buyers', ['id' => $buyer->id]);
    }

    /** 시더·artisan(로그인 없음)은 종전대로 — 「이미 매달린 데이터」를 재현하는 테스트들이 이 길을 쓴다. */
    public function test_unauthenticated_paths_are_not_guarded(): void
    {
        $buyer = Buyer::create(['name' => 'SEEDED', 'is_active' => true]);
        $this->vehicle($buyer);

        $buyer->delete();

        $this->assertSoftDeleted('buyers', ['id' => $buyer->id]);
    }

    // ── 이관 서비스 ─────────────────────────────────────────────────────

    public function test_rebind_moves_every_buyer_column_and_the_consignees_with_audit_rows(): void
    {
        $this->actingAs($this->admin());
        $from = Buyer::create(['name' => 'DUP #393', 'is_active' => true]);
        $to = Buyer::create(['name' => 'LIVE #47', 'is_active' => true]);
        $sale = $this->vehicle($from, 'buyer_id', '12가0001');
        $export = $this->vehicle($from, 'export_buyer_id', '12가0002');
        $both = Vehicle::create(['vehicle_number' => '12가0003', 'sales_channel' => 'export', 'purchase_price' => 1, 'export_buyer_id' => $from->id, 'bl_buyer_id' => $from->id]);
        $cons = Consignee::withTrashed()->where('buyer_id', $from->id)->first();   // created 훅이 만든 자동 컨사이니
        $this->assertNotNull($cons);

        $dry = BuyerRebindService::rebind($from, $to, apply: false);
        $this->assertFalse($dry['applied']);
        $this->assertCount(3, $dry['vehicles']);
        $this->assertSame($from->id, $sale->fresh()->buyer_id, 'dry-run 이 값을 바꿨다');

        $plan = BuyerRebindService::rebind($from, $to, apply: true);

        $this->assertSame($to->id, $sale->fresh()->buyer_id);
        $this->assertSame($to->id, $export->fresh()->export_buyer_id);
        $this->assertSame($to->id, $both->fresh()->export_buyer_id);
        $this->assertSame($to->id, $both->fresh()->bl_buyer_id);
        $this->assertSame($to->id, $cons->fresh()->buyer_id, '컨사이니가 따라오지 않았다');
        $this->assertSame(0, BuyerRebindService::vehiclesOf($from)->count());
        $this->assertSame(['buyer_id'], $plan['vehicles'][0]['columns']);

        // 감사로그 — 차량×컬럼 4행(09-23 운영 스크립트와 같은 형태) + 바이어 이관 이벤트 1행
        $this->assertSame(4, AuditLog::where('auditable_type', Vehicle::class)->where('action', 'updated')->whereIn('column_name', array_keys(BuyerRebindService::VEHICLE_COLUMNS))->count());
        $this->assertSame(1, AuditLog::where('auditable_type', Buyer::class)->where('action', 'buyer_rebound')->count());

        // 이관 뒤엔 가드가 통과한다
        $from->delete();
        $this->assertSoftDeleted('buyers', ['id' => $from->id]);
    }

    public function test_rebind_refuses_same_or_deleted_target_and_buyers_with_money(): void
    {
        $this->actingAs($this->admin());
        $from = Buyer::create(['name' => 'A', 'is_active' => true]);
        $gone = Buyer::create(['name' => 'GONE', 'is_active' => true]);
        Buyer::withTrashed()->whereKey($gone->id)->update(['deleted_at' => now()]);
        $this->vehicle($from);

        try {
            BuyerRebindService::rebind($from, $from, true);
            $this->fail('자기 자신으로 이관됐다');
        } catch (\DomainException) {
        }
        try {
            BuyerRebindService::rebind($from, Buyer::withTrashed()->find($gone->id), true);
            $this->fail('삭제된 바이어로 이관됐다');
        } catch (\DomainException) {
        }

        $rich = Buyer::create(['name' => 'RICH', 'is_active' => true]);
        SavingsStatus::create(['buyer_id' => $rich->id, 'currency' => 'USD', 'transaction_type' => 'EARNED', 'savings' => 1, 'balance' => 1]);
        try {
            BuyerRebindService::rebind($rich, $from, true);
            $this->fail('원장이 있는 바이어가 이관됐다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('적립금', $e->getMessage());
        }
    }

    // ── 화면 ────────────────────────────────────────────────────────────

    public function test_screen_opens_the_gate_and_rebinds_then_deletes(): void
    {
        $this->actingAs($this->admin());
        $from = Buyer::create(['name' => 'DUP', 'is_active' => true]);
        $to = Buyer::create(['name' => 'LIVE', 'is_active' => true]);
        $v = $this->vehicle($from);

        $c = Volt::test('erp.buyers.index')->call('delete', $from->id);
        $c->assertSet('showDeleteGate', true)->assertSet('deleteTargetId', $from->id);
        $this->assertNotNull(Buyer::find($from->id), '모달을 열었는데 지워졌다');
        $c->assertSee(__('buyer.delete_gate.confirm_btn'));

        // 대상 없이 확정 → 검증 에러, 아직 안 지워짐
        $c->call('confirmDeleteWithRebind')->assertHasErrors(['deleteRebindTargetStr']);
        $this->assertNotNull(Buyer::find($from->id));

        $c->set('deleteRebindTargetStr', (string) $to->id)->call('confirmDeleteWithRebind')->assertHasNoErrors();

        $this->assertSoftDeleted('buyers', ['id' => $from->id]);
        $this->assertSame($to->id, $v->fresh()->buyer_id);
        $c->assertSet('showDeleteGate', false);
    }

    public function test_screen_blocks_a_buyer_with_money_with_a_toast_and_no_modal(): void
    {
        $this->actingAs($this->admin());
        $rich = Buyer::create(['name' => 'RICH', 'is_active' => true]);
        SavingsStatus::create(['buyer_id' => $rich->id, 'currency' => 'USD', 'transaction_type' => 'EARNED', 'savings' => 1, 'balance' => 1]);

        Volt::test('erp.buyers.index')->call('delete', $rich->id)
            ->assertSet('showDeleteGate', false)
            ->assertDispatched('notify', type: 'error');

        $this->assertNotNull(Buyer::find($rich->id));
    }

    public function test_screen_deletes_a_clean_buyer_immediately(): void
    {
        $this->actingAs($this->admin());
        $b = Buyer::create(['name' => 'CLEAN', 'is_active' => true]);

        Volt::test('erp.buyers.index')->call('delete', $b->id)->assertSet('showDeleteGate', false);

        $this->assertSoftDeleted('buyers', ['id' => $b->id]);
    }

    /** (나) jin 2026-09-26 — 삭제·이관은 관리 role·업무관리자·admin·super(canApprove) 만. 영업은 버튼도 없고 호출도 403. */
    public function test_only_approvers_can_delete_or_rebind(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);
        $b = Buyer::create(['name' => 'CLEAN', 'is_active' => true]);

        Volt::test('erp.buyers.index')->assertDontSeeHtml('wire:click.stop="delete(');
        Volt::test('erp.buyers.index')->call('delete', $b->id)->assertForbidden();   // Livewire 는 abort 를 응답 상태로 돌려준다
        $this->assertNotNull(Buyer::find($b->id));

        foreach ([['permission' => 'user', 'role' => '관리'], ['permission' => 'manager', 'role' => '관리'], ['permission' => 'admin', 'role' => '관리'], ['permission' => 'super', 'role' => '관리']] as $attrs) {
            $this->actingAs(User::factory()->create($attrs + ['email_verified_at' => now()]));
            $x = Buyer::create(['name' => 'X '.$attrs['permission'], 'is_active' => true]);
            $c = Volt::test('erp.buyers.index');
            if (in_array($attrs['permission'], ['admin', 'super'], true)) {
                // 목록 전체가 보이는 계정에서만 버튼 존재를 본다 — role 관리(user) 는 부하 담당자 바이어만 목록에 뜬다(스코프)
                $c->assertSee($x->name)->assertSeeHtml('delete('.$x->id.')');
            }
            $c->call('delete', $x->id);
            $this->assertSoftDeleted('buyers', ['id' => $x->id]);   // ⚠️ 3번째 인자는 메시지가 아니라 DB 연결명
        }
    }

    // ── 점검 명령 ───────────────────────────────────────────────────────

    public function test_check_dangling_lists_vehicles_pointing_at_deleted_buyers_and_rebinds_on_apply(): void
    {
        $from = Buyer::create(['name' => 'ATLAS DUP', 'is_active' => true]);
        $to = Buyer::create(['name' => 'ATLAS LIVE', 'is_active' => true]);
        $v = $this->vehicle($from);
        Buyer::withTrashed()->whereKey($from->id)->update(['deleted_at' => now()]);   // 가드 이전에 생긴 매달린 상태를 그대로 재현

        $this->artisan('buyers:check-dangling')
            ->expectsOutputToContain('vehicles.buyer_id')
            ->expectsOutputToContain('12가0001')
            ->expectsOutputToContain('매달린 참조 합계: 1건')
            ->assertSuccessful();

        $this->artisan('buyers:check-dangling', ['--rebind' => "{$from->id}:{$to->id}"])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();
        $this->assertSame($from->id, $v->fresh()->buyer_id, 'dry-run 이 값을 바꿨다');

        $this->artisan('buyers:check-dangling', ['--rebind' => "{$from->id}:{$to->id}", '--apply' => true])
            ->expectsOutputToContain('이관 완료')
            ->expectsOutputToContain('아직 가리키는 차량 = 0대')
            ->assertSuccessful();
        $this->assertSame($to->id, $v->fresh()->buyer_id);

        Artisan::call('buyers:check-dangling');
        $this->assertStringContainsString('매달린 참조 합계: 0건', Artisan::output());
    }
}
