<?php

namespace App\Http\Controllers;

use App\Services\NiceDirectClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * NICE 차량정보 게이트웨이 — POST /provide/api/nice-lookup/.
 *
 * 기존 Django(ssancar-erp) 의 동일 엔드포인트를 대체. heymanerp 등 다른 박스는 이 경로를
 * 그대로 호출(NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/)하므로,
 * 입출력(요청 body: vehicle_number/owner_name, 응답: {success,message,data})을 Django 와
 * 100% 동일하게 유지한다.
 *
 * 🔐 토큰 검사(2026-11-22, jin 11-19 «그래 하자») — 구: 인증 없음(Django 파리티)이라 인터넷 누구나 차량번호+소유자명으로
 * NICE 유료 조회·소유자 정보를 받을 수 있었다. 3사 ERP 는 원래부터 `X-SSANCAR-API-KEY` 를 보낸다(NiceApiService) → 그 헤더를 검사한다.
 * 받아들이는 값 = `services.nice.gateway_tokens`(쉼표 목록) 또는 이 박스의 `provide_token`. 하나도 설정돼 있지 않으면 **닫는다**(fail-closed).
 * IP별 분당 호출 상한은 라우트의 throttle 미들웨어. board 는 이 주소를 부르지 않는다(11-19 board 실측).
 *
 * 동시 조회 상한(2026-07-26): NICE 조회 1건이 이 박스(54.116.7.83, ERP+board 워커 공유) 워커를
 * 최대 90초 붙잡는다. 3사 ERP·board 조회가 모두 이 컨트롤러 한 곳을 지나므로, 여기에 슬롯 락
 * 세마포어를 걸어 **전역 동시 상한**을 둔다. 다 차면 90초 붙잡지 않고 즉시 429 로 워커를 반납.
 * 락 백엔드 = database(cache_locks) → 워커 간 원자적. TTL 로 크래시 워커 슬롯 자동 해제.
 */
class ProvideNiceLookupController extends Controller
{
    public function __invoke(Request $request, NiceDirectClient $client): JsonResponse
    {
        if (! $this->tokenAccepted((string) $request->header('X-SSANCAR-API-KEY', ''))) {
            return response()->json(['success' => false, 'message' => '인증되지 않은 요청입니다.'], 401, [], JSON_UNESCAPED_UNICODE);
        }

        $max = max(1, (int) config('services.nice.max_concurrent', 4));

        $lock = null;
        for ($i = 1; $i <= $max; $i++) {
            $candidate = Cache::lock("nice-lookup-slot-{$i}", 120);   // TTL 120s > 조회 최대 90s
            if ($candidate->get()) {
                $lock = $candidate;
                break;
            }
        }

        if ($lock === null) {
            return response()->json([
                'success' => false,
                'message' => '동시 조회가 많아 잠시 후 다시 시도해 주세요.',
            ], 429, [], JSON_UNESCAPED_UNICODE);
        }

        try {
            $body = $request->json()->all();
            $vehicleNumber = (string) ($body['vehicle_number'] ?? '');
            $ownerName = (string) ($body['owner_name'] ?? '');

            $result = $client->lookup($vehicleNumber, $ownerName);
            $status = (int) ($result['status'] ?? ($result['success'] ? 200 : 400));
            unset($result['status']);

            return response()->json($result, $status, [], JSON_UNESCAPED_UNICODE);
        } finally {
            $lock->release();
        }
    }

    /** 보낸 토큰이 받아들이는 목록에 있나 — 길이·시간 차로 새지 않게 hash_equals. 설정이 비어 있으면 아무도 못 지난다. */
    private function tokenAccepted(string $given): bool
    {
        $accepted = array_filter(array_map('trim', explode(',', (string) config('services.nice.gateway_tokens', ''))))
            ?: array_filter([trim((string) config('services.nice.provide_token', ''))]);
        if ($given === '' || $accepted === []) {
            return false;
        }
        foreach ($accepted as $token) {
            if (hash_equals($token, $given)) {
                return true;
            }
        }

        return false;
    }
}
