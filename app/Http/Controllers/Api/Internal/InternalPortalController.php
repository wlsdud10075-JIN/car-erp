<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Services\ExchangeRateService;
use App\Services\PurchaseRegistrationGate;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * board 영업 포털 ④ 재무 읽기 API (읽기전용).
 *
 * 권위 스펙 = docs/integration/board-portal-api.md §3·§4.
 * - 응답은 **명시 화이트리스트만** — RRN(nice_reg_owner_rrn)·계좌(purchase_seller_account)·
 *   마진(sales/vat/total_margin) 절대 미포함. toArray() 금지.
 * - accessor/cache 그대로 반환(raw SQL 재계산 금지 = drift). 환율0 외화 = unpaid_krw null.
 * - 본인격리 = SalesmanResolver::resolveActiveOrFail (퇴사자 403).
 */
class InternalPortalController extends Controller
{
    private function salesmanId(Request $request): int
    {
        return SalesmanResolver::resolveActiveOrFail((string) $request->query('salesman_email', ''))->id;
    }

    /** 영업 본인 담당 차량 base 쿼리 (IDOR 단일출처). */
    private function ownVehicles(int $salesmanId)
    {
        return Vehicle::query()->whereNull('deleted_at')->where('salesman_id', $salesmanId);
    }

    /**
     * 환율 read — board 가 car-erp 값을 그대로 받아 씀 (인계 handoff-car-erp-exchange-rate).
     * ⚠️ 스코프 없음 (환율은 전역값, salesman_email 불필요). HMAC 인증만.
     * car-erp 가 실제 계산·저장에 쓰는 네이버 전신환 매입률(송금받을때) 그대로 노출.
     * 값은 car-erp 원본 그대로(소수 가능) — 반올림하면 board 값과 어긋나 통일 목적 무너짐.
     * JPY 는 100엔 기준(car-erp 관례). board 는 필요한 통화만 사용, 없는 키는 board 폴백.
     */
    public function rates(): JsonResponse
    {
        $service = app(ExchangeRateService::class);

        return response()->json([
            'rates' => $service->getRates() ?? [],
            'fetched_at' => $service->fetchedAt(),
            'source' => 'naver_전신환매입률',
        ]);
    }

