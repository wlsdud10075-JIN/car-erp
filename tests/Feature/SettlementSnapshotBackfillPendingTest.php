<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📸 paid 스냅샷이 없는 정산 (엑셀 적재분 · 스크립트 지급분) — 2026-09-22.
 *
 * 실측 ssancarerp: 2026-08-10 지급 426행이 스냅샷 없이 2차 대기 중이었다. 두 가지가 걸려 있었다:
 *   ① 관리자 대시보드가 그 행들의 마진 사슬을 매 렌더마다 다시 계산(운영 10초의 절반)
 *   ② 2차 마감이 `snapshot['actual_payout'] ?? 0` 기준이라 **실지급액 전액이 이월**로 둔갑 → 다음 정산에서 한 번 더 지급
 *
 * 그래서 ⓐ 마감 코드는 스냅샷이 없으면 이월을 만들지 않고, ⓑ 백필 명령에 `--include-pending` 을 두어
 * 오늘 값으로 채우되 `backfilled_at` 표식을 남긴다(지급 시점 기록이 아님을 밝힌다).
 */
class SettlementSnapshotBackfillPendingTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function paidWithoutSnapshot(string $secondary = 'pending'): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => 'SB-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_price' => 1_000_000, 'purchase_date' => '2026-05-01',
            'sale_price' => 3_000_000, 'sale_date' => '2026-05-10',
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'confirmed_at' => now(), 'paid_at' => now(),
            'secondary_status' => $secondary,
        ]);
        // paid 로 만들면 훅이 스냅샷을 찍는다 — 적재분처럼 비워 둔다
        DB::table('settlements')->where('id', $s->id)->update(['confirmed_snapshot' => null]);

        return $s->fresh();
    }

    /** ⓐ 스냅샷 없이 2차 마감 → 이월 0. (`?? 0` 이었으면 실지급액 전액이 이월이 됐다.) */
    public function test_closing_without_a_paid_snapshot_creates_no_carryover(): void
    {
        $s = $this->paidWithoutSnapshot();
        $this->assertNull($s->confirmed_snapshot);
        $this->assertGreaterThan(0, $s->actual_payout, '표본 정산의 실지급액이 0 이면 이 테스트는 아무것도 검사하지 않는다');

        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $s->refresh();
        $this->assertSame('closed', $s->secondary_status);
        $this->assertNull($s->carryover_out_krw, '기준 스냅샷이 없는데 이월이 생겼다 — 다음 정산에서 한 번 더 지급된다');
    }

    /** 스냅샷이 있으면 종전 규칙 그대로 — 마감 실지급액 − paid 스냅샷 실지급액. */
    public function test_closing_with_a_snapshot_still_computes_carryover(): void
    {
        $s = $this->paidWithoutSnapshot();
        $snap = $s->buildConfirmedSnapshot();
        $snap['actual_payout'] = $s->actual_payout - 70_000;   // 지급 시점엔 7만 적게 받았다고 치자
        DB::table('settlements')->where('id', $s->id)->update(['confirmed_snapshot' => json_encode($snap)]);

        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $s->id);

        $this->assertSame(70_000, (int) $s->fresh()->carryover_out_krw);
    }

    /** ⓑ 기본 실행은 2차 미마감 행을 건너뛴다(종전) · --include-pending 이면 오늘 값 + backfilled_at 표식. */
    public function test_backfill_fills_pending_rows_only_when_asked_and_marks_them(): void
    {
        $pending = $this->paidWithoutSnapshot('pending');
        $closed = $this->paidWithoutSnapshot('closed');

        $this->artisan('settlements:backfill-snapshot', ['--apply' => true])->assertSuccessful();
        $this->assertNull($pending->fresh()->confirmed_snapshot, '옵션 없이 2차 미마감 행을 채웠다');
        $this->assertNotNull($closed->fresh()->confirmed_snapshot);
        $this->assertArrayNotHasKey('backfilled_at', $closed->fresh()->confirmed_snapshot, '마감 행은 지금 값 = 지급 시점 값이라 표식이 없다');

        $this->artisan('settlements:backfill-snapshot', ['--apply' => true, '--include-pending' => true])->assertSuccessful();
        $snap = $pending->fresh()->confirmed_snapshot;
        $this->assertNotNull($snap);
        $this->assertArrayHasKey('backfilled_at', $snap, '소급 박제인데 표식이 없다 — 지급 시점 기록으로 오해된다');
        $this->assertSame((int) $pending->actual_payout, (int) $snap['actual_payout'], '오늘 값과 다르다');
        $this->assertArrayHasKey('shipping_fee', $snap, '회사 몫을 스냅샷만으로 닫으려면 발송비가 있어야 한다');
    }

    /** 이미 있는 스냅샷은 --include-pending 이어도 절대 덮지 않는다. */
    public function test_backfill_never_overwrites_an_existing_snapshot(): void
    {
        $s = $this->paidWithoutSnapshot('pending');
        DB::table('settlements')->where('id', $s->id)->update(['confirmed_snapshot' => json_encode(['actual_payout' => 1, 'marker' => 'keep'])]);

        $this->artisan('settlements:backfill-snapshot', ['--apply' => true, '--include-pending' => true])->assertSuccessful();

        $this->assertSame('keep', $s->fresh()->confirmed_snapshot['marker']);
    }
}
