<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutAdjustment;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /api/internal/board/payout-batches` — 영업 본인 월배치 미러 (2026-08-31 board 인계).
 *
 * 이 엔드포인트의 위험은 성능이 아니라 **남의 돈이 보이는 것**이다. 배치 행에는
 * `total_payout`·`settlement_count` 라는 **전 영업 합계 스냅샷**이 붙어 있고, 무심코 모델을
 * 직렬화하면 그대로 나간다. 그래서 응답 **본문 문자열**을 검사한다 — 매핑 배열만 보는 테스트는
 * 새어도 통과한다.
 *
 * 운영에는 조정이 **단 1 건**뿐이라(2026-08-31 실측) 실데이터로는 격리를 증명할 수 없다.
 * 두 영업 · 한 배치 · 양쪽 조정 fixture 를 직접 만든다.
 */
class BoardPayoutBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/internal/board/payout-batches';

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        Settlement::flushParamMemo();
    }

    private function signedGet(array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".self::PATH.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get(self::PATH.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function salesman(string $email): Salesman
    {
        return Salesman::create([
            'name' => 'SM-'.$email, 'email' => $email, 'type' => 'employee', 'is_active' => true,
        ]);
    }

    /** 정산 1건 — per_unit 고정액이라 마진 계산과 무관하게 금액이 결정된다(테스트 안정). */
    private function settlement(Salesman $sm, string $plate, int $payout, ?SettlementPayoutBatch $batch, string $status = 'paid'): Settlement
    {
        $v = Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD',
            'salesman_id' => $sm->id, 'purchase_price' => 1_000_000,
        ]);

        return Settlement::withoutEvents(fn () => Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $sm->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => $payout,
            'settlement_status' => $status,
            'payout_batch_id' => $batch?->id,
            'paid_at' => $status === 'paid' ? now() : null,
        ]));
    }

    private function submitterId(): int
    {
        return User::firstOrCreate(
            ['email' => 'submitter@t.test'],
            ['name' => '제출자', 'password' => bcrypt('x'), 'permission' => 'admin', 'email_verified_at' => now()],
        )->id;
    }

    private function batch(string $month, string $status): SettlementPayoutBatch
    {
        return SettlementPayoutBatch::create([
            'month' => $month, 'submitter_id' => $this->submitterId(), 'submitter_rank' => 1, 'current_level' => 2,
            'status' => $status, 'total_payout' => 999_999_999, 'settlement_count' => 99,
            'submitted_at' => now(), 'decided_at' => now(),
        ]);
    }

    private function adjustment(SettlementPayoutBatch $b, Salesman $sm, int $amount, string $reason): void
    {
        SettlementPayoutAdjustment::create([
            'batch_id' => $b->id, 'salesman_id' => $sm->id, 'amount' => $amount, 'reason' => $reason,
        ]);
    }

    // ── ① 남의 정산·조정이 절대 안 섞인다 ───────────────────────────────

    public function test_another_salesmans_rows_never_appear(): void
    {
        $me = $this->salesman('me@t.test');
        $other = $this->salesman('other@t.test');
        $b = $this->batch('2026-07', 'approved');

        $this->settlement($me, '11가1111', 1_000_000, $b);
        $this->settlement($other, '99하9999', 7_777_777, $b);
        $this->adjustment($b, $me, -300_000, '내 환수');
        $this->adjustment($b, $other, -555_555, '남의 환수');

        $res = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk();

        $body = $res->getContent();
        $this->assertStringNotContainsString('99하9999', $body, '남의 차량번호가 새어 나갔다');
        $this->assertStringNotContainsString('7777777', $body, '남의 정산액이 새어 나갔다');
        $this->assertStringNotContainsString('남의 환수', $body, '남의 조정 사유가 새어 나갔다');
        $this->assertStringNotContainsString('555555', $body, '남의 조정 금액이 새어 나갔다');

        $row = $res->json('data.0');
        $this->assertCount(1, $row['settlements']);
        $this->assertCount(1, $row['adjustments']);
    }

    // ── ② 배치 전체 스냅샷이 응답 어디에도 없다 (본문 문자열 검사) ──────

    public function test_batch_wide_snapshot_never_ships(): void
    {
        $me = $this->salesman('me@t.test');
        $b = $this->batch('2026-07', 'approved');   // total_payout=999,999,999 / settlement_count=99
        $this->settlement($me, '11가1111', 1_000_000, $b);

        $body = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk()->getContent();

        // 키 이름 자체가 없어야 한다 — 모델을 통째로 직렬화하면 여기서 걸린다.
        $this->assertStringNotContainsString('total_payout', $body, '전 영업 합계 컬럼이 응답에 있다');
        $this->assertStringNotContainsString('settlement_count', $body, '전 영업 건수 컬럼이 응답에 있다');
        // 그 값 자체도 없어야 한다(다른 키 이름으로 담아 보내는 실수 방지).
        $this->assertStringNotContainsString('999999999', $body);
        // 승인 사다리(누가 서명했는지)도 board 에 불필요 — 나가면 안 된다.
        $this->assertStringNotContainsString('approver', $body);
        $this->assertStringNotContainsString('submitter', $body);
    }

    // ── ③ 승인 전·반려 배치는 안 나온다 ────────────────────────────────

    public function test_only_approved_batches_are_mirrored(): void
    {
        $me = $this->salesman('me@t.test');
        foreach (['pending' => '2026-05', 'rejected' => '2026-06', 'cancelled' => '2026-04'] as $st => $month) {
            $b = $this->batch($month, $st);
            $this->settlement($me, 'X'.$month, 500_000, $b, 'confirmed');
        }
        $ok = $this->batch('2026-07', 'approved');
        $this->settlement($me, '11가1111', 1_000_000, $ok);

        $res = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk();

        $this->assertSame(1, $res->json('count'), '승인 전/반려 배치가 섞였다');
        $this->assertSame('2026-07', $res->json('data.0.month'));
    }

    // ── ④ net_payout = 본인 정산합 + 본인 조정합 ──────────────────────

    public function test_net_payout_is_own_settlements_plus_own_adjustments(): void
    {
        $me = $this->salesman('me@t.test');
        $other = $this->salesman('other@t.test');
        $b = $this->batch('2026-07', 'approved');

        $this->settlement($me, '11가1111', 1_000_000, $b);
        $this->settlement($me, '22나2222', 200_000, $b);
        $this->settlement($other, '99하9999', 9_000_000, $b);   // 남의 것 — 합계에 들어가면 안 된다
        $this->adjustment($b, $me, -300_000, '과지급 환수');
        $this->adjustment($b, $other, 4_000_000, '남의 특별지급');

        $row = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk()->json('data.0');

        $this->assertSame(1_200_000, $row['settlement_total']);
        $this->assertSame(-300_000, $row['adjustment_total']);
        $this->assertSame(900_000, $row['net_payout']);
        $this->assertSame($row['settlement_total'] + $row['adjustment_total'], $row['net_payout']);
    }

    /**
     * 단일 영업 배치에서는 내 몫 = 배치 전체다. 그때 `net_payout` 은 ERP 자신의 총액 계산
     * (`recomputeTotal()` = 정산합 + 조정합)과 **같아야 한다**. 두 정의가 나중에 갈리는 것을
     * 막는 유일한 싼 방법이다.
     */
    public function test_net_payout_matches_erp_own_total_for_a_single_salesman_batch(): void
    {
        $me = $this->salesman('me@t.test');
        $b = $this->batch('2026-07', 'approved');
        $this->settlement($me, '11가1111', 1_000_000, $b);
        $this->adjustment($b, $me, -300_000, '과지급 환수');

        $b->refresh()->recomputeTotal();

        $row = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk()->json('data.0');
        $this->assertSame((int) $b->fresh()->total_payout, $row['net_payout'],
            'ERP 배치 총액과 board 에 보내는 net_payout 이 갈렸다');
    }

    // ── unbatched_paid — 예외가 아니라 본류 ───────────────────────────

    public function test_unbatched_paid_is_returned_and_scoped(): void
    {
        $me = $this->salesman('me@t.test');
        $other = $this->salesman('other@t.test');
        $this->settlement($me, '33다3333', 800_000, null);            // 배치 밖 지급
        $this->settlement($other, '99하9999', 6_666_666, null);       // 남의 배치 밖 지급
        $this->settlement($me, '44라4444', 500_000, null, 'confirmed');   // 아직 미지급 → 제외

        $res = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk();

        $un = $res->json('unbatched_paid');
        $this->assertCount(1, $un, '배치 밖 지급은 paid 인 본인 것 하나여야 한다');
        $this->assertSame('33다3333', $un[0]['vehicle_number']);
        $this->assertSame(800_000, $un[0]['actual_payout']);
        $this->assertStringNotContainsString('99하9999', $res->getContent());
        $this->assertStringNotContainsString('44라4444', $res->getContent(), '미지급 정산이 섞였다');
    }

    // ── 빈 배치 제외 · 삭제 차량 번호 보존 ────────────────────────────

    public function test_batch_without_my_rows_is_excluded(): void
    {
        $me = $this->salesman('me@t.test');
        $other = $this->salesman('other@t.test');
        $b = $this->batch('2026-07', 'approved');
        $this->settlement($other, '99하9999', 1_000_000, $b);

        $res = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk();
        $this->assertSame(0, $res->json('count'), '내 행이 없는 배치가 0원짜리로 떴다');
    }

    public function test_soft_deleted_vehicle_still_shows_its_plate(): void
    {
        // 급여 명세에 「번호 없는 줄」이 생기면 설명할 수 없다. belongsTo 는 삭제 차량에 null 을 준다.
        $me = $this->salesman('me@t.test');
        $b = $this->batch('2026-07', 'approved');
        $s = $this->settlement($me, '55마5555', 1_000_000, $b);
        Vehicle::withoutEvents(fn () => Vehicle::whereKey($s->vehicle_id)->delete());

        $row = $this->signedGet(['salesman_email' => 'me@t.test'])->assertOk()->json('data.0');
        $this->assertSame('55마5555', $row['settlements'][0]['vehicle_number'],
            '삭제된 차량의 번호가 null 로 나갔다');
    }

    // ── 인증·격리 회귀 ────────────────────────────────────────────────

    public function test_unknown_or_inactive_salesman_forbidden(): void
    {
        $this->signedGet(['salesman_email' => 'nobody@t.test'])->assertStatus(403);
    }

    public function test_unsigned_request_rejected(): void
    {
        $this->get(self::PATH.'?salesman_email=me@t.test')->assertStatus(401);
    }
}
