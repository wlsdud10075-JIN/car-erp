<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

/**
 * ssancar.com 바이어 포털 — 차량 읽기 (2026-08-25, 요청서 `ERP_요청_포털_읽기_엔드포인트_v1.0`).
 *
 * 전량 pull + 차집합 구조다(v1.2 Q7). 증분·툼스톤·페이지네이션은 **일부러 안 만든다** —
 * 툼스톤 없는 증분은 삭제를 영원히 못 잡는다.
 *
 * 🔒 **명시 화이트리스트 SELECT.** `SELECT *` 뒤에 필터를 거는 형태로 바꾸지 말 것 —
 *    `nice_reg_owner_rrn`·`purchase_seller_account` 등은 `encrypted` cast 라
 *    **속성에 접근하는 것만으로 평문이 된다**. 나중에 칸이 늘 때 아무도 모르게 딸려 온다.
 *
 * 🚫 바이어에게 나가면 안 되는 것 — 매입가·비용·마진·정산 · 소유자 PII(주민번호·성명·주소) ·
 *    매입처 계좌 · 바이어 한도(`unsecured_limit_krw`·`lock_*_pct`). 가드 = `PortalVehicleApiTest`.
 */
class PortalVehicleController extends Controller
{
    /**
     * 이 응답이 담는 차량 컬럼 — 여기 없는 칸은 나가지 않는다.
     *
     * ⚠️ 이 배열이 곧 계약이다. 늘릴 때는 「바이어가 봐도 되는가」를 먼저 묻는다.
     */
    private const COLUMNS = [
        'id', 'buyer_id', 'vehicle_number', 'nice_reg_vin',
        'brand', 'model_type', 'year', 'mileage', 'color',
        'progress_status_cache',
        'purchase_date', 'sale_date', 'warehouse_out_date', 'shipping_date', 'eta_date',
        'vessel_name', 'container_number', 'port_of_loading', 'shipping_method', 'incoterms',
        'discharge_port_id', 'bl_document', 'bl_number', 'bl_issue_date',
        // 화물추적 링크 판정에 필요(포워딩사 템플릿 + 출항 D+1). 값 자체는 안 나간다.
        'forwarding_company_id',
        // C-1 미수 발행 (v1.12) — 아래 unpaid() 가 쓴다.
        // ⚠️ `exchange_rate` 는 **일부러 뺐다**. 바이어 통화로만 발행하고 원화 환산은 안 한다(Q11).
        'currency', 'sale_price', 'transport_fee', 'sale_other_costs',
        'commission', 'auto_loading', 'tax_dc', 'savings_used',
    ];

    // 🚫 여기 없는 것 중 **일부러 뺀 것** — 요청서 B-2 가 명시적으로 거부했다.
    //    `sale_unpaid_amount_krw_cache` : 원화 캐시. 발행은 **바이어 통화**로 한다(Q11) — 환산은 안 한다.
    //    ⚠️ `sale_price`·`transport_fee` 는 v1.11 에서 B-2 가 거부했다가 C-1 로 **되돌아왔다**.
    //       거부 사유였던 *"미러에 있으면 사이트가 계산하고 싶어진다"* 는 그대로 유효하므로,
    //       평탄한 칸이 아니라 `unpaid_components` **안에** 넣고 «표시 전용» 계약을 함께 건다.
    //       재계산 방지의 실질 가드는 아래 **닫힘 항등식**이다.
    //    소유자 PII · 원가 · 마진 · 바이어 한도 : 애초에 대상이 아니다.

    public function vehicles(): JsonResponse
    {
        $rows = Vehicle::query()
            // 🚨 바이어 미정(투기 매입)은 어느 바이어에게도 발행하지 않는다 — 이게 IDOR 경계다.
            ->whereNotNull('buyer_id')
            // 🚨 `finalPayments`·`receivableHistories` 는 `sale_unpaid_amount` 가 쓰는 관계다.
            //    빼면 261행 × 2 쿼리로 터진다. 🚫 컬럼 제한(`:id,amount`)을 걸지 말 것 —
            //    금액 계산 쿼리에서 컬럼을 제한하면 조용히 값이 틀어진다(MEMORY 「살아있는 함정」).
            ->with([
                'dischargePort:id,name',
                'forwardingCompany:id,name,tracking_url_template',
                'finalPayments',
                'receivableHistories',
            ])
            ->select(self::COLUMNS)
            ->orderBy('id')
            ->get();

        return response()->json([
            // v1.4 확정 스펙 — ISO8601 + 오프셋 필수(오프셋 없으면 파서가 로컬로 읽어 9시간 어긋난다).
            'generated_at' => now()->toIso8601String(),
            // 🚨 **서버별 설정이다.** 하드코딩하면 두 회사가 같은 값을 반환하고,
            //    사이트의 전량 pull + 차집합이 «다른 회사 행 = 이 회사 전량 삭제» 로 읽는다.
            //    Phase 1 은 heymanerp 만 호출해서 그 사고가 안 보인다 — 값이 늘어날 때 터진다.
            'source' => (string) config('services.ssancar_portal.source'),
            'count' => $rows->count(),
            // v1.4 의 안전핀 — false 면 사이트가 차집합 삭제를 보류한다(부분 응답으로 전량 삭제 방지).
            'complete' => true,
            'data' => $rows->map(fn (Vehicle $v) => $this->row($v))->all(),
        ]);
    }

