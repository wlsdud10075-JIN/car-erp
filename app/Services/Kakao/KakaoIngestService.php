<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 수집기가 보낸 카톡 복사본 1회분 → 파싱 → 중복 제거 → 새 메시지 저장 → 분류(방 단위 1회) → 차량 매칭.
 * docs/design/kakao-automation.md §10-1 · §10-2 「키·id 결정」.
 *
 * - 같은 메시지(msg_key)는 한 번만 저장한다. 본문이 더 길어졌으면(이어지는 줄이 더 잡힘) 갱신만 한다.
 * - 첨부·스페이서는 모델에 안 보내고 `type='첨부'` 로 찍는다(창에는 표식만).
 * - 분류 실패 시 메시지는 남고 type 이 비어 「확인 필요」 묶음으로 간다. 읽기 회차는 failed 로 기록.
 * - 상한: 읽기당 새 메시지(Setting kakaoMaxNewPerRead) · 사용자 하루 비용(kakaoDailyCostCapKrw).
 */
class KakaoIngestService
{
    /** 모델별 $/M 토큰 (입력, 캐시 읽기, 출력) — 비용 기록용. 모르는 모델은 Sonnet 값. */
    private const PRICE_USD_PER_M = [
        'claude-sonnet-5-5' => [2.0, 0.20, 10.0],
        'claude-haiku-5-5' => [0.10, 0.01, 0.50],
        'claude-opus-5-5' => [4.0, 0.20, 20.0],
    ];

    private const OPEN_ITEMS_LIMIT = 30;

    public function __construct(private KakaoClassifierClient $client, private KakaoExportParser $parser = new KakaoExportParser) {}

    public function ingest(User $user, KakaoRoom $room, string $text, ?string $fallbackDate = null): KakaoRead
    {
        // 같은 방을 두 사람이 몇 초 사이에 읽으면 둘 다 「처음 보는 메시지」로 판단해 같은 msg_key 를 넣다 유니크 위반이 난다.
        // 방 단위 잠금 — 두 번째는 첫 번째가 끝난 뒤 들어와 「이미 있음」으로 지나간다(분류 1회 보장).
        return Cache::lock('kakao:room:'.$room->id, 180)->block(30, fn () => $this->ingestLocked($user, $room, $text, $fallbackDate));
    }

