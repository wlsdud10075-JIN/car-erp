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
    ];

    // 🚫 여기 없는 것 중 **일부러 뺀 것** — 요청서 B-2 가 명시적으로 거부했다.
    //    `sale_price`·`transport_fee` : 미수를 사이트가 계산하게 만드는 재료다(§3 — 계산은 ERP 몫).
    //    `sale_unpaid_amount_krw_cache` : 원화 캐시. 발행은 **바이어 통화**로 해야 한다(Q11) → C-1 확정 후.
    //    소유자 PII · 원가 · 마진 · 바이어 한도 : 애초에 대상이 아니다.

    public function vehicles(): JsonResponse
    {
        $rows = Vehicle::query()
            // 🚨 바이어 미정(투기 매입)은 어느 바이어에게도 발행하지 않는다 — 이게 IDOR 경계다.
            ->whereNotNull('buyer_id')
            ->with(['dischargePort:id,name', 'forwardingCompany:id,name,tracking_url_template'])
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
        ];
    }

    private function date($value): ?string
    {
        return $value?->toDateString();
    }
}
