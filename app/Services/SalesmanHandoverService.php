<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 퇴사 승계 — A 영업담당자가 하던 일을 B 가 통째로 받는다 (jin 2026-08-27).
 *
 * ═══ 경계는 「정산이 생겼나」 하나다 (jin) ══════════════════════════════════
 *   정산 row 없음(진행중)  →  차량 담당자 A→B.  앞으로 정산은 B 가 받는다
 *   정산 row 있음          →  그대로 A.        손대지 않는다
 *
 * jin: *"진행중인것도 정산에 빠지지 않았으면 B로 인계받는것이고, 정산으로 빠진 차량이라면
 * 그건 그대로 A가 정산 받으면 될거같은데."*
 *
 * 🔑 **정산 자체는 옮기지 않는다.** `settlements.salesman_id` 는 만들어질 때 차량 담당자에서 오고,
 *    이미 만들어진 행은 안 건드린다 — 그래서 A 계정 정산은 A 에 남고 B 는 앞으로 것만 받는다.
 *    이건 새로 만드는 규칙이 아니라 **원래 그렇게 동작하던 것**이다.
 *
 * 🚫 **되돌리기(undo)를 만들지 않는다.** 반대로 한 번 더 돌리는 것이 곧 되돌리기이고,
 *    기록은 `AuditLog` 에 남는다. undo 경로를 두면 「직전 상태」라는 두 번째 진실이 생긴다.
 *
 * ⚠️ **바이어 담당자 변경은 평소 감사에 안 남는다** — `Buyer` 엔 감사 훅이 없다(`is_inherited`·
 *    무담보 한도·락만 화면이 손으로 기록한다). 수십 명을 통째로 옮기는데 흔적이 없으면 안 되므로
 *    여기서 **바이어마다** `recordChange` 를 남기고, 일괄 출처를 `salesman_handover` 로 따로 박는다.
 *    (차량 쪽은 `vehicles.salesman_id` 가 `AUDITED_COLUMNS` 라 모델 훅이 자동으로 남긴다.)
 */
