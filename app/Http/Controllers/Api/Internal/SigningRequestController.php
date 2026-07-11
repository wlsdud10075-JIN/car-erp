<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Documents\SigningSessionService;
use App\Services\SalesmanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * board 영업 포털 → 판매계약서 전자서명 세션 발급 (2026-07-10 풀회의, ERP 직접호스팅).
 * 권위 = docs/integration/board-portal-api.md §10.
 *
 * board 는 반환된 signed_url 을 바이어 1:1 채널(카톡/SNS)로 전달만 한다. 서명 페이지·서명본·증거메일은
 * 전부 ERP 가 호스팅·완결하므로 board 는 계약서 파일도 바이어 PII 도 받지 않는다.
 * 인증 = VerifyBoardReadHmac(POST 바디 canonical 포함). 본인격리 = salesman_id(IDOR).
 */
class SigningRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $salesman = SalesmanResolver::resolveActiveOrFail((string) $request->input('salesman_email', ''));

        $data = $request->validate([
            'vehicle_ids' => ['required', 'array', 'min:1'],
            'vehicle_ids.*' => ['integer'],
            'recipient_email' => ['nullable', 'email'],
        ]);

        $ids = collect($data['vehicle_ids'])->map(fn ($x) => (int) $x)->filter()->unique()->values();
        $byId = Vehicle::whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = $ids->map(fn ($id) => $byId->get($id))->filter()->values();
        abort_if($vehicles->isEmpty(), 404, 'Not found');

        // IDOR — 본인 차만(같은 담당자). export·동일 바이어/통화 검증은 서비스가 422.
        abort_unless($vehicles->every(fn (Vehicle $v) => $v->salesman_id === $salesman->id), 403, 'Forbidden');

        try {
            $result = app(SigningSessionService::class)->issue($vehicles, $data['recipient_email'] ?? null, null);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'unprocessable', 'message' => $e->validator->errors()->first()], 422);
        }

        $c = $result['contract'];

        return response()->json([
            'signed_url' => $result['url'],
            'contract_no' => $c->contract_no,
            'buyer' => ['id' => $c->buyer_id, 'name' => data_get($c->snapshot_data, 'buyer_name')],
            'currency' => $c->currency,
            'vehicle_count' => (int) data_get($c->snapshot_data, 'vehicle_count', $vehicles->count()),
            'status' => $c->status,
            'expires_at' => optional($c->token_expires_at)->toIso8601String(),
        ]);
    }
}
