<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\SignedContract;
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

    /**
     * §10-2 — board 폴링용 서명 상태 조회 (그 묶음 차량 set 의 현 세션).
     *   status: none(미발송) / pending / viewed / signed. 3전이 타임스탬프만.
     *   ⚠️ 서명본 파일·서명이미지·바이어 PII 미포함(상태 메타만). board 는 이걸로 칩 갱신.
     */
    public function status(Request $request): JsonResponse
    {
        $salesman = SalesmanResolver::resolveActiveOrFail((string) $request->query('salesman_email', ''));

        $ids = collect(explode(',', (string) $request->query('vehicle_ids', '')))
            ->map(fn ($x) => (int) trim($x))->filter()->unique()->values();
        abort_if($ids->isEmpty(), 400, 'No vehicles');

        $vehicles = Vehicle::whereIn('id', $ids)->get();
        abort_if($vehicles->isEmpty(), 404, 'Not found');
        // IDOR — 본인 차만
        abort_unless($vehicles->every(fn (Vehicle $v) => $v->salesman_id === $salesman->id), 403, 'Forbidden');

        // 그 set 의 현 세션(signed 우선, 없으면 active). revoked 제외.
        $sessions = SignedContract::where('buyer_id', $vehicles->first()->buyer_id)
            ->whereIn('status', [SignedContract::STATUS_SIGNED, SignedContract::STATUS_VIEWED, SignedContract::STATUS_PENDING])
            ->latest('id')->get();
        $c = SignedContract::pickForSet($sessions, $ids->all());

        if (! $c) {
            return response()->json(['status' => 'none']);
        }

        return response()->json([
            'status' => $c->status,
            'contract_no' => $c->contract_no,
            'vehicle_count' => (int) data_get($c->snapshot_data, 'vehicle_count', $vehicles->count()),
            'sent_at' => optional($c->sent_at)->toIso8601String(),
            'viewed_at' => optional($c->viewed_at)->toIso8601String(),
            'signed_at' => optional($c->signed_at)->toIso8601String(),
        ]);
    }
}
