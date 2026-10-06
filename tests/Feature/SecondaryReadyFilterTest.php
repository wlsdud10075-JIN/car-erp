<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💴 **「2차 가능」 / 「비용 대기」** (jin 2026-10-06).
 *
 * jin: *「2차 마감을 일괄 업로드로 탁송비·면허비 기타등등 차량관리에서 업로드해서 하는데, 그걸 솔팅할 수 있는 방안을
 *      마련하고 그것만 2차 마감을 하고, 안 된 애들은 별도로 찾아보거나 다른 방안을 마련해야 한다.」*
 *
 * 판정 = 지급(1차) **뒤에** 그 차량의 비용 칸(10개)이 **실제로** 바뀐 기록(`secondary_ready_at`).
 * 명세서 기입 일괄이든 패널 수동이든 같다(jin 「손으로 넣은 것도 마찬가지, 뺄 이유가 없다」).
 * 일괄 2차 마감은 「2차 가능」만, 단건 [2차 완료]는 종전대로 둘 다(추가 비용이 원래 없는 차).
 */
class SecondaryReadyFilterTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    /** 완납 EUR 차 + 지급(paid)된 정산 = 2차 대기(비용 대기). */
    private function paidSettlement(string $plate = '11가1111', string $month = '2026-07'): array
    {
        $sm = Salesman::firstOrCreate(['name' => '프리'], ['type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => $month.'-01',
            'sale_price' => 10_000, 'sale_date' => $month.'-10',
        ]);
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1500, 'payment_date' => $month.'-20', 'confirmed_at' => now(),
        ]);
        $v->refresh()->refreshCaches();

        Settlement::$allowBatchPayout = true;
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => $month.'-01',
        ]);
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        return [$v->fresh(), $s->fresh()];
    }

    /** 지급 직후엔 비용 대기 · 비용 칸(탁송비) 하나가 바뀌면 2차 가능. 패널 수동이든 일괄이든 같은 모델 update 경로다. */
    public function test_a_real_cost_change_after_payout_marks_the_settlement_ready(): void
    {
        [$v, $s] = $this->paidSettlement();
        $this->assertSame('pending', $s->secondary_status);
        $this->assertNull($s->secondary_ready_at, '지급 직후는 비용 대기');

        $v->update(['cost_towing' => 150_000]);

        $this->assertNotNull($s->fresh()->secondary_ready_at, '비용 기입 뒤에도 비용 대기다');
        $this->assertSame(1, Settlement::secondaryReady()->count());
        $this->assertSame(0, Settlement::secondaryWaiting()->count());
    }

    /**
     * 🧹 「0.00 → 0」 재저장은 변경이 아니다(§8 #108) — 전 차량이 2차 가능이 되면 필터가 무의미하다.
     *    비용이 아닌 칸(메모)만 바뀌어도 표시하지 않는다.
     */
    public function test_a_no_op_resave_or_a_non_cost_change_does_not_mark_ready(): void
    {
        [$v, $s] = $this->paidSettlement();

        // DB 가 돌려주는 "0.00" 문자열 모양을 심어 재저장 — 운영 MySQL 의 decimal 과 같다
        $attrs = $v->getAttributes();
        $attrs['cost_towing'] = '0.00';
        $v->setRawAttributes($attrs, true);
        $v->cost_towing = 0;
        $v->save();
        $this->assertNull($s->fresh()->secondary_ready_at, '표기만 다른 재저장이 2차 가능으로 둔갑했다');

        $v->fresh()->update(['memo' => '메모만 고침']);
        $this->assertNull($s->fresh()->secondary_ready_at, '비용이 아닌 칸이 2차 가능을 켰다');
    }

    /** 1차 지급 **전**에 넣은 비용은 근거가 아니다 — paid 전환 때 리셋된다. 첫 기입 시각은 덮지 않는다. */
    public function test_costs_entered_before_payout_do_not_count_and_the_first_mark_is_kept(): void
    {
        $sm = Salesman::firstOrCreate(['name' => '프리'], ['type' => 'freelance', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $sm->id, 'purchase_price' => 8_000_000, 'purchase_date' => '2026-07-01',
            'sale_price' => 10_000, 'sale_date' => '2026-07-10',
        ]);
        $v->finalPayments()->create(['type' => 'balance', 'amount' => 10_000, 'exchange_rate' => 1500, 'payment_date' => '2026-07-20', 'confirmed_at' => now()]);
        $v->refresh()->refreshCaches();
        Settlement::$allowBatchPayout = true;
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(), 'attributed_month' => '2026-07-01',
            'secondary_ready_at' => now()->subDay(),   // 억지로 미리 찍힌 값
        ]);
        $v->fresh()->update(['cost_license' => 30_000]);   // 지급 전 비용 — 2차 대기가 아니라 아무 일도 없다
        $s->forceFill(['settlement_status' => 'paid', 'paid_at' => now()])->save();
        Settlement::$allowBatchPayout = false;

        $this->assertNull($s->fresh()->secondary_ready_at, 'paid 전환이 리셋하지 않았다');

        $v->fresh()->update(['cost_towing' => 1]);
        $first = $s->fresh()->secondary_ready_at;
        $this->travel(1)->hours();
        $v->fresh()->update(['cost_towing' => 2]);
        $this->assertEquals($first, $s->fresh()->secondary_ready_at, '두 번째 기입이 첫 기입 시각을 덮었다');
    }

    /** 필터 ready / waiting / pending 이 SQL 로 갈리고, 뱃지도 다르게 그려진다. */
    public function test_the_filter_and_the_badge_split_ready_from_waiting(): void
    {
        [$v1, $ready] = $this->paidSettlement('33다3333');
        [, $waiting] = $this->paidSettlement('44라4444');
        $v1->update(['cost_towing' => 100_000]);
        $this->actingAs($this->finance());

        $ids = fn (string $f) => Settlement::query()->secondaryFilter($f)->pluck('id')->all();
        $this->assertSame([$ready->id], $ids('ready'));
        $this->assertSame([$waiting->id], $ids('waiting'));
        $this->assertEqualsCanonicalizing([$ready->id, $waiting->id], $ids('pending'));
        $this->assertCount(2, $ids('garbage'), '모르는 값은 무시(필터 없음)');

        $html = Volt::test('erp.settlements.index')->set('monthFilter', '2026-07')->set('secondaryFilter', 'ready')->call('searchNow')->html();
        $this->assertStringContainsString('33다3333', $html);
        $this->assertStringNotContainsString('44라4444', $html, '비용 대기 건이 2차 가능 필터에 나왔다');
        $this->assertMatchesRegularExpression('/badge badge-green[^>]*>\s*'.preg_quote(__('settlement.secondary.ready'), '/').'/u', $html, '2차 가능 뱃지가 없다');
        $this->assertStringContainsString('data-secondary-filter', $html, '필터 셀렉트가 화면에 없다');
    }

    /** 🔑 일괄 2차 마감은 「2차 가능」만 닫는다 — 비용 대기는 미리보기에 건수로 보이고 그대로 남는다. 단건은 둘 다 된다. */
    public function test_bulk_close_takes_only_ready_rows_and_says_how_many_are_waiting(): void
    {
        [$v1, $ready] = $this->paidSettlement('55마5555');
        [, $waiting] = $this->paidSettlement('66바6666');
        $v1->update(['cost_towing' => 100_000]);
        $this->actingAs($this->finance());

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '2026-07')->call('openCloseSecondaryModal');
        $preview = $c->get('closeSecondaryPreview');
        $this->assertSame([$ready->id], array_column($preview['ready'], 'id'));
        $this->assertSame(1, $preview['waiting']);
        $c->assertSee(__('settlement.batch.close_waiting', ['count' => 1]));

        $c->call('closeSecondaryMonth');
        $this->assertSame('closed', $ready->fresh()->secondary_status);
        $this->assertSame('pending', $waiting->fresh()->secondary_status, '비용 대기가 일괄에 쓸려 들어갔다');

        // 추가 비용이 원래 없는 차는 행의 [2차 완료]로 닫는다 — 단건은 막지 않는다
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $waiting->id);
        $this->assertSame('closed', $waiting->fresh()->secondary_status);
    }

    /** 배포 전부터 대기 중이던 건은 감사로그로 되짚는다 — paid 뒤 비용 변경 기록이 있으면 그 첫 시각으로. dry-run 은 안 쓴다. */
    public function test_backfill_marks_from_audit_log_only_after_paid_at(): void
    {
        [$v1, $before] = $this->paidSettlement('77사7777');
        [$v2, $after] = $this->paidSettlement('88아8888');
        // 훅이 이미 표시한 것을 지워 「배포 전」 상태를 만든다
        $v1->update(['cost_towing' => 50_000]);
        $v2->update(['cost_towing' => 70_000]);
        Settlement::query()->update(['secondary_ready_at' => null]);
        // 지급은 기입보다 먼저였다 — 같은 초에 만든 픽스처라 시각을 벌려 둔다(운영에선 날짜 단위로 떨어져 있다)
        Settlement::whereKey($after->id)->update(['paid_at' => now()->subMinutes(10)]);
        // v1 의 비용 기록은 지급 **전**으로 되돌린다 → 근거가 아니다
        AuditLog::where('auditable_type', Vehicle::class)->where('auditable_id', $v1->id)
            ->where('column_name', 'cost_towing')->update(['created_at' => now()->subDays(3)]);
        Settlement::whereKey($before->id)->update(['paid_at' => now()->subDay()]);

        Artisan::call('settlements:backfill-secondary-ready');
        $this->assertNull($after->fresh()->secondary_ready_at, 'dry-run 이 썼다');

        Artisan::call('settlements:backfill-secondary-ready', ['--apply' => true]);
        $this->assertNotNull($after->fresh()->secondary_ready_at);
        $this->assertNull($before->fresh()->secondary_ready_at, '지급 전 기록을 근거로 삼았다');
        $this->assertStringContainsString('2차 가능 1', Artisan::output());
    }
}
