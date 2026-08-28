<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use Illuminate\Http\JsonResponse;

/**
 * ssancar.com 바이어 포털 — 바이어 명부 읽기 (2026-08-28, 요청서 `전달패킷_ssancar→ERP_2026-08-28`).
 *
 * 차량 엔드포인트(`PortalVehicleController`)와 **인증·봉투·throttle 을 그대로 공유**한다.
 * 이유는 하나다 — 차량 응답이 바이어에 대해 주는 것은 `buyer_id` 뿐이라 사이트가 이름을 못 그린다
 * (관리자 화면에 「ERP 바이어 #10 99대」처럼 번호만 떴다).
 *
 * 전량 pull + 차집합 구조다(v1.2 Q7). 증분·툼스톤·페이지네이션은 **일부러 안 만든다**.
 *
 * 🔒 **명시 화이트리스트 SELECT.** `SELECT *` 뒤에 필터를 거는 형태로 바꾸지 말 것 —
 *    나중에 칸이 늘 때 아무도 모르게 딸려 온다.
 *
 * 🚫 사이트에 나가면 안 되는 것 — **내부 신용 판단** 전부:
 *    `unsecured_limit_krw` · `lock_shipping_entry_pct` · `lock_purchase_registration_pct` ·
 *    `memo` · `passport_id`(**평문이다**) · `salesman_id`·`is_inherited` 계열.
 *    v1.16 §22-4 에서 정책으로 닫혔고, ssancar 미러 스키마에도 자리가 없다.
 *
 * ✅ **예외 하나 — `salesman_name`(2026-08-28 jin 승인).** 3차 패킷 요청.
 *    사이트가 계정 비밀번호를 담당자별로 갈라 전달해야 하는데, 그쪽의 유일한 소스
 *    (`ssc_buyers`)가 **2026-06-22 스냅샷이라 두 달치 재배정이 빠져 있다**(그쪽 실측).
 *    쓰는 자리는 **관리자 화면**이고 바이어 화면에는 안 나간다.
 *    🚫 **`salesman_id` 는 계속 닫힌다** — id 를 주면 그쪽이 id→이름 사전을 따로 들게 되고,
 *       그 사전이 낡는 순간 지금 고치려는 사고가 그대로 재발한다. **이름만** 보낸다.
 *    🚫 연락처는 요청도 안 왔고 보내지 않는다.
 *
 * 🚫 **`email_buyer_count` 같은 파생 집계는 넣지 않는다**(2026-08-28 2차 회신에서 빠졌다).
 *    사이트가 **자동 매칭을 안 하고**(사람이 승인한다) 화면 표시용 개수는 자기 미러에서 센다.
 *    분모가 우리에게 있는 파생값을 보내면 값이 어긋나는 날 **어느 쪽이 맞는지 물어볼 데가 없다**.
 */
class PortalBuyerController extends Controller
{
    /**
     * 이 응답이 담는 바이어 컬럼 — 여기 없는 칸은 나가지 않는다.
     *
     * ⚠️ 이 배열이 곧 계약이다. 늘릴 때는 「사이트가 봐도 되는가」를 먼저 묻는다.
     * `deleted_at` 은 값으로 나가지 않는다 — `erp_deleted` 불리언을 만들고 버린다.
     */
    private const COLUMNS = [
        'id', 'name', 'country_id', 'contact_email', 'is_active', 'deleted_at',
        // 🚨 `salesman_id` 는 **관계 매칭용이지 발행 대상이 아니다**(`country_id` 와 같은 취급).
        //    `select()` 가 목록을 통째로 갈아치우므로 부모 FK 를 안 실으면 `belongsTo` 가
        //    **전 행 조용히 null** 이 된다 — 예외도 경고도 없다. `row()` 는 이 값을 안 내보낸다.
        'salesman_id',
    ];

