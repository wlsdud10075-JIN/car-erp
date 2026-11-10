<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KakaoRoom;
use App\Services\Kakao\KakaoIngestService;
use App\Services\Kakao\KakaoLimitException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/kakao/ingest — 수집기가 복사한 카톡 본문 1회분. 인증 = VerifyKakaoCollectorToken(Bearer kck_…).
 * 응답은 수집기가 그대로 보여 줄 한국어 메시지 + 숫자. 방은 ERP 페이지에서 **미리 등록**돼 있어야 한다(없으면 404 — 빈 구성으로
 * 자동 생성하면 to enum 이 「없음」뿐이라 분류가 조용히 무의미해진다).
 */
class KakaoIngestController extends Controller
{
    public const MAX_TEXT_BYTES = 1_000_000;   // 바쁜 방 7일 ≈ 150KB. 수집기 버그(20,000줄) 방어

    public function __invoke(Request $request, KakaoIngestService $service): JsonResponse
    {
        $user = $request->attributes->get('kakao_user');
        $data = $request->validate([
            'room' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT_BYTES],
            'fallback_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'text.required' => '복사한 본문이 비어 있습니다.',
            'text.max' => '본문이 너무 큽니다(1MB). 읽는 범위를 좁히세요.',
        ]);

        $room = KakaoRoom::where('name', $data['room'])->first();
        if (! $room) {
            return response()->json(['message' => "방 「{$data['room']}」 이 ERP 에 등록돼 있지 않습니다. 「내 카톡 할 일」에서 방을 먼저 등록하세요."], 404);
        }
        if (! $room->users()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => "방 「{$room->name}」 을 구독하지 않았습니다. 「내 카톡 할 일」에서 방을 체크하고 내 표시명을 적으세요."], 403);
        }

        try {
            $read = $service->ingest($user, $room, $data['text'], $data['fallback_date'] ?? null);
        } catch (KakaoLimitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $needsReview = $read->status === 'failed';
        $message = match (true) {
            $needsReview => "메시지 {$read->new_count}건은 저장했지만 분류에 실패했습니다. 화면의 「확인 필요」에서 보세요.",
            $read->new_count === 0 => "새 메시지가 없습니다(받은 {$read->total}건 전부 이미 있음).",
            default => "새 메시지 {$read->new_count}건 분류 완료(받은 {$read->total}건).",
        };

        return response()->json([
            'read_id' => $read->id,
            'total' => $read->total,
            'new_count' => $read->new_count,
            'status' => $read->status,
            'needs_review' => $needsReview,
            'cost_krw' => (float) $read->cost_krw,
            'message' => $message,
        ]);
    }
}
