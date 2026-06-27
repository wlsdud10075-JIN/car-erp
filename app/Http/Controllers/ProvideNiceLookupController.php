<?php

namespace App\Http\Controllers;

use App\Services\NiceDirectClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NICE 차량정보 게이트웨이 — POST /provide/api/nice-lookup/.
 *
 * 기존 Django(ssancar-erp) 의 동일 엔드포인트를 대체. heymanerp 등 다른 박스는 이 경로를
 * 그대로 호출(NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/)하므로,
 * 입출력(요청 body: vehicle_number/owner_name, 응답: {success,message,data})을 Django 와
 * 100% 동일하게 유지한다.  ⚠️ 첫 컷오버는 Django 동작 복제 — 토큰 검증 등 추가 금지(파리티 우선).
 */
class ProvideNiceLookupController extends Controller
{
    public function __invoke(Request $request, NiceDirectClient $client): JsonResponse
    {
        $body = $request->json()->all();
        $vehicleNumber = (string) ($body['vehicle_number'] ?? '');
        $ownerName = (string) ($body['owner_name'] ?? '');

        $result = $client->lookup($vehicleNumber, $ownerName);
        $status = (int) ($result['status'] ?? ($result['success'] ? 200 : 400));
        unset($result['status']);

        return response()->json($result, $status, [], JSON_UNESCAPED_UNICODE);
    }
}
