<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KakaoRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 수집기 ↔ ERP 방 목록 (jin 11-10 «카톡방 열려 있으면 목록이 떠서 N개를 골라 읽게»).
 *   POST /api/kakao/rooms  {rooms: [창 제목…]}  → 지금 열려 있는 방을 보고(방 자동 등록 + 이 사용자에게 last_seen_at), 체크된 방 목록을 돌려준다
 *   GET  /api/kakao/rooms                        → 이 사용자가 「읽기」로 체크한 방 목록
 * 체크는 ERP 화면에서 한다. 수집기는 열린 방 ∩ 체크된 방만 읽는다.
 */
class KakaoRoomsController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $user = $request->attributes->get('kakao_user');
        $data = $request->validate(['rooms' => ['required', 'array', 'max:200'], 'rooms.*' => ['nullable', 'string', 'max:120']]);   // 빈 제목은 null 로 들어온다
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $data['rooms']), fn ($n) => KakaoRoom::isRoomTitle($n))));   // 저장 창·그림자 창은 방이 아니다(11-11)

        foreach ($names as $name) {
            $room = KakaoRoom::firstOrCreate(['name' => $name]);
            $existing = $room->users()->where('users.id', $user->id)->first();
            if ($existing) {
                $room->users()->updateExistingPivot($user->id, ['last_seen_at' => now()]);
            } else {
                $room->users()->attach($user->id, ['my_name' => '', 'selected' => false, 'last_seen_at' => now()]);
            }
        }

        return $this->selected($request);
    }

    /** 수집기 창의 체크 상태를 그대로 저장 — 목록에 있는 방은 체크, 이 사용자의 나머지 방은 해제. */
    public function select(Request $request): JsonResponse
    {
        $user = $request->attributes->get('kakao_user');
        $data = $request->validate(['selected' => ['present', 'array', 'max:200'], 'selected.*' => ['nullable', 'string', 'max:120']]);
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $data['selected']), fn ($n) => KakaoRoom::isRoomTitle($n)));

        foreach ($names as $name) {
            $room = KakaoRoom::firstOrCreate(['name' => $name]);
            if ($room->users()->where('users.id', $user->id)->exists()) {
                $room->users()->updateExistingPivot($user->id, ['selected' => true, 'last_seen_at' => now()]);
            } else {
                $room->users()->attach($user->id, ['my_name' => '', 'selected' => true, 'last_seen_at' => now()]);
            }
        }
        $ids = KakaoRoom::whereIn('name', $names)->pluck('id');
        $user->kakaoRooms()->wherePivot('selected', true)->whereNotIn('kakao_rooms.id', $ids)->get()
            ->each(fn ($r) => $user->kakaoRooms()->updateExistingPivot($r->id, ['selected' => false]));

        return $this->selected($request);
    }

    public function selected(Request $request): JsonResponse
    {
        $user = $request->attributes->get('kakao_user');
        $rooms = $user->kakaoRooms()->wherePivot('selected', true)->orderBy('name')->get();

        return response()->json([
            'selected' => $rooms->pluck('name')->values(),
            'need_setup' => $rooms->filter(fn ($r) => trim((string) $r->pivot->my_name) === '')->pluck('name')->values(),
            'message' => $rooms->isEmpty() ? 'ERP 「내 카톡 할 일」에서 읽을 방을 체크하세요.' : "읽을 방 {$rooms->count()}개.",
        ]);
    }
}
