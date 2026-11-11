<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 「내 카톡 할 일」 한 사람 분 — 구독한 방의 최근 N일 메시지를 보는 사람 시점으로 방향·묶음 계산.
 * 방향은 SQL 로 못 거르므로 후보를 먼저 좁힌다: 요청·질문 + 분류 실패(확인 필요) + 내가 상태를 건드린 것. 첨부는 제외.
 * ⚠️ `#[Computed]` 로 두지 말 것 — wire:poll 이 매번 재계산한다(§8 #96-C). 페이지는 render 때 한 번 부른다.
 */
class KakaoTodoBoard
{
    /**
     * @param  bool  $allDone  「완료된 것 보기」 — 꺼져 있으면 「오늘 끝난 것」은 오늘 닫힌 것만(jin 11-11: 7일 동안 164건이 쌓여 있었다)
     */
    public static function forUser(User $user, CarbonInterface $now, bool $allDone = false): array
    {
        $picked = KakaoTopics::of($user);   // 담당 분야(null = 안 고름 → 전부 내 일)
        $rooms = $user->kakaoRooms()->wherePivot('selected', true)->orderBy('name')->get();   // 「읽기」로 체크한 방만
        $roomIds = $rooms->pluck('id')->all();
        $myName = $rooms->mapWithKeys(fn ($r) => [$r->id => (string) $r->pivot->my_name])->all();
        $sides = $rooms->mapWithKeys(fn ($r) => [$r->id => $r->sideMembers()])->all();
        $people = $rooms->mapWithKeys(fn ($r) => [$r->id => $r->personRoles()])->all();   // 보낸이 → 부서. ⚠️ 메시지의 room 은 부분 select(id,name)라 roles 가 없다(§8 #83) — 여기서 넘긴다

        $buckets = array_fill_keys(KakaoBuckets::ORDER, []);
        if ($roomIds !== []) {
            $since = $now->copy()->subDays(Setting::kakaoWindowDays())->startOfDay();
            $messages = KakaoMessage::whereIn('room_id', $roomIds)
                ->where('sent_at', '>=', $since)
                ->where(fn ($q) => $q->where('type', '!=', '첨부')->orWhereNull('type'))
                ->where(fn ($q) => $q->whereIn('type', ['요청', '질문'])->orWhereNull('type')
                    ->orWhereHas('items', fn ($i) => $i->where('user_id', $user->id)))
                ->with(['items' => fn ($i) => $i->where('user_id', $user->id), 'vehicle:id,vehicle_number', 'room:id,name'])
                ->withExists('resolvedBy')
                ->withMax('resolvedBy', 'sent_at')   // 답이 달린 시각 — 「오늘 끝난 것」을 오늘 닫힌 것만으로
                ->orderByDesc('sent_at')
                ->get();

            foreach ($messages as $m) {
                $item = $m->items->first();
                $direction = KakaoDirection::of($m->sender, $m->judgmentFor($item), $sides[$m->room_id] ?? [], $myName[$m->room_id] ?? '');
                // 담당 분야 — 내 편에게 온 요청이라도 내 분야가 아니면 「팀 동료 진행 중」으로(@나 로 콕 집은 것은 분야 무관 내 일)
                $offTopic = $direction === KakaoDirection::MINE
                    && ! KakaoDirection::mentions($m->mention, $myName[$m->room_id] ?? '', $sides[$m->room_id] ?? [])
                    && ! KakaoTopics::isMine($picked, $m->topic);
                if ($offTopic) {
                    $direction = KakaoDirection::TEAMMATE;
                }
                $b = KakaoBuckets::of($direction, $m, $item, (bool) $m->resolved_by_exists, $now);
                if ($offTopic && $b['bucket'] === 'teammate') {
                    $b['reason'] = '다른 분야('.$m->topic.')';
                }
                if ($b['bucket'] === 'done' && $b['auto'] && ! $allDone) {   // 답 달림 자동 완료 — 답이 오늘 온 것만(완료 박기, jin 11-11)
                    $replyAt = $m->resolved_by_max_sent_at ? Carbon::parse($m->resolved_by_max_sent_at) : null;
                    if (! $replyAt || ! $replyAt->isSameDay($now)) {
                        continue;
                    }
                }
                if ($b['bucket'] === null) {
                    continue;
                }
                $buckets[$b['bucket']][] = self::row($m, $item, $direction, $b, $now, $people[$m->room_id] ?? []);
            }
            // 놓쳤을 가능성·지금 할 일은 오래된 것부터, 나머지는 최신부터
            foreach (['missed', 'now', 'today', 'waiting'] as $k) {
                usort($buckets[$k], fn ($a, $b) => strcmp($a['sent_at'], $b['sent_at']));
            }
        }

        $lastRead = $roomIds === [] ? null : KakaoRead::whereIn('room_id', $roomIds)->where('user_id', $user->id)->latest('id')->first();

        return [
            'rooms' => $rooms,
            'buckets' => $buckets,
            'counts' => array_map('count', $buckets),
            'last_read' => $lastRead,
            'cost_today' => (float) KakaoRead::where('user_id', $user->id)->whereDate('created_at', $now->toDateString())->sum('cost_krw'),
            'window_days' => Setting::kakaoWindowDays(),
            'topics' => $picked,
        ];
    }

    private static function row(KakaoMessage $m, $item, string $direction, array $b, CarbonInterface $now, array $people = []): array
    {
        $first = KakaoExportParser::firstLine($m->body);

        return [
            'id' => $m->id,
            'bucket' => $b['bucket'],
            'reason' => $b['reason'],
            'auto' => $b['auto'],
            'direction' => $direction,
            'title' => mb_strlen($first) > 70 ? mb_substr($first, 0, 70).'…' : $first,
            'body' => $m->body,
            'sender' => $m->sender,
            'sender_role' => $people[$m->sender] ?? null,   // 우리 편의 부서(ERP 명부) — jin 11-10 «부서별로 나눠서 보여주면»
            'to' => $m->to_side,
            'type' => $item?->type_override ?? $m->type,
            'topic' => $m->topic,
            'sent_at' => $m->sent_at?->format('Y-m-d H:i') ?? '',
            'ago' => $m->sent_at ? self::ago($m->sent_at, $now) : '',
            'room' => $m->room?->name ?? '',
            'vehicle_id' => $m->vehicle_id,
            'vehicle_no' => $m->vehicle?->vehicle_number ?? $m->vehicle_no,
            'amount' => $m->amount,
            'ref' => $m->ref,
            'state' => $item?->state,
            'snooze_until' => $item?->snooze_until?->format('m/d H:i'),
            'snooze_reason' => $item?->snooze_reason,
            'has_reply' => (bool) $m->resolved_by_exists,
        ];
    }

    private static function ago(CarbonInterface $at, CarbonInterface $now): string
    {
        $h = $at->diffInHours($now);
        if ($h < 1) {
            return $at->diffInMinutes($now).'분 전';
        }

        return $h < 24 ? $h.'시간 전' : intdiv($h, 24).'일 전';
    }

    /** @return Collection<int, KakaoRoom> 사용자가 구독하지 않은 방(구독 UI 용) */
    public static function otherRooms(User $user): Collection
    {
        return KakaoRoom::whereNotIn('id', $user->kakaoRooms()->pluck('kakao_rooms.id'))->orderBy('name')->get();
    }
}