    /**
     * 차량 한 대 → 포털이 읽는 형태.
     *
     * 🔑 계산은 전부 ERP 단일 출처를 그대로 읽는다. 사이트가 재계산하면 갈린다 —
     *    이번 협의에서 같은 형태로 다섯 번 어긋났다(정본 §3-5).
     */
    private function row(Vehicle $v): array
    {
        return [
            'id' => $v->id,
            'buyer_id' => $v->buyer_id,
            'vehicle_number' => $v->vehicle_number,
            'vin' => $v->nice_reg_vin,
            'brand' => $v->brand,
            'model_type' => $v->model_type,
            'year' => $v->year,
            'mileage' => $v->mileage,
            'color' => $v->color,

            // ★원문 문자열★ — 사이트가 모르는 값은 폴백 + 로그로 처리한다(닫힌 enum 금지, v1.3).
            'progress_status_cache' => $v->progress_status_cache,
            // 진행 단계 평가에 쓰는 축. 사이트가 날짜로 재판정하면 갈린다(v1.2 §7-B 2).
            'sailing_phase' => $v->sailing_phase,
            // 🚨 `$v->departed()` 는 쿼리 스코프라 Builder 가 돌아온다 — 인스턴스 판정은 isDeparted().
            'departed' => $v->isDeparted(),

            'purchase_date' => $this->date($v->purchase_date),
            'sale_date' => $this->date($v->sale_date),
            'warehouse_out_date' => $this->date($v->warehouse_out_date),
            'shipping_date' => $this->date($v->shipping_date),
            'eta_date' => $this->date($v->eta_date),

            'vessel_name' => $v->vessel_name,
            'container_number' => $v->container_number,
            'port_of_loading' => $v->port_of_loading,
            'shipping_method' => $v->shipping_method,
            'incoterms' => $v->incoterms,
            // id 가 아니라 이름으로 푼다 — 사이트에 ports 사본을 두면 동기화 대상이 하나 늘고,
            // 그게 갈리면 도착지가 조용히 틀린다(요청서 B-1).
            'discharge_port' => $v->dischargePort?->name,

            // 🔑 번호와 문서는 뜻이 다르다 — 번호만 있고 문서가 없는 차가 실측 14대.
            //    「발급됨」 판정은 **문서**로 건다(v1.2 §7-B 3).
            'has_bl_document' => filled($v->bl_document),
            'bl_number' => $v->bl_number,
            'bl_issue_date' => $this->date($v->bl_issue_date),

            // 포워딩사 화물추적 — 열 수 없으면 null. **사이트는 열림 조건을 판정하지 않는다.**
            'tracking_url' => $v->tracking_url,
        ] + $this->unpaid($v);
    }

