<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShippingDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔢 **차량관리 「번호 일괄 기입」에 B/L 번호 추가** (jin 2026-09-17).
 *
 * jin: *「선적요청 탭에는 수출신고번호·컨테이너번호·B/L번호·선박명 기입이 있는데, 차량관리 체크박스
 * N대 → 번호 일괄기입에는 수출신고번호·컨테이너번호만 되고 B/L번호가 안 돼.」*
 *
 * 🔑 막던 것 = `BulkVehicleShippingDateService::FIELDS` **화이트리스트**에 `bl_number` 가 없었다.
 *
 * 🚫 **`bl_document`(B/L 파일)는 대상이 아니다** — G1 100% 완납 게이트가 걸린 자리라 차량별로만 올린다
 *    (선적요청 묶음 폼도 같은 이유로 제외해 뒀다).
 * 🔑 `bl_number` 는 **진행상태·게이트 판정에 안 쓰인다**(§8 #97 — 자유 입력칸이라 실측에서 기각).
 *    그래서 일괄로 찍어도 단계가 넘어가거나 잠기지 않는다.
 */
class BulkNumberBlTest extends TestCase
{
    use RefreshDatabase;

    private function clearanceUser(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now(),
        ]);
    }

    /** @return array<int, Vehicle> */
    private function vehicles(int $n = 3): array
    {
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = Vehicle::create([
                'vehicle_number' => sprintf('%02d바%04d', 40 + $i, 1000 + $i),
                'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1700,
                'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            ]);
        }

        return $out;
    }

    /** 🔒 화이트리스트에 들어 있어야 서비스가 그 칸을 만진다 — 여기 없으면 화면이 넘겨도 무시된다. */
    public function test_bl_number_is_whitelisted_but_the_file_is_not(): void
    {
        $this->assertContains('bl_number', BulkVehicleShippingDateService::FIELDS);
        $this->assertNotContains('bl_document', BulkVehicleShippingDateService::FIELDS,
            'B/L 파일이 일괄 대상에 들어갔다 — G1 100% 게이트가 걸린 자리다');
    }

    /** ✅ 선택 N대에 B/L 번호가 실제로 찍힌다. */
    public function test_it_writes_the_bl_number_to_every_selected_vehicle(): void
    {
        [$a, $b, $c] = $this->vehicles();
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id, $c->id])
            ->call('openBulkNumber')
            ->set('bulkNumBl', 'HDMUABCD1234567')
            ->call('applyBulkNumber')
            ->assertHasNoErrors();

        foreach ([$a, $b, $c] as $v) {
            $this->assertSame('HDMUABCD1234567', $v->fresh()->bl_number);
        }
    }

    /**
     * 🚦 **진행상태가 안 움직인다** — `bl_number` 는 cascade 가 보는 값이 아니다(보는 건 `bl_document`).
     *    이게 깨지면 일괄 기입 한 번에 수십 대가 거래완료로 넘어가 회계가 잠긴다.
     */
    public function test_it_does_not_move_the_progress_stage(): void
    {
        [$a] = $this->vehicles(1);
        $before = $a->fresh()->progress_status_cache;
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id])
            ->call('openBulkNumber')
            ->set('bulkNumBl', 'HDMUABCD1234567')
            ->call('applyBulkNumber');

        $this->assertSame($before, $a->fresh()->progress_status_cache, 'B/L 번호가 단계를 움직였다');
        $this->assertNull($a->fresh()->bl_document, 'B/L 파일이 생겼다');
    }

    /** 🧾 **수십 대를 한 번에 바꾸는 입구**라 감사 기록이 남아야 한다(07-28 선적일·ETA 와 같은 이유). */
    public function test_the_change_is_audited(): void
    {
        [$a] = $this->vehicles(1);
        $this->actingAs($this->clearanceUser());
        AuditLog::query()->delete();

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id])
            ->call('openBulkNumber')
            ->set('bulkNumBl', 'HDMUABCD1234567')
            ->call('applyBulkNumber');

        // 이 도구는 **두 줄**을 남긴다 — 값 변경(어느 값에서 어느 값으로)과 일괄 기입 사유.
        //   전자는 `Vehicle::updated` 훅, 후자는 서비스가 직접(§ BulkVehicleShippingDateService).
        $change = AuditLog::query()->where('column_name', 'bl_number')
            ->where('action', '!=', 'bulk_shipping_date_applied')->get();
        $this->assertCount(1, $change, 'B/L 번호 값 변경이 감사로그에 안 남았다');
        $this->assertSame('HDMUABCD1234567', $change->first()->new_value);

        $this->assertSame(1, AuditLog::query()->where('action', 'bulk_shipping_date_applied')
            ->where('column_name', 'bl_number')->count(), '일괄 기입 사유가 안 남았다');
    }

    /** 🚫 빈 칸이면 아무것도 안 바뀐다 — 이 도구로 값을 **비울 수는 없다**(오조작 한 번에 수십 대 날아감 방지). */
    public function test_a_blank_field_never_clears_an_existing_value(): void
    {
        [$a] = $this->vehicles(1);
        $a->forceFill(['bl_number' => 'KEEPME1234'])->save();
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id])
            ->call('openBulkNumber')
            ->set('bulkNumDecl', '12345-67-8901234')   // 신고번호만 채운다
            ->call('applyBulkNumber');

        $this->assertSame('KEEPME1234', $a->fresh()->bl_number, '빈 칸이 기존 B/L 번호를 지웠다');
        $this->assertSame('12345-67-8901234', $a->fresh()->export_declaration_number);
    }

    /**
     * 🚦 **기존 값이 섞였으면 확인 없이는 못 덮는다** — 서로 다른 B/L 번호를 단 차가 섞였는데
     *    모르고 덮으면 되돌리기 어렵다(선박명 일괄에서 배운 규칙, jin 2026-08-12).
     */
    public function test_mixed_existing_values_need_an_explicit_ack(): void
    {
        [$a, $b] = $this->vehicles(2);
        $a->forceFill(['bl_number' => 'AAA111'])->save();
        $b->forceFill(['bl_number' => 'BBB222'])->save();
        $this->actingAs($this->clearanceUser());

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id, $b->id])
            ->call('openBulkNumber')
            ->set('bulkNumBl', 'CCC333');

        // 미리보기가 섞임을 알아본다
        $this->assertArrayHasKey('bl_number', $c->instance()->bulkNumberConflicts());

        $c->call('applyBulkNumber');
        $this->assertSame('AAA111', $a->fresh()->bl_number, '확인 없이 덮였다');

        // 확인하면 진행된다
        $c->set('bulkNumAck', true)->call('applyBulkNumber');
        $this->assertSame('CCC333', $a->fresh()->bl_number);
        $this->assertSame('CCC333', $b->fresh()->bl_number);
    }

    /** 세 칸이 모두 비면 「하나는 입력하라」 — 빈 실행으로 감사만 쌓이지 않게. */
    public function test_all_three_blank_is_refused(): void
    {
        [$a] = $this->vehicles(1);
        $this->actingAs($this->clearanceUser());

        Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [$a->id])
            ->call('openBulkNumber')
            ->call('applyBulkNumber');

        $this->assertNull($a->fresh()->bl_number);
    }
}