class SalesmanHandoverService
{
    /**
     * 미리보기·실행이 **같은 함수**를 쓴다 — 갈리면 「모달엔 옮긴다고 떴는데 안 옮겨진」 행이 남는다.
     *
     * ═══ 나눠 넘기기 (jin 2026-08-27) ═════════════════════════════════════════
     * 바이어가 많으면 한 사람이 다 못 받는다. **바이어를 골라 B 에게 넘기고, 다시 눌러 남은 것을
     * C 에게** 넘길 수 있어야 한다. 두 번째로 열면 이미 넘어간 바이어는 A 소속이 아니므로
     * 목록에서 자동으로 빠진다 — 남은 것만 보인다.
     *
     * 🔑 **이동 단위는 바이어이고, 차량은 그 바이어를 따라간다.** 「이 바이어들을 B 가 맡는다」가
     *    사람이 생각하는 단위이기 때문이다. 그래서 진행중 차량을 넷으로 가른다:
     *
     *      ① 고른 바이어의 차           → 함께 이동 (자동. 따로 고르지 않는다)
     *      ② A 의 바이어지만 안 고른 차  → 그대로 A  (그 바이어를 넘길 때 같이 간다)
     *      ③ 담당 바이어가 없는 차       → 그대로 A  (아래)
     *      ④ 정산이 있는 차             → 그대로 A  (경계 = 정산 유무, jin)
     *
     * ⚠ **③ 를 체크박스로 떠넘기지 않는다** (jin 2026-08-27 — 만들었다가 뻐다).
     *    그 차들은 대부분 **바이어에 담당자가 안 붙어서** 생긴다 — 승계 모달에서 고를 일이
     *    아니라 **바이어 탭에서 담당자를 지정하면 저절로 풀린다**(바이어 목록 「담당자 미지정」 필터).
     *    그래서 여기서는 그대로 두고, 「그대로 두는 것」에 **어디서 고치는지**를 적어 보여준다.
     *
     * @param  ?array  $buyerIds  넘길 바이어. null = 전부(단일 승계).
     */
    public function preview(Salesman $from, Salesman $to, ?array $buyerIds = null): array
    {
        $this->assertPair($from, $to);

        $all = Buyer::where('salesman_id', $from->id)->orderBy('name')->get();
        $allIds = $all->pluck('id')->all();

        // null = 전부. 넘어온 id 중 **A 소속이 아닌 것은 버린다** — 클라이언트가 주입할 수 있다(§8 #26).
        $selectedIds = $buyerIds === null
            ? $allIds
            : array_values(array_intersect(array_map('intval', $buyerIds), $allIds));
        $buyers = $all->whereIn('id', $selectedIds);

        $vehicles = Vehicle::where('salesman_id', $from->id)
            ->withCount('settlements')
            ->orderBy('vehicle_number')
            ->get();

        $settled = $vehicles->filter(fn (Vehicle $v) => $v->settlements_count > 0);
        $inProgress = $vehicles->filter(fn (Vehicle $v) => $v->settlements_count === 0);

        $mine = $inProgress->filter(fn (Vehicle $v) => in_array((int) $v->buyer_id, $selectedIds, true));
        $notSelected = $inProgress->filter(fn (Vehicle $v) => $v->buyer_id
            && in_array((int) $v->buyer_id, $allIds, true)
            && ! in_array((int) $v->buyer_id, $selectedIds, true));
        $orphan = $inProgress->filter(fn (Vehicle $v) => ! $v->buyer_id
            || ! in_array((int) $v->buyer_id, $allIds, true));

        $row = fn (Vehicle $v) => ['id' => $v->id, 'vehicle_number' => $v->vehicle_number];

        return [
            // 넘어가는 것
            'buyers' => $buyers->map(fn (Buyer $b) => [
                'id' => $b->id,
                'name' => $b->name,
                // 이미 다른 승계 이력이 있는 바이어 — 덮어쓰면 「누가 넘겨줬나」가 조용히 바뀐다.
                'rewrites_history' => (bool) $b->is_inherited
                    && $b->inherited_from_salesman_id
                    && (int) $b->inherited_from_salesman_id !== (int) $from->id,
            ])->values()->all(),
            'vehicles' => $mine->map($row)->values()->all(),

            // 고를 수 있는 바이어 전체 — 화면의 체크 목록. **바이어별 진행중 차량 대수**를 같이 준다
            //   (몇 대짜리인지 모르면 사람이 나눌 수가 없다).
            'candidates' => $all->map(fn (Buyer $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'in_progress' => $inProgress->where('buyer_id', $b->id)->count(),
            ])->values()->all(),

            // 건너뛰는 것 — **사유와 차량번호를 같이 보여준다.** 카운터로 뭉개면 정작 봐야 할
            //   몇 대가 숫자에 묻힌다(SKILLS §8 #67).
            'skipped' => $settled->map($row)->map(fn ($r) => $r + ['reason' => 'has_settlement'])
                ->concat(
                    $notSelected->map($row)->map(fn ($r) => $r + ['reason' => 'buyer_not_selected'])
                )
                ->concat(
                    $orphan->map($row)->map(fn ($r) => $r + ['reason' => 'no_buyer'])
                )
                ->values()->all(),

            // 승계 표시를 켤지 — **받는 사람(B)의 정산 유형**이 정한다.
            //   사내직원이면 건당 5만 고정(신규 개척이 아니므로), 프리랜서는 비율제라 무관.
            //   🧭 이 판정을 화면에 문장으로 적을 것 — 코드에만 있으면 사람이 반대로 조작한다(§8 #60).
            'marks_inherited' => $this->marksInherited($to),
            'to_type' => $to->type,
        ];
    }