    public function buyers(): JsonResponse
    {
        $rows = Buyer::withTrashed()
            /*
            | 🚨 **소프트삭제·비활성 바이어도 행을 보낸다. 이 줄을 되돌리지 말 것.**
            |
            | 차량 엔드포인트는 `buyers` 를 **조인하지 않는다**. 그래서 소프트삭제된 바이어의
            | 차량은 지금도 그대로 발행된다. 여기서 그 바이어를 빼면 사이트에
            | **미러에 없는 `buyer_id` 를 가리키는 차량**이 남는다.
            |
            | 나중에 누가 「삭제된 사람을 왜 보내지」 하고 `whereNull('deleted_at')` 을 한 줄
            | 넣는 순간 그렇게 된다. **에러는 안 난다** — 관리자 화면에 이름 없는 번호가
            | 조용히 늘어날 뿐이다(2026-08 에 실제로 그 증상으로 이 요청이 왔다).
            | 가드 = `PortalBuyerApiTest::test_soft_deleted_and_inactive_buyers_still_ship`.
            */
            // 🚫 `salesman` 에 `withTrashed` 를 붙이지 말 것 — 소프트삭제된 담당자를 보내면
            //    포털이 **ERP 화면보다 더 많이** 보여준다(ERP 바이어 목록은 그때 「미지정」을 그린다).
            //    컬럼 제한(`:id,name`)은 여기서 안전하다 — 금액을 계산하지 않고 이름만 읽는다.
            ->with(['country:id,name', 'salesman:id,name'])
            ->select(self::COLUMNS)
            /*
            | 🔑 **대조용 숫자다.** 이 카운트의 집합이 차량 엔드포인트가 발행하는 집합과
            |    같아야 한다 — `Vehicle` 은 SoftDeletes 라 서브쿼리에 `deleted_at IS NULL` 이
            |    자동으로 붙고, 차량 엔드포인트는 `buyer_id IS NOT NULL` + 삭제 제외다. 일치한다.
            |    사이트는 이 값을 저장만 하고 **화면 숫자는 자기 미러에서 센다** —
            |    어긋나면 그쪽 적재기가 행을 잃었다는 신호로 쓴다(2차 회신 §3).
            | ⚠️ **`select()` 뒤에 와야 한다** — `select()` 는 select 목록을 통째로 갈아치우므로
            |    앞에 두면 withCount 서브쿼리가 지워져 **조용히 항상 0** 이 된다(차량 쪽 실측).
            */
            ->withCount('vehicles')
            ->orderBy('id')
            ->get();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            // 🚨 **서버별 설정이다.** 하드코딩하면 두 회사가 같은 값을 반환하고,
            //    사이트의 전량 pull + 차집합이 «다른 회사 행 = 이 회사 전량 삭제» 로 읽는다.
            'source' => (string) config('services.ssancar_portal.source'),
            // 조회한 컬렉션에서 센다 — 별도 count 쿼리를 쓰면 직렬화 결과와 어긋나
            // 사이트의 `count≠행수` 게이트를 스스로 밟는다.
            'count' => $rows->count(),
            // 안전핀 — false 면 사이트가 차집합 삭제를 보류한다. 쿼리가 무한 단발이라 항상 true.
            'complete' => true,
            'data' => $rows->map(fn (Buyer $b) => $this->row($b))->all(),
        ]);
    }

    /** 바이어 한 명 → 포털이 읽는 형태. */
    private function row(Buyer $b): array
    {
        return [
            // 차량 응답의 `buyer_id` 와 **같은 축**이다. 「응답 안에서 그 행의 PK 는 항상 `id`」가
            // 두 엔드포인트의 공통 규칙이다(2차 회신 §1) — 이름을 바꾸지 말 것.
            'id' => $b->id,
            'name' => $b->name,
            // 🔑 ERP 는 `buyers.name` 을 저장 시 trim 하지 않는다(trim 은 Vehicle 에만 있다).
            //    실측에 `[R.S.H ]` 처럼 끝 공백이 있어 사이트 매칭이 어긋난다 — 여기서 만들어 준다.
            //    🚫 원본 `name` 을 대신 덮어쓰지 말 것. 사람이 보는 값과 매칭 키는 따로 둔다.
            //    (사이트가 자기 쪽에서 TRIM 하면 **매칭 키를 사이트가 만드는** 셈이 된다.)
            'name_trimmed' => trim((string) $b->name),
            // 정본 이메일 칸은 이것 하나다(Invoice E10 · 판매계약서 · 전자서명 수신자가 같은 값을 쓴다).
            // ⚠️ 검증이 없어 공백·이름 섞인 값이 실재한다 — 사이트는 완전일치 조인이라 그 행은 후보가 안 뜬다.
            'contact_email' => $b->contact_email,
            // id 가 아니라 이름으로 푼다 — 사이트에 countries 사본을 두면 동기화 대상이 하나 늘고,
            // 그게 갈리면 국가가 조용히 틀린다(차량 `discharge_port` 와 같은 판단).
            'country' => $b->country?->name,
            'is_active' => (bool) $b->is_active,
            // 영업담당자 **이름**(2026-08-28 jin 승인 — 3차 패킷). 관리자 화면 전용.
            // ⚠️ `null` 은 두 가지를 뜻한다 — 「미지정」**이거나** 「담당자가 소프트삭제됨」.
            //    보통 퇴사는 승계로 재배정되므로(`SalesmanHandoverService` 가 `buyers.salesman_id` 를
            //    새 담당자로 옮긴다) 후자는 드물다. 어느 쪽이든 ERP 바이어 화면과 같은 값이다.
            // 🚫 빈 문자열로 눕히지 말 것 — 「없음」과 「모름」이 섞인다(상대 요청).
            // 🧭 이름이 `salesman_name` 인 것은 **외부 계약**이라 그렇다. 이 파일의 관용
            //    (`country` 처럼 접미사 없음)과 다르지만 「정리」하지 말 것 — 상대가 그 키를 읽는다.
            'salesman_name' => $b->salesman?->name,
            'vehicle_count' => (int) ($b->vehicles_count ?? 0),
            // 🔑 행을 빼지 않고 **플래그로** 준다 — 위 withTrashed() 주석 참조.
            'erp_deleted' => $b->trashed(),
        ];
    }
}