    /**
     * C-1 미수 발행 (v1.12) — **차량 단위 · 바이어 통화 · 음수 분리**.
     *
     * ═══ 닫힘 항등식 (이게 계약의 본체다) ═══════════════════════════════
     *   sale_price + transport_fee + other_charges − paid − savings_used − written_off
     *       ≡  unpaid_amount − overpaid_amount            (오차 < 통화 1단위)
     *
     * 🔑 **`components` 를 「3항」이나 「4항」으로 적으면 닫히지 않는다.**
     *    ERP 미수 공식(`Vehicle::getSaleUnpaidAmountAttribute`)의 항은 실제로 8개다 —
     *    `sale_other_costs`·`commission`·`auto_loading`·`tax_dc`·회수이력까지 들어간다.
     *    ssancar v1.11 §17-1 이 `savings_used` 하나를 되돌려 4항을 제안했는데, 그래도 짧다.
     *    ⇒ 항을 세지 말고 **닫히게** 만든다. 아래 두 값은 **파생**이라 공식 사본이 아니다:
     *      other_charges = sale_total_amount − sale_price − transport_fee
     *      paid          = sale_total_amount − savings_used − written_off − 미수(스냅 포함)
     *    ERP 공식이 바뀌어도 항등식은 자동으로 따라온다(SKILLS §45 — 공식 복제 금지).
     *
     * ⚠️ **`written_off`(손실처리)는 「바이어가 낸 돈」이 아니다 — 회사가 포기한 채권이다.**
     *    그래서 `paid` 에 섞지 않고 **별도 줄**로 뽑는다(jin 2026-08-25 결정).
     *    섞으면 «당신이 낸 돈» 이 실제보다 커져, 바이어가 자기 송금 기록과 대조하다 어긋난다.
     *    ⚠️ 그래도 **판매계약서 Balance 와는 다를 수 있다** — 계약서 Received 는 회수이력을
     *    일부러 제외한다(SKILLS §29, jin 2026-07-29). 화면 문구가 출처를 밝혀야 한다.
     *
     * 🔑 `paid` = 확정 잔금 + 회수이력(`cash`·`offset`·`other`) — **실제로 들어온 돈**.
     *    `written_off` 도 파생으로 뺀 게 아니라 직접 합산하지만, `paid` 를 그만큼 줄여서
     *    **항등식은 그대로 닫힌다**(아래 계산식 참조).
     *
     * 🚫 원화 환산을 하지 않는다(Q11) — 다중통화 바이어가 실재하고, 환산은 시점 문제가 붙는다.
     */
    private function unpaid(Vehicle $v): array
    {
        // 판매 전(매입만 있는 차)은 미수라는 개념이 없다. 0 이 아니라 **null** 로 보낸다 —
        // 0 을 보내면 사이트가 「완납」으로 그린다.
        if ((float) $v->sale_price <= 0) {
            return [
                'currency' => null,
                'unpaid_amount' => null,
                'overpaid_amount' => null,
                'unpaid_components' => null,
                // C-3 — 레벨3 승급용. 판매가 없으면 「온전히 결제한 차」가 아니다.
                'fully_paid' => false,
            ];
        }

        $total = (float) $v->sale_total_amount;      // 단일 출처
        $raw = (float) $v->sale_unpaid_amount;       // 단일 출처(0<x<1 완납 스냅 포함)
        $savings = (float) ($v->savings_used ?? 0);
        // 🚫 「바이어가 낸 돈」이 아니라 회사가 포기한 채권 — paid 에 섞지 않는다.
        $writtenOff = (float) $v->receivableHistories->where('method', 'write_off')->sum('amount');

        return [
            'currency' => $v->currency,
            // v1.6 Q13-3 — 음수를 섞으면 바이어 합계에서 남의 차 미수를 상쇄한다. 눌러서 보낸다.
            'unpaid_amount' => $this->money(max(0.0, $raw)),
            // 원본 음수(과입금)는 **따로**. 🚫 unpaid 와 더하지 말 것.
            'overpaid_amount' => $this->money(max(0.0, -$raw)),
            'unpaid_components' => [
                'sale_price' => $this->money((float) $v->sale_price),
                'transport_fee' => $this->money((float) $v->transport_fee),
                // 부대비용 묶음 = 기타비용 + Commission + Auto loading − TAX D/C.
                // 음수일 수 있다(TAX D/C 가 크면).
                'other_charges' => $this->money($total - (float) $v->sale_price - (float) $v->transport_fee),
                // 실제로 들어온 돈 = 확정 잔금 + 회수이력(cash·offset·other).
                'paid' => $this->money($total - $savings - $writtenOff - $raw),
                'savings_used' => $this->money($savings),
                // 손실처리(회수 포기). 바이어가 낸 돈이 아니므로 paid 와 절대 합치지 말 것.
                'written_off' => $this->money($writtenOff),
            ],
            // C-3 — ssancar 레벨2→3 자동 승급(v1.11 §2-3). 「판매완료」의 코드상 정의 그대로다.
            // 🚨 `progress_status_cache === '판매완료'` 로 세면 틀린다 — 완납한 차가 선적되면
            //    상태가 선적중·거래완료로 올라가 그 문자열에서 빠진다(v4 cascade). 미수로 판정할 것.
            'fully_paid' => $raw <= 0,
        ];
    }

    /** 통화 금액 — 소수 2자리. KRW 는 정수라 영향 없고, 외화는 잔차를 접는다. */
    private function money(float $amount): float
    {
        return round($amount, 2);
    }

    private function date($value): ?string
    {
        return $value?->toDateString();
    }
}