    /**
     * 실행 — 한 트랜잭션. 반환 = `[buyers, vehicles, skipped]` 건수.
     *
     * ⚠️ 인가를 **여기서 다시** 확인한다. 모달을 연 시점의 인가에만 기대면 프로퍼티 주입으로
     *    뚫린다(SKILLS §8 #26 — mutating 은 매번 재인가).
     */
    public function apply(
        Salesman $from,
        Salesman $to,
        User $actor,
        ?string $reason = null,
        ?array $buyerIds = null,
    ): array {
        if (! $actor->canApprove()) {
            throw new AuthorizationException('퇴사 승계는 [관리] 이상만 실행할 수 있습니다.');
        }
        $this->assertPair($from, $to);

        // 🔑 미리보기와 **같은 함수·같은 인자**. 화면이 보여준 그대로가 실행된다.
        $plan = $this->preview($from, $to, $buyerIds);

        // 한 명도 안 고르고 차량도 안 넘기면 아무 일도 안 하는 것 — 빈 감사로그만 남기지 않는다.
        if (! $plan['buyers'] && ! $plan['vehicles']) {
            throw new InvalidArgumentException('넘길 바이어를 하나 이상 고르세요.');
        }
        $marksInherited = $plan['marks_inherited'];

        return DB::transaction(function () use ($from, $to, $plan, $marksInherited, $reason, $actor) {
            foreach ($plan['buyers'] as $row) {
                $buyer = Buyer::find($row['id']);
                if (! $buyer) {
                    continue;   // 그 사이 삭제됨
                }
                $wasSalesman = $buyer->salesman_id;
                $wasInherited = (bool) $buyer->is_inherited;

                $buyer->salesman_id = $to->id;
                if ($marksInherited) {
                    $buyer->is_inherited = true;
                    $buyer->inherited_from_salesman_id = $from->id;
                    $buyer->inherited_at = now()->toDateString();
                }
                // 🚫 프리랜서에게 넘길 때 승계 표시를 켜지 않는다(jin: "프리랜서라면 그대로 넘어가고").
                //    이미 켜져 있던 것을 끄지도 않는다 — 이 도구가 판단할 일이 아니다.
                $buyer->save();

                // Buyer 엔 감사 훅이 없다 — 담당자 이동을 여기서 직접 남긴다.
                AuditLog::recordChange($buyer, 'salesman_id', $wasSalesman, $to->id);
                if ($marksInherited && ! $wasInherited) {
                    AuditLog::recordChange($buyer, 'is_inherited', false, true);
                }
            }

            foreach ($plan['vehicles'] as $row) {
                $vehicle = Vehicle::find($row['id']);
                if (! $vehicle) {
                    continue;
                }
                // 🚨 판정을 **여기서 다시** 한다 — 미리보기 이후에 정산이 생겼을 수 있다.
                //    생겼으면 그 차는 A 것이다(jin 규칙). 조용히 넘기지 않는다.
                if ($vehicle->settlements()->exists()) {
                    continue;
                }
                // 모델 update 로 간다 — bulk update 는 감사·캐시 훅이 안 뜬다(SKILLS §2).
                //   `salesman_id` 는 AUDITED_COLUMNS 라 old→new 가 자동 기록된다.
                $vehicle->update(['salesman_id' => $to->id]);
            }

            // 일괄 출처 — 개별 변경 로그만으로는 「한 번의 퇴사 승계」였다는 사실이 안 보인다.
            //   ⚠️ `column_name` 엔 **실제 컬럼**을 넣는다. 컬럼이 아닌 값을 넣으면 감사로그
            //      드롭박스가 무한히 늘어난다(MEMORY 「살아있는 함정」).
            AuditLog::create([
                'user_id' => $actor->id,
                'auditable_type' => Salesman::class,
                'auditable_id' => $to->id,
                'action' => 'salesman_handover',
                'column_name' => 'salesman_id',
                'old_value' => $from->name,
                'new_value' => sprintf(
                    '%s → %s · 바이어 %d명%s · 차량 %d대 · 정산분 %d대 제외%s',
                    $from->name,
                    $to->name,
                    count($plan['buyers']),
                    $marksInherited ? '(승계표시 ON)' : '',
                    count($plan['vehicles']),
                    count($plan['skipped']),
                    $reason ? ' · '.$reason : '',
                ),
                'ip_address' => request()?->ip(),
            ]);

            return [
                'buyers' => count($plan['buyers']),
                'vehicles' => count($plan['vehicles']),
                'skipped' => count($plan['skipped']),
            ];
        });
    }

    /** 승계 표시는 **받는 사람이 사내직원일 때만** 켠다(건당 5만 고정). 프리랜서는 비율제라 무관. */
    public function marksInherited(Salesman $to): bool
    {
        return $to->defaultSettlementType() === 'per_unit';
    }

    private function assertPair(Salesman $from, Salesman $to): void
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('같은 담당자에게는 승계할 수 없습니다.');
        }
        // 받는 사람은 **활동 중**이어야 한다. 넘기는 쪽(퇴사자)은 이미 비활성일 수 있으므로 안 본다 —
        //   비활성이면 못 넘긴다고 하면 「먼저 내리고 나중에 넘기는」 순서에서 영영 막힌다.
        if (! $to->is_active) {
            throw new InvalidArgumentException('받는 담당자가 비활성 상태입니다.');
        }
    }
}
