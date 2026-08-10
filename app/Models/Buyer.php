<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Buyer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'country_id', 'salesman_id',
        'contact_name', 'contact_email',
        'contact_phone', 'passport_id', 'address', 'memo', 'is_active',
        // 2026-08-04 jin — 퇴사자 승계 바이어. 사내직원 정산 시 건당 5만원 고정(영구).
        'is_inherited', 'inherited_from_salesman_id', 'inherited_at',
        // 2026-08-10 jin — 무담보 한도(단골에게 담보 없이 내주는 매입 한도). NULL·0 = 미설정 = 기존 동작.
        'unsecured_limit_krw',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_inherited' => 'boolean',
        'inherited_at' => 'date',
    ];

    /**
     * 대량 임포트/시더에서 바이어→컨사이니 자동생성을 끄는 플래그 (안전판).
     * 기본 가드는 auth()->check() (아래 booted). ConsigneeImport 처럼 auth 컨텍스트에서
     * 바이어+컨사이니를 함께 만드는 경로가 생기면 이 플래그로 명시 차단.
     */
    public static bool $skipAutoConsignee = false;

    /**
     * jin 2026-07-06 quick win ⑥ — 바이어 등록 시 컨사이니 1번 자동 생성.
     * 근거: 바이어가 본인 수령하는 경우가 있어, 등록 시 동일 정보(이름/주소/이메일/전화/국가)로
     * 컨사이니 1건을 바로 만들어 두면 편하다(추후 컨사이니는 바이어 매칭으로 별도 추가).
     * 자동 생성분은 독립 — 이후 바이어 수정해도 동기화하지 않는다(단순).
     * 가드: 대량 임포트/시더(artisan, auth 없음)는 제외 — 일괄업로드가 컨사이니를 함께 만들 때 중복 방지.
     */
    protected static function booted(): void
    {
        static::created(function (Buyer $buyer) {
            if (self::$skipAutoConsignee || ! auth()->check()) {
                return;
            }

            $buyer->consignees()->create([
                'name' => $buyer->name,
                'country_id' => $buyer->country_id,
                // ID 번호도 함께 넘긴다 (jin 2026-08-06) — 바이어 본인이 수령하는 경우가 많아
                //   같은 번호를 컨사이니에 다시 적게 되던 것을 없앤다. 서류(Invoice E7)가 이 값을 쓴다.
                //   ⚠️ id_value 는 모델 cast 로 암호화 저장된다(바이어 passport_id 는 평문) — 여기 대입만으로 처리됨.
                'id_value' => $buyer->passport_id ?: null,
                'contact_name' => $buyer->contact_name,
                'contact_email' => $buyer->contact_email,
                'contact_phone' => $buyer->contact_phone,
                'address' => $buyer->address,
                'is_active' => $buyer->is_active,
            ]);
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    // 회의확장씬 #5-1 (2026-05-22) — 바이어 영업담당자 직접 지정.
    // [관리] 솔팅에서 buyers.salesman_id IN subordinates_salesman_ids 로 직접 사용.
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /** 승계 전 담당자(퇴사자) — 기록용. 정산 계산엔 `is_inherited` 만 쓴다 (jin 2026-08-04). */
    public function inheritedFromSalesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'inherited_from_salesman_id');
    }

    public function consignees(): HasMany
    {
        return $this->hasMany(Consignee::class);
    }

    public function savingsStatuses(): HasMany
    {
        return $this->hasMany(SavingsStatus::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** 신규 매입 게이트 임계 — 바이어 미수율이 이 값 초과면 신규 차량 등록 차단(관리 승인 우회). */
    public const RECEIVABLE_GATE_THRESHOLD = 0.5;

    /**
     * 바이어 미수금 게이지 — 목록·드로어·매입 게이트 단일 출처 (숫자 일치 보장).
     * 분모: Σ(sale_total_amount × exchange_rate), total>0 && rate>0 인 진행중 차량만.
     * 분자: Σ(sale_unpaid_amount_krw_cache). 환율 미입력 외화는 분모·분자 양쪽 자동 제외.
     * 반환 null = 진행중 판매 이력 없음(분모 0) → 게이지·게이트 미적용.
     *
     * ⚠️ 거래완료(progress_status_cache='거래완료', B/L 발급·완납)는 분모·분자·건수에서 제외 (jin 2026-07-18).
     *   근거: 거래완료 총액이 분모에 섞이면 미수율이 희석돼 "진행중 실제 미수"를 정확히 못 본다.
     *   미수/게이트는 진행중 총액 기준. 거래완료 총액·건수는 completed_krw/completed_count 로 별도 반환(표기용).
     *
     * ⚠️ 선적 후(warehouse_out_date 채워짐=출고 완료)도 분모·분자·건수에서 제외 (jin 2026-07-20).
     *   근거: 매입 등록 락은 "국내(선적 전) 총금액의 50%" 기준 — 이미 출고돼 운송 중인 차의 미수는
     *   신규 매입 한도에 안 잡는다(선적전/후 미수 pivot=출고일, SKILLS §14 와 동일 축). 선적 후 총액·건수는
     *   shipped_krw/shipped_count 로 별도 반환(표기용). 거래완료가 우선 — 거래완료면 completed 버킷.
     *
     * 보증금 여력(jin 2026-07-29 재정의) — **순수 표시용. 아무것도 차단하지 않는다.**
     *   한도 = 선적 전 진행중 차량의 **입금액** × 임계(기본 50%)
     *   사용 = 그 차량들의 **매입 지급**(확정 PBP, Vehicle::purchase_paid_amount 단일출처)
     *   여력 = 한도 − 사용 → "이만큼 더 매입할 수 있다"
     *   판매 잔금이 더 들어오면 한도가 늘어 여력이 회복된다.
     *   ⚠️ 구 정의(선적전 총액 × 50% − 미수)와 분모·분자가 둘 다 다르다. 미수 개념이 아니다.
     *   ⚠️ 매입등록 게이트(C5)는 여기가 아니라 ratio 를 본다 — limit/used/available 을 고쳐도 락은 안 움직인다.
     *   $depositThreshold 미지정 시 Setting::lockThreshold('purchase_registration')(기본 0.5). 배치는 1회 계산해 전달(N+1 방지).
     *
     * @param  iterable  $vehicles  sale_total_amount + progress_status_cache + warehouse_out_date
     *                              + purchaseBalancePayments 가 로드된 Vehicle 컬렉션
     */
    /**
     * 무담보 한도 (jin 2026-08-10) — `$unsecuredLimit` 로 주입한다.
     *   기존 한도는 **선적 전 국내 차량**의 입금액에서만 나오므로, 차가 다 선적되면 0이 되고
     *   게이지 자체가 `null` 이라 매입 락이 통째로 사라진다. 무담보 한도는 그 구간을 메운다.
     *   ⚠️ 그래서 아래 `$totalKrw <= 0` 조기반환은 **무담보 한도가 있으면 하지 않는다** —
     *      바로 그 상황(국내 차 0대)에서 쓰라고 만든 값이기 때문이다.
     */
    public static function computeReceivableGauge(iterable $vehicles, ?float $depositThreshold = null, int $unsecuredLimit = 0): ?array
    {
        $depositThreshold ??= Setting::lockThreshold('purchase_registration');
        $unsecuredLimit = max(0, $unsecuredLimit);
        $totalKrw = 0;
        $unpaidKrw = 0;
        $purchasePaidKrw = 0;   // 선적 전 진행중 차량에 이미 나간 매입 지급 (여력 소진분)
        $count = 0;
        $completedKrw = 0;
        $completedCount = 0;
        $shippedKrw = 0;
        $shippedCount = 0;
        $shippedUnpaidKrw = 0;
        $lockedDownPaymentKrw = 0;   // 무담보에 묶인 계약금 (선적 진입 전인 차량분)
        foreach ($vehicles as $v) {
            $rate = (float) ($v->exchange_rate ?? 0);
            $total = (float) ($v->sale_total_amount ?? 0);
            $rowKrw = ($total > 0 && $rate > 0) ? (int) ($total * $rate) : 0;

            if (($v->progress_status_cache ?? null) === '거래완료') {
                $completedKrw += $rowKrw;
                $completedCount++;

                continue;   // 진행중 미수 기준에서 분리
            }

            if (filled($v->warehouse_out_date)) {
                $shippedKrw += $rowKrw;
                $shippedCount++;
                // 선적 후 미수 — 한도 계산엔 **안 들어간다**(jin 2026-08-10 이번 범위 밖).
                //   무담보 한도를 정할 때 사람이 보고 판단하라고 별도 집계만 한다.
                $shippedUnpaidKrw += (int) ($v->sale_unpaid_amount_krw_cache ?? 0);

                continue;   // 선적 후(출고) — 국내 미수 기준에서 분리
            }

            $count++;
            $totalKrw += $rowKrw;
            $rowUnpaidKrw = (int) ($v->sale_unpaid_amount_krw_cache ?? 0);
            $unpaidKrw += $rowUnpaidKrw;
            $purchasePaidKrw += $v->purchase_paid_amount;   // 매입은 원화 — 환율 환산 불필요

            // 무담보에 묶이는 것 = **「무담보로 지급」 체크된 차량의 계약금**이고,
            //   그 차가 선적 진입 조건을 넘으면 풀린다 (jin 2026-08-10).
            //
            //   🚨 체크를 보는 이유 — 계약금 행만으로는 그 돈이 **바이어가 보낸 것인지 회사가 대신
            //      낸 것인지 알 수 없다**. 무담보는 회사가 대신 내준 몫을 담는 주머니이므로,
            //      사람이 명시한 것만 센다(jin: "이거 실제로 50만원이 누구 돈일 줄 알고?").
            //      체크 안 함 = 바이어 돈 → 무담보 무관. 우회가 아니라 사실 기록이다.
            //   해제 판정은 `Vehicle::isShippingEntryMet()` **단일 출처**다 — 저장 가드도 같은 걸 쓴다.
            //   각자 계산하면 "화면은 풀렸다는데 저장은 막힌다"가 생긴다.
            if (! $v->isShippingEntryMet() && ($v->is_unsecured_down ?? false)) {
                $lockedDownPaymentKrw += $v->confirmed_down_payment;
            }
        }

        // ⚠️ 무담보 한도가 설정된 바이어는 국내 차가 0대여도 게이지를 만든다(그게 이 기능의 목적).
        if ($totalKrw <= 0 && $unsecuredLimit <= 0) {
            return null;
        }

        $paidKrw = max(0, $totalKrw - $unpaidKrw);
        // 보증금 여력 (jin 2026-07-29 재정의) — 한도 = **입금액** × 50%.
        //   구: 선적전 총액 × 50% − 미수. 팔리기만 하고 돈이 안 들어와도 여력이 잡히던 게 문제였다.
        //   신: 실제로 받은 돈의 절반까지 매입에 쓸 수 있고, 매입 지급이 나가면 그만큼 줄고,
        //       판매 잔금이 더 들어오면 한도가 늘어 여력이 회복된다.
        //   ⚠️ 표시 전용 — 차단하지 않는다. 매입등록 게이트(C5)는 아래 ratio 를 그대로 쓴다.
        $baseLimitKrw = (int) ($paidKrw * $depositThreshold);
        $limitKrw = $baseLimitKrw + $unsecuredLimit;
        // 무담보 사용량 (jin 2026-08-10 최종) — **계약금만 담는 독립 주머니**다.
        //   보증금과 섞지 않는다. 규모가 근본적으로 다르기 때문이다:
        //     보증금 = 차값 전체(잔금 포함)를 커버하는 큰 한도(입금액의 절반, 보통 수천만)
        //     무담보 = 계약금 전용 작은 한도(계약금 건당 50~100만 × 몇 건 = 500만 정도)
        //   보증금 초과분을 기준으로 삼으면 **차값 전체가 초과분에 들어가** 500만짜리 한도가
        //   한 번에 날아간다 — 그러면 이 기능이 의미가 없다(jin: "그래서 내가 차감형식을 얘기했던 거야").
        //
        //   그래서 계약금을 걸면 그만큼 빠지고, 그 차가 선적 진입 조건을 넘으면 그만큼 돌아온다.
        //   그게 전부다. 예측이 쉽고 화면 숫자로 다음 동작을 알 수 있다.
        $unsecuredUsedKrw = min($unsecuredLimit, $lockedDownPaymentKrw);

        return [
            'total_krw' => $totalKrw,               // 진행중(선적 전) 총액 (거래완료·출고 제외)
            'unpaid_krw' => $unpaidKrw,
            'paid_krw' => $paidKrw,
            // ⚠️ 무담보 한도만 있고 국내 차가 0대면 $totalKrw = 0 이다 — 0 나누기 방어.
            'paid_pct' => $totalKrw > 0 ? max(0, min(100, $paidKrw / $totalKrw * 100)) : 100.0,
            'ratio' => $totalKrw > 0 ? max(0, min(1, $unpaidKrw / $totalKrw)) : 0.0,   // 게이지 채움·게이트 비교 (미수/진행중총) — 변경 금지
            'vehicle_count' => $count,              // 진행중·선적 전 건수
            'deposit_pct' => (int) round($depositThreshold * 100),  // 한도 비율(%) — 기본 50
            'base_limit_krw' => $baseLimitKrw,      // 담보분 = 입금액 × 설정비율
            'unsecured_limit_krw' => $unsecuredLimit,   // 무담보분 = 바이어별 설정값 (0 = 미설정)
            'locked_down_payment_krw' => $lockedDownPaymentKrw,   // 선적 진입 전 차량의 계약금 합(해제 대기)
            'unsecured_used_krw' => $unsecuredUsedKrw,  // 무담보분 사용 = 위 계약금 중 보증금으로 못 덮은 몫
            'unsecured_available_krw' => max(0, $unsecuredLimit - $unsecuredUsedKrw),   // 무담보 잔액 — 락 판정
            'limit_krw' => $limitKrw,               // 쓸 수 있는 총액 = 담보분 + 무담보분
            'used_krw' => $purchasePaidKrw,         // 이미 쓴 금액 = 매입 지급(계약금·잔금 확정분)
            'available_krw' => max(0, $limitKrw - $purchasePaidKrw),   // 남은 여력 = 이만큼 더 매입 가능
            'completed_krw' => $completedKrw,       // 거래완료 총액 (분리 표기)
            'completed_count' => $completedCount,
            'shipped_krw' => $shippedKrw,           // 선적 후(출고) 총액 (분리 표기)
            'shipped_count' => $shippedCount,
            'shipped_unpaid_krw' => $shippedUnpaidKrw,   // 선적 후 미수 — 한도 계산 미포함, 판단 근거 표시용
        ];
    }

    /**
     * 여러 바이어의 미수 게이지를 한 번에 (N+1 방지) — [buyer_id => gauge].
     *
     * 바이어 목록 행 배경 게이지 · board 읽기 API 의 매입 락 표시가 공유한다.
     * ⚠️ `get()` 의 컬럼 목록을 줄이지 말 것 — 하나만 빠져도 계산이 **조용히** 틀어진다
     *    (관계 컬럼 제한으로 정산액이 20배 틀렸던 사고와 같은 형태).
     *
     * @param  int[]  $buyerIds
     * @return array<int, array>
     */
    public static function computeReceivableGaugesFor(array $buyerIds): array
    {
        $buyerIds = array_values(array_unique(array_map('intval', $buyerIds)));
        if (empty($buyerIds)) {
            return [];
        }

        $vehicles = Vehicle::whereIn('buyer_id', $buyerIds)
            ->with('purchaseBalancePayments')   // 여력의 '사용' 분자 — 없으면 차량당 1쿼리
            ->get([
                'id', 'buyer_id', 'sale_price', 'transport_fee', 'sale_other_costs',
                'commission', 'auto_loading', 'tax_dc', 'exchange_rate',
                'sale_unpaid_amount_krw_cache', 'progress_status_cache', 'warehouse_out_date',
                // ⚠️ 무담보 판정에 쓰인다 — 빠지면 조용히 false 가 되어 묶인 계약금이 0 으로 보인다.
                'is_unsecured_down',
            ]);

        $depositThreshold = Setting::lockThreshold('purchase_registration');   // 1회 조회(N+1 방지)
        // 무담보 한도 — 대상 바이어분만 1쿼리로. 기능 토글이 꺼진 회사에서는 전부 0 으로 본다.
        $limits = Setting::unsecuredLimitEnabled()
            ? static::whereIn('id', $buyerIds)->pluck('unsecured_limit_krw', 'id')
            : collect();

        $out = [];
        foreach ($vehicles->groupBy('buyer_id') as $bid => $group) {
            $gauge = static::computeReceivableGauge($group, $depositThreshold, (int) ($limits[$bid] ?? 0));
            if ($gauge !== null) {
                $out[(int) $bid] = $gauge;
            }
        }

        // 차량이 한 대도 없는데 한도만 설정된 바이어 — 위 루프는 **차량에서 출발**하므로 통째로 빠진다.
        //   이 기능이 겨냥하는 게 담보 0인 바이어라, 여기서 빠지면 "패널엔 있는데 목록엔 없다"가 된다.
        foreach ($limits as $bid => $limit) {
            if ((int) $limit > 0 && ! isset($out[(int) $bid])) {
                $out[(int) $bid] = static::computeReceivableGauge([], $depositThreshold, (int) $limit);
            }
        }

        return $out;
    }

    /** 이 바이어의 미수 게이지 (자기 차량 로딩). 매입 게이트가 사용. */
    public function receivableGauge(): ?array
    {
        // purchaseBalancePayments = 여력의 '사용' 분자. eager load 안 하면 차량당 1쿼리.
        return static::computeReceivableGauge(
            $this->vehicles()->with('purchaseBalancePayments')->get(),
            null,
            $this->effectiveUnsecuredLimit(),   // 기능 토글 반영 — 화면과 게이트가 같은 값을 본다
        );
    }

    /**
     * 무담보 한도가 설정된 바이어인가 (jin 2026-08-10) — **게이트 분기의 단일 출처**.
     *
     * 설정됨  → 한도 기반 게이트(여력 소진 시 차단). 국내 차가 0대여도 판정된다.
     * 미설정  → 기존 미수율 기반 게이트 그대로. 운영 충격 없음(jin 확정).
     */
    /**
     * 실효 무담보 한도 — **기능 토글까지 반영한 단일 출처**.
     * 시스템관리자가 기능을 끈 회사에서는 컬럼에 값이 있어도 0으로 본다(판정·표시 동시에 꺼짐).
     */
    public function effectiveUnsecuredLimit(): int
    {
        if (! Setting::unsecuredLimitEnabled()) {
            return 0;   // 기능 OFF — 컬럼을 볼 필요도 없다
        }

        // 🚨 컬럼이 안 실린 인스턴스(`select`·`pluck` 로 컬럼을 제한한 쿼리)에서 부르면
        //    `?? 0` 이 조용히 "미설정"으로 만들어 **락이 사라진다**. 그 형태의 사고가 이미 있었다
        //    (`with('관계:id,name')` 로 tier 컬럼이 빠져 정산액이 20배 틀림 — 예외도 경고도 없었다).
        //    금액·락을 좌우하는 값이라 조용히 넘어가지 않고 큰 소리로 죽인다.
        if (! array_key_exists('unsecured_limit_krw', $this->getAttributes())) {
            throw new \LogicException(
                'Buyer::effectiveUnsecuredLimit() — unsecured_limit_krw 가 로드되지 않았습니다. '
                .'매입 락 판정에 쓰이므로 컬럼을 제한한 쿼리로 조회하지 마세요.'
            );
        }

        return max(0, (int) ($this->unsecured_limit_krw ?? 0));
    }

    public function hasUnsecuredLimit(): bool
    {
        return $this->effectiveUnsecuredLimit() > 0;
    }
}