    public function receivables(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = $this->ownVehicles($sid)->where('sale_price', '>', 0)->with('buyer')->get()
            ->map(fn (Vehicle $v) => Vehicle::portalMeta($v) + [
                'vehicle_number' => $v->vehicle_number,
                'buyer' => $v->buyer?->name,
                'currency' => $v->currency,
                'exchange_rate' => $v->exchange_rate !== null ? (float) $v->exchange_rate : null,
                'sale_total' => (float) $v->sale_total_amount,
                'unpaid_krw' => $v->sale_unpaid_amount_krw_cache,   // null = 환율 미입력 (완납 아님)
                'unpaid_ratio' => $v->unpaid_ratio,                 // 0~1(미납률)|0.0(완납)|null(판매가 미입력). 통화 비의존 accessor
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function sales(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        // 진행상태 필터 (board 인계 2026-08-09 요청 ②) — 판매내역에서 거래완료인지 진행중인지 구분이 안 됐다.
        //   board 가 받아놓고 감추면 트래픽이 그대로라 의미가 없으므로 **서버에서 건다**.
        //   progress_status_cache 는 인덱스가 있어 필터가 실제로 행을 줄인다.
        $exclude = array_values(array_filter(array_map(
            'trim', explode(',', (string) $request->query('exclude_status', ''))
        )));
        // 운항 필터 (2026-08-09) — 진행상태와 **직교**하는 축이라 exclude_status 와 동시에 걸 수 있다.
        //   ⚠️ 값은 영문 키다(라벨은 한글) — 쿼리는 HMAC 서명 대상이라 한글이 들어가면 인코딩 차이로 서명이 깨진다.
        $sailing = (string) $request->query('sailing', '');
        $sailing = in_array($sailing, Vehicle::SAILING_PHASES, true) ? $sailing : '';

        $data = $this->ownVehicles($sid)->where('sale_price', '>', 0)->with('buyer')
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('progress_status_cache', $exclude))
            ->when($sailing !== '', fn ($q) => $q->sailing($sailing))
            ->get()
            ->map(fn (Vehicle $v) => Vehicle::portalMeta($v) + [
                // §11 요청·확인 신호용 식별자 (2026-08-08) — board 가 [판매대금확인] 을 보내려면
                //   차량 id 와 **바이어 id** 가 필요하다. 바이어를 이름 문자열로 맞추면 동명이인·표기흔들림에
                //   깨지고, 그건 곧 422 buyer_mismatch 로 튕기거나 엉뚱한 바이어에 묶인다.
                //   PII 아님(내부 정수) + 스코프는 ownVehicles 그대로 → §3 화이트리스트 취지 유지.
                'vehicle_id' => $v->id,
                'buyer_id' => $v->buyer_id,
                'vehicle_number' => $v->vehicle_number,
                // ⚠️ ERP 값 **그대로** 내보낸다 — board 용으로 추리거나 이름을 바꾸면
                //    "ERP엔 있는데 board엔 없다"가 생긴다(jin 2026-08-09 확정).
                'progress_status' => $v->progress_status_cache,
                // 운항 상태 (2026-08-09) — 진행상태와 **별개 축**이다. 진행상태가 선적중이든 거래완료든
                //   선적일+ETA 가 있으면 배 위에 있다. `sailing` = 기계용 키(필터값과 동일) / `sailing_status` = 표시 라벨.
                //   ⚠️ 「도착예정」은 ETA 가 지났다는 뜻이지 **실제 입항 확인이 아니다** — board 화면에도 그렇게 쓸 것.
                'sailing' => $v->sailing_phase,
                'sailing_status' => $v->sailing_status,
                'vessel_name' => $v->vessel_name,
                'shipping_date' => $v->shipping_date?->toDateString(),
                'eta_date' => $v->eta_date?->toDateString(),
                'buyer' => $v->buyer?->name,
                'currency' => $v->currency,
                'sale_price' => (float) $v->sale_price,
                'sale_date' => $v->sale_date?->toDateString(),
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = $this->ownVehicles($sid)->where('purchase_price', '>', 0)->get()
            ->map(fn (Vehicle $v) => [
                // §11 [입금요청] 전송용 식별자 (2026-08-08) — 없으면 board 버튼이 비활성으로 죽는다.
                'vehicle_id' => $v->id,
                'vehicle_number' => $v->vehicle_number,
                'purchase_price' => (float) $v->purchase_price,
                'cost_total' => $v->cost_total,
                'purchase_unpaid' => $v->purchase_unpaid_amount,
                'purchase_date' => $v->purchase_date?->toDateString(),
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * 재고 3분류 (board 인계 2026-08-09 요청 ①) — `erp/inventory` 화면의 미러.
     *
     * 왜: board 「매입내역」은 `purchases()`(매입가>0 **전량**)라 필터도 페이징도 없이
     * 영업이 평생 매입한 차가 매번 통째로 왔다. 단조증가라 절대 안 줄어든다.
     * 재고는 `inStock()` 이라 집합이 유한하고(영업당 20~50대), 누적되는 꼬리는 `shipped_out` 으로 빠진다.
     *
     * ⚠️ **분류 정의는 화면 scope 를 그대로 재사용한다** — 여기에 조건을 옮겨 적으면 갈리는 순간
     *    "ERP엔 재고인데 board엔 없다"가 된다. `inStock()` 은 출고일뿐 아니라 매입완납·거래완료까지
     *    보는 복합 조건이라 특히 그렇다.
     * 🚫 마진·PII 없음(§3) — 화면에도 마진 노출이 0건이라 그대로 옮기면 된다.
     */
    public function inventory(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);

        $category = (string) $request->query('category', 'general');
        if (! in_array($category, ['awaiting_payment', 'general', 'pre_ship', 'shipped_out'], true)) {
            return response()->json([
                'error' => 'invalid_category',
                'message' => 'category 는 awaiting_payment | general | pre_ship | shipped_out 중 하나여야 합니다.',
            ], 422);
        }

        $search = trim((string) $request->query('search', ''));
        $isShippedOut = $category === 'shipped_out';

        $q = $this->ownVehicles($sid)
            ->with(['buyer', 'purchaseBalancePayments'])   // purchase_unpaid_amount·warehouse_in_date accessor N+1 방지
            // scope 를 그대로 쓴다 — generalStock/preShippingStock 은 inStock() 을 이미 품고 있다.
            //   awaiting_payment(지급대기) = 매입 대금이 남은 차 = 입고 전. **[입금요청]을 보낼 대상이 정확히 이 집합**이라
            //   재고 3분류만으로는 board 가 버튼을 달 곳이 없다(jin 2026-08-09 — ERP 재고관리에도 같은 탭을 만들었다).
            ->when($category === 'awaiting_payment', fn ($q2) => $q2->awaitingPurchasePayment())
            ->when($category === 'general', fn ($q2) => $q2->generalStock())
            ->when($category === 'pre_ship', fn ($q2) => $q2->preShippingStock())
            ->when($isShippedOut, fn ($q2) => $q2->whereNotNull('warehouse_out_date'))
            // 검색 대상은 화면과 동일(소유자명 제외 — PII).
            ->when($search !== '', fn ($q2) => $q2->where(fn ($q3) => $q3
                ->where('vehicle_number', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('model_type', 'like', "%{$search}%")
                ->orWhere('nice_reg_vin', 'like', "%{$search}%")
                ->orWhere('export_declaration_number', 'like', "%{$search}%")
                ->orWhere('vessel_name', 'like', "%{$search}%")
                ->orWhere('container_number', 'like', "%{$search}%")
            ))
            ->when($isShippedOut,
                fn ($q2) => $q2->orderByDesc('warehouse_out_date')->orderByDesc('id'),
                fn ($q2) => $q2->orderByRaw('salesman_id IS NULL ASC')->orderBy('salesman_id')->orderBy('purchase_date')
            );

        $total = (clone $q)->count();

        // 누적되는 건 shipped_out 뿐이라 거기만 잘라 보낸다(board 는 최근 30건 + [더 보기] offset).
        if ($isShippedOut) {
            $limit = min(max((int) $request->query('limit', 30), 1), 200);
            $q->offset(max((int) $request->query('offset', 0), 0))->limit($limit);
        }

        $data = $q->get()->map(fn (Vehicle $v) => Vehicle::portalMeta($v) + [
            'vehicle_id' => $v->id,                       // §11 [입금요청] 전송용 — 없으면 board 버튼이 죽는다
            'vehicle_number' => $v->vehicle_number,
            'progress_status' => $v->progress_status_cache,
            // 운항 상태 (2026-08-09) — 출고완료 탭에서 특히 쓸모 있다("나갔다"만으론 배 위인지 도착인지 모른다).
            //   재고(지급대기·일반·선적전)는 출고 전이라 대개 null 이다.
            'sailing' => $v->sailing_phase,
            'sailing_status' => $v->sailing_status,
            'vessel_name' => $v->vessel_name,
            'shipping_date' => $v->shipping_date?->toDateString(),
            'eta_date' => $v->eta_date?->toDateString(),
            'stock_location' => $v->stock_location,
            'stock_location_note' => $v->stock_location_note,
            'warehouse_in_date' => $v->warehouse_in_date?->toDateString(),
            'warehouse_out_date' => $v->warehouse_out_date?->toDateString(),
            'buyer_id' => $v->buyer_id,                   // 일반재고는 바이어 미정이라 null 가능
            'buyer' => $v->buyer?->name,
            'purchase_price' => (float) $v->purchase_price,
            'purchase_unpaid' => $v->purchase_unpaid_amount,
            'purchase_date' => $v->purchase_date?->toDateString(),
        ])->values();

        return response()->json([
            'count' => $data->count(),
            'total' => $total,          // shipped_out 의 [더 보기] 종료 판정용
            'category' => $category,
            'data' => $data,
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $data = Settlement::query()->where('salesman_id', $sid)->with('vehicle')->get()
            ->map(fn (Settlement $s) => [
                'vehicle_number' => $s->vehicle?->vehicle_number,
                'status' => $s->settlement_status,
                'actual_payout' => $s->actual_payout,   // 실지급액 — 마진 raw 는 미포함
                'confirmed_at' => $s->confirmed_at?->toDateString(),
                'paid_at' => $s->paid_at?->toDateString(),   // 실제 지급일 — board 가 받은 月(5/6월) 기준으로 묶음
            ])->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * 바이어별 묶음 — 영업이 "이 바이어가 나에게 얼마 이득을 줬나"를 보는 뷰.
     *
     * - 판매(sale): 바이어별 판매금액 합(통화별). 바이어 = 판매측 개념(`buyer_id`).
     * - 정산(이득): 바이어 차량의 `actual_payout`(영업 실지급액) 합 = "나에게 준 이득".
     *   accessor 그대로 합산(환차·이월 반영). paid(확정)/전체 분리.
     * - 매입은 구입처(`purchase_from`) 기준이라 바이어 무관 → 미포함(설계 확정).
     */
    public function byBuyer(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);

        $byBuyer = [];

        // 판매금액 — 바이어 배정된(판매된) 본인 차량
        foreach ($this->ownVehicles($sid)->where('sale_price', '>', 0)->whereNotNull('buyer_id')->with('buyer')->get() as $v) {
            $bid = $v->buyer_id;
            $byBuyer[$bid] ??= $this->emptyBuyerRow($v->buyer?->name);
            $byBuyer[$bid]['vehicle_count']++;
            $cur = $v->currency ?: 'KRW';
            $byBuyer[$bid]['sales_by_currency'][$cur] = ($byBuyer[$bid]['sales_by_currency'][$cur] ?? 0) + (float) $v->sale_price;
        }

        // 정산 실지급액 — 본인 정산을 차량의 바이어로 귀속
        foreach (Settlement::query()->where('salesman_id', $sid)->with('vehicle.buyer')->get() as $s) {
            $bid = $s->vehicle?->buyer_id;
            if ($bid === null) {
                continue;
            }
            $byBuyer[$bid] ??= $this->emptyBuyerRow($s->vehicle->buyer?->name);
            $byBuyer[$bid]['payout_total_krw'] += (int) $s->actual_payout;
            if ($s->settlement_status === 'paid') {
                $byBuyer[$bid]['payout_paid_krw'] += (int) $s->actual_payout;
            }
        }

        $data = collect($byBuyer)
            ->map(fn (array $row, $bid) => ['buyer_id' => (int) $bid] + $row)
            ->sortByDesc('payout_total_krw')   // 이득 큰 바이어부터 (채권 TOP10 패턴)
            ->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /** 바이어 1행 초기값. payout: 전체(예상+확정) / paid(확정)만 분리. */
    private function emptyBuyerRow(?string $name): array
    {
        return [
            'buyer' => $name,
            'vehicle_count' => 0,
            'sales_by_currency' => [],
            'payout_total_krw' => 0,
            'payout_paid_krw' => 0,
        ];
    }

    /**
     * board 경매/구매 드로어용 — 영업 본인 바이어 목록 (드롭다운).
     * jin 2026-06-23: 전체 활성이 아니라 **영업 본인 바이어만**(IDOR 격리 유지). board 는
     * salesman_email 로 본인 바이어만 보고 신차에 지정. 응답 = 화이트리스트만(연락처·PII 금지).
     */
    public function buyers(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $buyers = Buyer::query()->where('salesman_id', $sid)->where('is_active', true)
            ->with('country')->orderBy('name')->get();

        // 🔒 매입 등록 락 (2026-08-10) — board 가 **낙찰 전에** 막을 수 있게 같이 내려준다.
        //   ERP 차량관리는 저장 시점에 막지만, board 는 이미 `status='won'`(= 돈이 나간 뒤)에
        //   보내므로 그때 거부해봐야 늦다. 예방은 바이어를 고르는 이 화면에서만 가능하다.
        //   판정은 `PurchaseRegistrationGate` 단일 출처 — 조건을 여기 옮겨 적지 말 것(SKILLS §8 #44).
        //   ⚠️ 키가 없는 바이어 = 판정 근거 없음 = **통과**(락 아님). board 도 그렇게 읽어야 한다.
        $verdicts = PurchaseRegistrationGate::forBuyerIds($buyers->pluck('id')->all());

        $data = $buyers->map(function (Buyer $b) use ($verdicts) {
            $v = $verdicts[$b->id] ?? null;
            $g = $v['gauge'] ?? null;

            return [
                'id' => $b->id,
                'name' => $b->name,
                'country' => $b->country?->name,
                // 평탄한 bool — board 가 최소 구현으로도 막을 수 있게.
                'purchase_locked' => (bool) ($v['locked'] ?? false),
                // 사람에게 "왜 막혔는지"를 보여주기 위한 근거. 락이 아니어도 함께 내려간다
                // (남은 여력을 보고 판단하는 게 예방의 핵심이라 막힌 뒤에만 주면 늦다).
                //
                // 🚨 `basis`(락을 정하는 값)와 `reference`(참고 숫자)를 **일부러 분리**했다.
                //    ratio 모드에서 `available_krw`(보증금 여력)는 락 판정과 **무관**해서 서로 모순돼
                //    보인다 — "여력 0원인데 등록됨" / "락인데 여력 1천만" 이 둘 다 실제로 생긴다
                //    (게이트는 미수율을 보고, 여력은 입금액×비율−매입지급이라 분모·분자가 다르다).
                //    한 덩어리로 내려주면 board 가 반드시 나란히 렌더하고, 영업은 그걸 보고 오판한다.
                //    board 는 **`basis` 만 근거로 표시**할 것.
                'purchase_lock' => [
                    'locked' => (bool) ($v['locked'] ?? false),
                    'mode' => (string) ($v['mode'] ?? PurchaseRegistrationGate::MODE_OFF),
                    'basis' => self::purchaseLockBasis($v, $g),
                    'reference' => [
                        'unpaid_krw' => $g['unpaid_krw'] ?? null,
                        'vehicle_count' => $g['vehicle_count'] ?? null,   // 선적 전 진행중 대수
                        'unpaid_ratio_pct' => $g ? round($g['ratio'] * 100, 1) : null,
                        // 보증금 여력 = 입금액×비율 + 무담보 − 매입 지급. **락 판정 미사용**(표시 전용).
                        'available_krw' => $g['available_krw'] ?? null,
                        'unsecured_limit_krw' => $g['unsecured_limit_krw'] ?? null,
                        'unsecured_available_krw' => $g['unsecured_available_krw'] ?? null,
                    ],
                ],
            ];
        })->values();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    /**
     * 매입 락을 **실제로 결정하는 값 하나**만 골라 낸다 — board 표시의 단일 근거.
     *
     * `kind` 로 단위가 갈린다:
     *   - `ratio`         : current/limit = 미수율(%)  — 현재가 한계를 **초과**하면 락
     *   - `unsecured_krw` : current/limit = 원(무담보 잔액/한도) — 잔액이 **0 이하**면 락
     *   - `null`          : 판정 근거 없음(신규 바이어) 또는 락 토글 OFF
     */
    private static function purchaseLockBasis(?array $verdict, ?array $gauge): array
    {
        $mode = $verdict['mode'] ?? PurchaseRegistrationGate::MODE_OFF;

        if ($gauge === null || $mode === PurchaseRegistrationGate::MODE_OFF) {
            return ['kind' => null, 'current' => null, 'limit' => null];
        }

        if ($mode === PurchaseRegistrationGate::MODE_UNSECURED) {
            return [
                'kind' => 'unsecured_krw',
                'current' => (int) $gauge['unsecured_available_krw'],   // 남은 무담보
                'limit' => (int) $gauge['unsecured_limit_krw'],
            ];
        }

        return [
            'kind' => 'ratio',
            'current' => round($gauge['ratio'] * 100, 1),                        // 현재 미수율(%)
            'limit' => round(((float) ($verdict['threshold'] ?? 0)) * 100, 1),   // 임계(%)
        ];
    }

    /**
     * board 드로어용 — 선택 바이어 하위 컨사이니 목록.
     * IDOR — 요청 buyer_id 가 본인 소유일 때만 반환(타 영업 바이어 컨사이니 열람 차단).
     */
    public function consignees(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $buyerId = (int) $request->query('buyer_id', 0);

        $ownsBuyer = $buyerId > 0
            && Buyer::where('id', $buyerId)->where('salesman_id', $sid)->exists();

        $data = $ownsBuyer
            ? Consignee::query()->where('buyer_id', $buyerId)->where('is_active', true)
                ->orderBy('name')->get()
                ->map(fn (Consignee $c) => ['id' => $c->id, 'name' => $c->name])->values()
            : collect();

        return response()->json(['count' => $data->count(), 'data' => $data]);
    }

    public function finance(Request $request): JsonResponse
    {
        $sid = $this->salesmanId($request);
        $vehicles = $this->ownVehicles($sid)->get();

        return response()->json([
            // NULL(환율 미입력)은 합산서 제외(?? 0 금지 — cash_audit 교훈). fx_missing_count 로 별도 노출.
            // 결제대기(grace) 제외 — board 도 같은 DB·같은 채권 정의를 봄(jin 2026-07-06). scopeExcludeReceivableGrace
            //   단일 출처(=채권관리·대시보드와 동일 fresh 기준). 컬렉션($vehicles) 대신 fresh 쿼리로 스코프 적용.
            'unpaid_total_krw' => (int) $this->ownVehicles($sid)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->excludeReceivableGrace()
                ->sum('sale_unpaid_amount_krw_cache'),
            'purchase_unpaid_total' => (int) $vehicles->where('purchase_price', '>', 0)->sum->purchase_unpaid_amount,
            'fx_missing_count' => $vehicles->where('sale_price', '>', 0)
                ->filter(fn (Vehicle $v) => $v->sale_unpaid_amount_krw_cache === null)->count(),
            'settlement_pending_count' => Settlement::where('salesman_id', $sid)
                ->whereIn('settlement_status', ['pending', 'calculating', 'confirmed'])->count(),
        ]);
    }
}
