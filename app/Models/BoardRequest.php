<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * board → erp 요청·확인 신호 — 카톡으로 하던 "해주세요" 두 마디.
 *
 * 권위 = docs/integration/board-portal-api.md §11 / 계획 = docs/design/board-erp-request-ack.md.
 *
 * 💰 **금액을 싣는다 (2026-08-11 개정).** 받는 사람이 얼마를 보내야 하는지 몰라 결국 카톡으로
 *    되돌아갔다 — 금액이 없으면 신호가 일을 끝내지 못한다. 다만 **표시 전용**이다:
 *    🚫 회계 컬럼(`final_payments`·`purchase_balance_payments`·`vehicles.*`)에 간접적으로라도
 *    쓰지 않는다(§11-5 흡수 금지 유지). 자동 기입은 은행 API 연동 이후.
 *
 * 🗺️ **type 별 성격은 TYPE_META 한 곳에만 둔다.** 화면 3곳(목록 뱃지·드로어·알람센터)이 각자
 *    `=== TYPE_PURCHASE_PAYMENT` 로 분기하던 걸 맵으로 모았다. 안 그러면 type 을 늘릴 때마다
 *    **아무 에러 없이 뱃지가 안 뜨는** 화면이 생긴다(SKILLS §8 #45 — 같은 판정의 복제).
 *
 * 👤 확인 주체 = `User::canConfirmFinance()` = super · admin · 업무관리자 · role∈{재무, 관리}.
 *    **재무 전용이 아니다**(jin 2026-08-09). 핵심은 영업이 스스로 확인할 수 없다는 것 —
 *    요청한 사람과 통장을 본 사람이 갈려야 신호에 의미가 있다.
 */
class BoardRequest extends Model
{
    /**
     * 매입 [입금요청] — 차량 1대 단위. **deprecated (2026-08-11)**: 계약금/잔금으로 쪼개졌다.
     *
     * ⚠️ **수신은 계속 허용한다 — 화이트리스트에서 빼지 말 것.** board 운영(master)은 아직 이 type 을
     *    보내는 구버전이고, ERP 가 먼저 배포된다(§7 배포 순서). 여기서 422 로 튕기면 board 운영의
     *    **유일한 입금요청 경로가 죽는다**. board master 가 신버전을 실은 뒤에 별도 커밋으로 뺀다.
     */
    public const TYPE_PURCHASE_PAYMENT = 'purchase_payment';

    /** 매입 [계약금] — 차량 1대 + 금액. 계약금 지급은 ERP 가 알 방법이 없어 **수동 확인만**. */
    public const TYPE_PURCHASE_DEPOSIT = 'purchase_deposit';

    /** 매입 [매입잔금] — 차량 1대 + 금액. 매입 미지급 0 이면 자동소멸(+수동 확인도 허용). */
    public const TYPE_PURCHASE_BALANCE = 'purchase_balance';

    /** 판매 [판매대금확인] — 바이어 1 + 차량 N대 묶음. */
    public const TYPE_SALE_PAYMENT_CONFIRM = 'sale_payment_confirm';

    public const TYPES = [
        self::TYPE_PURCHASE_PAYMENT,
        self::TYPE_PURCHASE_DEPOSIT,
        self::TYPE_PURCHASE_BALANCE,
        self::TYPE_SALE_PAYMENT_CONFIRM,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_CANCELLED];

    /**
     * type 별 성격 — **단일 출처**. 새 type 을 늘리려면 여기 한 줄만 추가한다.
     *
     * - `badge`/`title`/`action` = lang 키(ko·en 양쪽에 있어야 한다).
     * - `color`   = 뱃지 색. ⚠️ **blue·purple 만 쓴다** — 빌드된 CSS 에 있는 색이라 안전하다.
     *               새 색을 넣으려면 `public/build/assets/*.css` 에 그 클래스가 있는지 먼저 확인할 것
     *               (SKILLS §8 #50 — 없으면 켜도 회색으로 보인다).
     * - `manual_confirm` = 드로어에 「입금 확인」 버튼이 뜨는가.
     * - `auto_resolve`   = 매입 미지급 0 이면 스스로 닫히는가.
     *      ⚠️ **계약금은 false 여야 한다.** 미지급 0 = 잔금까지 다 준 상태다. 그때 계약금 신호가
     *      비로소 꺼지면 "계약금 아직 안 보냈다"는 거짓 신호가 차 인수 시점까지 화면에 남는다.
     * - `amount` = 금액을 필수로 받는가(board 가 빈 값으로 보내면 422).
     * - `payee`  = 알림톡에 **송금할 매입처 계좌**를 실을 것인가.
     *      ⚠️ **판매대금확인은 false 여야 한다.** 그건 "돈이 들어왔으니 확인해달라"는 신호인데,
     *      거기에 매입처 계좌가 찍히면 받는 사람이 **거기로 돈을 보낼 수 있다**(방향이 반대다).
     */
    public const TYPE_META = [
        self::TYPE_PURCHASE_PAYMENT => [
            'badge' => 'vehicle.board_badge_purchase',
            'title' => 'vehicle.board_title_purchase',
            'action' => 'alarm.board_purchase_action',
            'alarm' => 'board_purchase_payment',
            'payee' => true,
            'task' => 'alarm.task_board_purchase',
            'color' => 'blue',
            'manual_confirm' => false,
            'auto_resolve' => true,
            'amount' => false,
        ],
        self::TYPE_PURCHASE_DEPOSIT => [
            'badge' => 'vehicle.board_badge_deposit',
            'title' => 'vehicle.board_title_deposit',
            'action' => 'alarm.board_deposit_action',
            'alarm' => 'board_purchase_deposit',
            'payee' => true,
            'task' => 'alarm.task_board_deposit',
            'color' => 'blue',
            'manual_confirm' => true,
            'auto_resolve' => false,
            'amount' => true,
        ],
        self::TYPE_PURCHASE_BALANCE => [
            'badge' => 'vehicle.board_badge_balance',
            'title' => 'vehicle.board_title_balance',
            'action' => 'alarm.board_balance_action',
            'alarm' => 'board_purchase_balance',
            'payee' => true,
            'task' => 'alarm.task_board_balance',
            'color' => 'blue',
            'manual_confirm' => true,
            'auto_resolve' => true,
            'amount' => true,
        ],
        self::TYPE_SALE_PAYMENT_CONFIRM => [
            'badge' => 'vehicle.board_badge_sale',
            'title' => 'vehicle.board_title_sale',
            'action' => 'alarm.board_sale_action',
            'alarm' => 'board_sale_confirm',
            'payee' => false,
            'task' => 'alarm.task_board_sale',
            'color' => 'purple',
            'manual_confirm' => true,
            'auto_resolve' => false,
            // 판매대금확인은 금액칸을 만들지 않는다 (jin 2026-08-11) — 입금요청만 분리 대상이다.
            'amount' => false,
        ],
    ];

    protected $fillable = [
        'batch_id', 'type', 'vehicle_id', 'buyer_id', 'amount_krw', 'status',
        'requested_by_email', 'requested_at', 'confirmed_by_id', 'confirmed_at', 'note',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'amount_krw' => 'integer',
    ];

    /** type 의 성격 한 줄. 모르는 type(옛 데이터)이면 빈 배열 — 호출측이 ?? 로 degrade. */
    public static function meta(?string $type): array
    {
        return self::TYPE_META[$type] ?? [];
    }

    /** 알람 type(`board_*`)으로 역조회 — 알람센터가 색·문구를 고를 때 쓴다. */
    public static function metaByAlarmType(string $alarmType): array
    {
        foreach (self::TYPE_META as $meta) {
            if ($meta['alarm'] === $alarmType) {
                return $meta;
            }
        }

        return [];
    }

    /**
     * 이 플래그가 켜진 type 목록 — `typesWith('auto_resolve')` 처럼 쓴다.
     * 쿼리 `whereIn` 에 그대로 넣어 판정 복제를 막는다.
     *
     * @return array<int, string>
     */
    public static function typesWith(string $flag): array
    {
        return array_keys(array_filter(self::TYPE_META, fn (array $m) => ($m[$flag] ?? false) === true));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    /**
     * 열린 신호 수 — **뱃지 단일 출처**. 재무처리 탭 · 사이드바 · 알람이 전부 이걸 쓴다.
     * 세는 식을 복제하면 화면마다 숫자가 갈린다(SKILLS §8 — 같은 공식이 3곳에 있던 회사이익 사고).
     */
    public static function openCount(?string $type = null): int
    {
        return self::query()->open()
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            // 차량이 지워진 신호는 화면에 못 그리므로 세지도 않는다(뱃지 숫자 ≠ 목록 건수 방지).
            ->whereHas('vehicle')
            ->count();
    }

    /**
     * 멱등 — 같은 차 + 같은 type 에 이미 열린 요청이 있으면 만들지 않는다.
     * board 가 재전송해도 뱃지가 두 개 생기지 않게 하는 단일 지점.
     */
    public static function hasOpenFor(int $vehicleId, string $type): bool
    {
        return self::query()->where('vehicle_id', $vehicleId)->ofType($type)->open()->exists();
    }

    /**
     * 신호 1건 올리기. 이미 열려 있으면 null (호출측이 skipped 로 응답).
     * batch_id 를 넘기면 그 묶음에 붙고, 없으면 새 묶음(입금요청 = 1행짜리 묶음).
     *
     * ⚠️ 이름이 `open` 이 아닌 이유 — scope `open()` 과 충돌한다. Eloquent 는 `Model::scopeX` 를
     *    `Model::x()` 로 노출하는데, **같은 이름의 실제 static 메서드가 있으면 그쪽이 이긴다.**
     *    그래서 `BoardRequest::raise()->get()`(조회 의도)이 생성 메서드로 가서 ArgumentCountError 로 죽는다.
     *    (2026-08-09 실제로 밟았다.) 조회는 `BoardRequest::query()->open()`, 생성은 `raise()`.
     */
    public static function raise(
        int $vehicleId,
        string $type,
        string $requestedByEmail,
        ?int $buyerId = null,
        ?string $batchId = null,
        ?string $note = null,
        ?int $amountKrw = null,
    ): ?self {
        if (! in_array($type, self::TYPES, true) || self::hasOpenFor($vehicleId, $type)) {
            return null;
        }

        $row = self::create([
            'batch_id' => $batchId ?: (string) Str::uuid(),
            'type' => $type,
            'vehicle_id' => $vehicleId,
            'buyer_id' => $buyerId,
            // 금액을 받지 않는 type 에 값이 딸려와도 저장하지 않는다(표시 자리가 없어 유령 데이터가 된다).
            'amount_krw' => (self::meta($type)['amount'] ?? false) ? $amountKrw : null,
            'status' => self::STATUS_OPEN,
            'requested_by_email' => $requestedByEmail,
            'requested_at' => now(),
            'note' => $note,
        ]);

        $row->syncTaskAlarm();

        return $row;
    }

    /**
     * 열린 요청의 **금액만** 고쳐 쓴다 — 오타 정정 재전송 (jin 2026-08-11).
     *
     * 멱등키가 `(vehicle_id, type)` 이라 재전송은 `raise()` 에서 null 이 된다. 그런데 **금액이 이
     * 기능의 전부**라, 300만을 350만으로 고쳐 다시 보냈는데 옛 금액이 남으면 받는 사람이 틀린 돈을
     * 보낸다. 그래서 금액만 갱신한다 — **행을 새로 만들지 않으므로 중복 뱃지가 안 생긴다**(멱등 유지).
     *
     * 갱신했으면 그 행을, 아니면 null(= 호출측이 종전대로 skipped 처리).
     * - 금액을 안 받는 type(판매대금확인·구 입금요청)은 대상이 아니다.
     * - **같은 금액이면 갱신하지 않는다** — 오클릭 재전송에 알림톡이 두 번 나가지 않게.
     *
     * ⚠️ 갱신하면 호출측이 **알림톡을 다시 보내야 한다.** 안 보내면 받는 사람 카톡엔 옛 금액이,
     *    화면엔 새 금액이 남아 둘이 갈린다 — 그게 이 기능이 막으려던 바로 그 사고다.
     */
    public static function refreshAmount(int $vehicleId, string $type, ?int $amountKrw): ?self
    {
        if (! (self::meta($type)['amount'] ?? false) || ! $amountKrw) {
            return null;
        }

        $row = self::query()->where('vehicle_id', $vehicleId)->ofType($type)->open()->first();
        if (! $row || (int) $row->amount_krw === (int) $amountKrw) {
            return null;
        }

        $row->update(['amount_krw' => $amountKrw]);

        return $row;
    }

    /**
     * 알람 생성 — 재무 처리 화면에 **들어가 보기 전엔 모르던 문제**를 없앤다(jin 2026-08-09).
     *
     * `target_role='관리'` 를 쓰면 기존 `TaskAlarm::scopeVisibleTo` 분기를 그대로 타서
     * **admin·업무관리자는 전체 / role='관리' 는 본인 팀**(휴가 위임 포함)이 본다 —
     * `canSeeAlarm` 과의 lockstep 을 건드리지 않는다.
     */
    public function syncTaskAlarm(): void
    {
        if (! Schema::hasTable('task_alarms') || $this->status !== self::STATUS_OPEN) {
            return;
        }

        $alarm = TaskAlarm::firstOrNew([
            'type' => self::meta($this->type)['alarm'] ?? null,
            'vehicle_id' => $this->vehicle_id,
            'resolved_at' => null,
        ]);
        $alarm->target_role = '관리';
        $alarm->message_meta = TaskAlarm::sanitizeMeta([
            'vehicle_number' => $this->vehicle?->vehicle_number,
        ]);
        $alarm->save();
    }

    /** 신호가 닫히면 알람도 같이 닫는다 — 안 하면 처리한 일이 벨에 계속 남는다. */
    public function resolveTaskAlarm(string $reason): void
    {
        if (! Schema::hasTable('task_alarms')) {
            return;
        }

        TaskAlarm::where('type', self::meta($this->type)['alarm'] ?? null)
            ->where('vehicle_id', $this->vehicle_id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => $reason]);
    }

    /**
     * 확인 처리 — 재무가 체크(수동) 또는 자동 해소. $user 없으면 시스템 자동 해소.
     *
     * ⚠️ **일방향이다(의도).** 자동 해소 뒤 잔금이 삭제돼 미지급이 다시 생겨도 이 요청은 안 열린다.
     *    되열림을 만들지 말 것 — 지급/취소가 오갈 때마다 뱃지가 깜빡여 신호의 신뢰가 깨진다.
     *    다시 필요하면 board 가 재전송하면 되고, 멱등 가드는 done 이후 재요청을 허용한다
     *    (`BoardRequestModelTest::test_can_reopen_after_done`).
     */
    public function markDone(?User $user = null): void
    {
        if ($this->status !== self::STATUS_OPEN) {
            return;
        }

        $this->update([
            'status' => self::STATUS_DONE,
            'confirmed_by_id' => $user?->id,
            'confirmed_at' => now(),
        ]);

        $this->resolveTaskAlarm($user ? 'confirmed' : 'auto_resolved');
    }

    /**
     * 묶음 집계 상태 — board 칩(3/5)·erp 카드 헤더 공용 단일 출처.
     * 전부 done = done / 일부 done = partial / 하나도 안 됐으면 open / 남은 게 없으면 cancelled.
     *
     * @param  Collection<int, self>  $lines
     */
    public static function batchStatus($lines): string
    {
        $live = $lines->where('status', '!=', self::STATUS_CANCELLED);
        if ($live->isEmpty()) {
            return self::STATUS_CANCELLED;
        }

        $done = $live->where('status', self::STATUS_DONE)->count();
        if ($done === 0) {
            return self::STATUS_OPEN;
        }

        return $done === $live->count() ? self::STATUS_DONE : 'partial';
    }
}