    private function ingestLocked(User $user, KakaoRoom $room, string $text, ?string $fallbackDate): KakaoRead
    {
        $parsed = $this->parser->parse($text, $fallbackDate);

        $spentToday = (float) KakaoRead::where('user_id', $user->id)->whereDate('created_at', now()->toDateString())->sum('cost_krw');
        if ($spentToday >= Setting::kakaoDailyCostCapKrw()) {
            throw new KakaoLimitException('오늘 분류 비용 상한('.Setting::kakaoDailyCostCapKrw().'원)에 닿았습니다. 내일 다시 읽거나 기능설정에서 상한을 올리세요.');
        }

        $keys = array_column($parsed, 'msg_key');
        $existing = $keys === [] ? collect() : KakaoMessage::where('room_id', $room->id)->whereIn('msg_key', $keys)->get()->keyBy('msg_key');
        $newParsed = array_values(array_filter($parsed, fn ($m) => ! $existing->has($m['msg_key'])));
        $classifyCount = count(array_filter($newParsed, fn ($m) => ! KakaoExportParser::isAttachment($m['body'])));
        if ($classifyCount > Setting::kakaoMaxNewPerRead()) {
            throw new KakaoLimitException("새 메시지가 {$classifyCount}건으로 읽기 1회 상한(".Setting::kakaoMaxNewPerRead().'건)을 넘습니다. 읽는 범위를 좁히세요.');
        }

        $read = KakaoRead::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'sides_snapshot' => $room->sides,
            'started_at' => now(),
            'total' => count($parsed),
            'new_count' => count($newParsed),
            'status' => 'pending',
        ]);

        // ① 저장 (갱신 + 신규) — 분류와 분리해 둔다: 분류가 죽어도 메시지는 남는다
        $fresh = DB::transaction(function () use ($parsed, $existing, $newParsed, $room, $read) {
            foreach ($parsed as $m) {
                $row = $existing->get($m['msg_key']);
                if ($row && strlen($m['body']) > strlen($row->body) && str_starts_with($m['body'], $row->body)) {
                    $row->update(['body' => $m['body']]);   // 다음 읽기에 이어지는 줄이 더 잡힌 경우
                }
            }
            $rows = [];
            foreach ($newParsed as $m) {
                $rows[] = KakaoMessage::create([
                    'room_id' => $room->id,
                    'read_id' => $read->id,
                    'msg_key' => $m['msg_key'],
                    'sent_at' => $m['sent_at'],
                    'sender' => $m['sender'],
                    'body' => $m['body'],
                    'type' => KakaoExportParser::isAttachment($m['body']) ? '첨부' : null,
                    'to_side' => KakaoExportParser::isAttachment($m['body']) ? '없음' : null,
                ]);
            }
            $room->forceFill(['last_read_at' => now()])->save();

            return collect($rows);
        });

        // 편 구성이 아직 없는 방(처음 읽은 방) — ERP 명부로 「나」·「우리 회사」를 자동으로 잡는다(jin 11-10 «왜 클릭을 하고 나눠야 하는데?»).
        // 못 잡으면 메시지만 쌓고 분류는 사람이 「이 방에서 나는 누구?」를 고른 뒤 classifyPending() 으로. 새 메시지가 0건이어도 「나」는 잡는다(두 번째 사람이 같은 방을 읽을 때)
        $myName = (string) ($room->users()->where('users.id', $user->id)->first()?->pivot->my_name ?? '');
        if (trim($myName) === '') {
            $room->autoSetUpFor($user);   // 편이 이미 있는 방이면 이 사용자의 「나」만 잡는다
        }

        if ($classifyCount === 0) {
            $read->update(['status' => 'done', 'finished_at' => now()]);
            // 새 메시지는 없는데 방금 「나」가 잡혀 편이 생겼으면, 앞서 설정 없이 쌓였던 미분류를 이제 분류한다(두 번째 사람이 같은 방을 읽는 경우)
            if ($room->isSetUp() && KakaoMessage::where('room_id', $room->id)->whereNull('type')->exists()) {
                $this->classifyPending($room, $user);
            }

            return $read;
        }
        if (! $room->isSetUp()) {
            $read->update(['status' => 'pending_setup', 'finished_at' => now()]);

            return $read;
        }
        $room->refreshCounterpart();   // 새로 나타난 사람은 「상대」로

        $this->classify($room, $fresh, $read);   // 첨부도 같이 넘긴다 — 창에 표식으로만 들어가 흐름을 보여 준다(apply 는 첨부를 건너뛴다)

        return $read;
    }

    /** 설정이 끝난 방의 미분류 메시지(첨부 제외, type null)를 분류한다 — 설정 전에 쌓인 것·실패했던 것. */
    public function classifyPending(KakaoRoom $room, User $user): ?KakaoRead
    {
        if (! $room->isSetUp()) {
            return null;
        }
        $room->refreshCounterpart();
        $pending = KakaoMessage::where('room_id', $room->id)->whereNull('type')->orderBy('sent_at')->limit(Setting::kakaoMaxNewPerRead())->get();
        if ($pending->isEmpty()) {
            return null;
        }
        $read = KakaoRead::create([
            'room_id' => $room->id, 'user_id' => $user->id, 'sides_snapshot' => $room->sides,
            'started_at' => now(), 'total' => $pending->count(), 'new_count' => $pending->count(), 'status' => 'pending',
        ]);
        $this->classify($room, $pending, $read);

        return $read;
    }

    private function classify(KakaoRoom $room, Collection $fresh, KakaoRead $read): void
    {
        try {
            $sides = $room->sideMembers();
            $roles = $room->sideRoles();
            $open = $this->openItems($room, $fresh);
            $result = $this->client->classify(KakaoPrompt::system($sides, $roles, $room->personRoles(), $room->corrections()), KakaoPrompt::user($open, $fresh), KakaoPrompt::schema($sides));   // 사람이 고친 분류를 예시로(jin 11-10)
            $this->apply($room, $fresh, $open, $result['items'], $sides);
            $usage = $result['usage'];
            $read->update([
                'status' => 'done',
                'finished_at' => now(),
                'model' => $result['model'],
                'input_tokens' => $usage['input_tokens'],
                'cache_read_tokens' => $usage['cache_read_tokens'],
                'cache_write_tokens' => $usage['cache_write_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'],
                'cost_krw' => self::costKrw($result['model'], $usage),
            ]);
        } catch (Throwable $e) {
            $read->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            report($e);
        }
    }

    /** 앞 읽기에서 아직 답이 안 달린 요청·질문 (최근 N건) — 모델이 resolves 로 가리킬 수 있게 창에 같이 준다. */
    private function openItems(KakaoRoom $room, Collection $fresh): Collection
    {
        $freshIds = $fresh->pluck('id')->all();

        return KakaoMessage::where('room_id', $room->id)
            ->whereIn('type', ['요청', '질문'])
            ->when($freshIds !== [], fn ($q) => $q->whereNotIn('id', $freshIds))
            ->whereDoesntHave('resolvedBy')
            ->orderByDesc('sent_at')
            ->limit(self::OPEN_ITEMS_LIMIT)
            ->get()
            ->sortBy('sent_at')
            ->values();
    }

    /** 모델 판정을 메시지에 적는다 — 값 검증(유형·편·resolves) + 차량 매칭. 미지의 id 는 무시. */
    private function apply(KakaoRoom $room, Collection $fresh, Collection $open, array $items, array $sides): void
    {
        $byId = $fresh->keyBy('id');
        $knownIds = $byId->keys()->merge($open->pluck('id'))->flip();
        $validTo = array_merge(array_keys($sides), ['없음']);

        DB::transaction(function () use ($byId, $knownIds, $validTo, $items, $room) {
            foreach ($items as $it) {
                $msg = $byId->get((int) ($it['id'] ?? 0));
                if (! $msg || $msg->type === '첨부') {
                    continue;
                }
                $type = in_array($it['type'] ?? null, KakaoMessage::TYPES, true) ? $it['type'] : null;
                $to = in_array($it['to'] ?? null, $validTo, true) ? $it['to'] : '없음';
                $resolves = isset($it['resolves']) && $it['resolves'] !== null ? (int) $it['resolves'] : null;
                if ($resolves !== null && ($resolves === $msg->id || ! $knownIds->has($resolves))) {
                    $resolves = null;
                }
                if ($resolves !== null) {
                    $target = KakaoMessage::find($resolves);
                    if (! $target || $target->room_id !== $room->id || $target->sent_at > $msg->sent_at) {
                        $resolves = null;
                    }
                }
                $vehicleNo = self::str($it['vehicle'] ?? null, 20);
                $vinTail = preg_match('/^\d{6}$/', (string) ($it['vin_tail'] ?? '')) ? $it['vin_tail'] : null;

                $msg->update([
                    'type' => $type,
                    'to_side' => $to,
                    'mention' => self::str($it['mention'] ?? null, 200),
                    'ref' => self::str($it['ref'] ?? null, 40),
                    'vehicle_no' => $vehicleNo,
                    'vin_tail' => $vinTail,
                    'amount' => self::str($it['amount'] ?? null, 40),
                    'resolves_id' => $resolves,
                    'vehicle_id' => self::matchVehicle($vehicleNo, $vinTail),
                    'ai' => $it,
                ]);
            }
        });
    }

    /** 차량번호 정확 일치(공백·「헤이」 접두 무시) → 없으면 차대번호 끝 6자리(차량관리 vinSearch 와 같은 like). */
    public static function matchVehicle(?string $vehicleNo, ?string $vinTail): ?int
    {
        if ($vehicleNo) {
            $plate = preg_replace('/\s+|^헤이/u', '', $vehicleNo);
            $id = Vehicle::where('vehicle_number', $plate)->value('id')
                ?? Vehicle::whereRaw("REPLACE(vehicle_number, ' ', '') = ?", [$plate])->value('id');
            if ($id) {
                return (int) $id;
            }
        }
        if ($vinTail) {
            $ids = Vehicle::where('nice_reg_vin', 'like', '%'.$vinTail)->limit(2)->pluck('id');
            if ($ids->count() === 1) {   // 둘 이상이면 모르는 것으로
                return (int) $ids->first();
            }
        }

        return null;
    }

    public static function costKrw(string $model, array $usage): float
    {
        [$in, $cache, $out] = self::PRICE_USD_PER_M[$model] ?? self::PRICE_USD_PER_M['claude-sonnet-5-5'];
        $usd = ($usage['input_tokens'] * $in
            + ($usage['cache_write_tokens'] ?? 0) * $in * 1.25
            + $usage['cache_read_tokens'] * $cache
            + $usage['output_tokens'] * $out) / 1_000_000;

        return round($usd * (int) config('services.anthropic.krw_per_usd', 1400), 2);
    }

    private static function str(mixed $v, int $max): ?string
    {
        $s = is_string($v) ? trim($v) : null;

        return $s === null || $s === '' ? null : mb_substr($s, 0, $max);
    }
}
