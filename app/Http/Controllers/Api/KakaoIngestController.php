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
 * 응답은 수집기가 그대로 보여 줄 한국어 메시지 + 숫자. 방은 처음 보낼 때 **자동 생성**되고 메시지만 쌓인다(편 구성 없음 → 분류 보류).
 * 사람은 ERP 화면에서 그 방의 「나」(와 같은 회사 사람)만 고르면 되고, 그 순간 쌓인 메시지가 분류된다 — 빈 구성으로 분류를 돌리지는 않는다.
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

        // 방은 수집기가 처음 보낼 때 자동으로 생긴다 — 사람이 적는 건 ERP 화면에서 「나」 한 번 (jin 11-10 «방이 수십개면 다 적어야해?»)
        $room = KakaoRoom::firstOrCreate(['name' => trim($data['room'])]);
        if (! $room->users()->where('users.id', $user->id)->exists()) {
            $room->users()->attach($user->id, ['my_name' => '', 'selected' => true, 'last_seen_at' => now()]);   // 내 표시명은 화면에서 고른다
        } else {
            $room->users()->updateExistingPivot($user->id, ['selected' => true, 'last_seen_at' => now()]);   // 보냈다 = 읽기로 체크한 방
        }

        try {
            $read = $service->ingest($user, $room, $data['text'], $data['fallback_date'] ?? null);
        } catch (KakaoLimitException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $needsReview = $read->status === 'failed';
        $message = match (true) {
            $read->status === 'pending_setup' => "메시지 {$read->new_count}건 저장. 처음 읽은 방입니다 — ERP 「내 카톡 할 일」에서 이 방의 「나」를 한 번 골라 주면 분류가 시작됩니다.",
            $needsReview => "메시지 {$read->new_count}건은 저장했지만 분류에 실패했습니다({$read->pendingAfter}건 남음). 화면이 「이어서 분류」로 다시 시도합니다.",
            $read->status === 'partial' => "새 메시지 {$read->new_count}건 중 {$read->pendingAfter}건은 시간이 모자라 아직 분류 전 — 화면이 이어서 분류합니다.",
            $read->new_count === 0 => "새 메시지가 없습니다(받은 {$read->total}건 전부 이미 있음).",
            default => "새 메시지 {$read->new_count}건 분류 완료(받은 {$read->total}건).",
        };
        if ($read->leftover > 0) {   // 읽기 1회 상한 — 나머지는 [지금 읽기] 한 번 더(이미 저장된 건은 건너뛴다)
            $message .= " 읽기 1회 상한에 걸려 {$read->leftover}건은 아직 안 담았습니다 — [지금 읽기]를 한 번 더 누르세요.";
        }

        return response()->json([
            'read_id' => $read->id,
            'total' => $read->total,
            'new_count' => $read->new_count,
            'leftover' => $read->leftover,
            'pending_after' => $read->pendingAfter,
            'status' => $read->status,
            'needs_review' => $needsReview,
            'cost_krw' => (float) $read->cost_krw,
            'message' => $message,
        ]);
    }
}
