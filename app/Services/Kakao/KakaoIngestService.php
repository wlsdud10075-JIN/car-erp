<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 수집기가 보낸 카톡 복사본 1회분 → 파싱 → 중복 제거 → 새 메시지 저장 → 규칙(첨부·확인·잡담·반복·호출) → 분류(방 단위, 60건씩) → 차량 매칭.
 * docs/design/kakao-automation.md §10-1 · §10-2 「키·id 결정」 · §12(11-11 규칙 계층).
 *
 * - 같은 메시지(msg_key)는 한 번만 저장한다. 본문이 더 길어졌으면(이어지는 줄이 더 잡힘) 갱신만 한다.
 * - 규칙이 찍은 것(첨부·잡담·반복·호출)은 모델에 안 보낸다 — 「넵」은 일부러 모델에 보낸다(앞 요청을 닫는 resolves 를 단다, KakaoRules 머리 주석) — 창에는 표식 한 줄만(흐름 유지). KakaoRules.
 * - 분류는 CHUNK_SIZE 건씩 여러 번 부른다. 한 호출에 267건을 넣으면 출력 16k 토큰 상한에서 잘려 통째로 실패했다(11-11 ssancarerp 503건 「확인 필요」).
 *   호출마다 앞 조각의 미해결 요청·질문이 「앞 읽기」로 들어가므로 resolves 연결은 조각을 넘어 이어진다.
 * - 한 HTTP 요청은 $budgetSeconds 안에서만 돈다(nginx fastcgi_read_timeout 90s). 남은 건 type null 로 두고 회차를 partial 로 —
 *   화면이 continueClassifying(classifyPending) 으로 이어서 분류한다.
 * - 분류 실패 시 메시지는 남고 type 이 비어 「확인 필요」 묶음으로 간다. 회차는 failed 로 기록(앞 조각 결과는 보존).
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

    /** 한 호출에 넣는 분류 대상 수 — 실측 출력 ≈61 토큰/건(ssancarerp 11-11) → 60건 ≈ 3.7k 토큰 · 약 17초 */
    public const CHUNK_SIZE = 60;

    /** 한 HTTP 요청 안에서 분류에 쓰는 시간(초, 직전 조각 소요를 더해 판정). 넘으면 나머지는 다음 호출로. nginx 90s 안. 테스트에서 바꾼다. */
    public static int $budgetSeconds = 40;

    /** 호출(@이름만) 이 붙을 같은 사람 메시지를 찾는 범위(분) — 앞으로 10 · 뒤로 15 · 남의 바로 앞 메시지 3 */
    public const CALL_PREV_MINUTES = 10;

    public const CALL_NEXT_MINUTES = 15;

    public const CALL_ANYONE_PREV_MINUTES = 3;

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

        if ($this->spentToday($user) >= Setting::kakaoDailyCostCapKrw()) {
            throw new KakaoLimitException('오늘 분류 비용 상한('.Setting::kakaoDailyCostCapKrw().'원)에 닿았습니다. 내일 다시 읽거나 기능설정에서 상한을 올리세요.');
        }

        $keys = array_column($parsed, 'msg_key');
        $existing = $keys === [] ? collect() : KakaoMessage::where('room_id', $room->id)->whereIn('msg_key', $keys)->get()->keyBy('msg_key');
        $newParsed = array_values(array_filter($parsed, fn ($m) => ! $existing->has($m['msg_key'])));
        $classifyCount = count(array_filter($newParsed, fn ($m) => self::ruleType($m['body']) === null));
        // 읽기 1회 상한 — 거부하지 않고 **오래된 것부터 상한까지만** 저장한다(jin 11-10 «7일 기준 500 충분했어?» — 표본B 프롬 방은 바쁜 주 7일 창이 591건).
        // 수집기는 매번 창 전체를 다시 보내고 저장된 건은 건너뛰므로, [지금 읽기]를 한 번 더 누르면 나머지가 들어온다.
        $leftover = 0;
        if ($classifyCount > Setting::kakaoMaxNewPerRead()) {
            $kept = [];
            $n = 0;
            foreach ($newParsed as $m) {   // 파싱 순서 = 시각 순
                $isRuled = self::ruleType($m['body']) !== null;
                if (! $isRuled && $n >= Setting::kakaoMaxNewPerRead()) {
                    $leftover++;

                    continue;
                }
                $kept[] = $m;
                $n += $isRuled ? 0 : 1;
            }
            $newParsed = $kept;
            $keptKeys = array_flip(array_column($kept, 'msg_key'));
            $parsed = array_values(array_filter($parsed, fn ($m) => $existing->has($m['msg_key']) || isset($keptKeys[$m['msg_key']])));
            $classifyCount = $n;
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
        $read->leftover = $leftover;   // 상한에 걸려 이번에 안 담은 건수(선언된 프로퍼티 — §8 #96-B, DB 컬럼 아님)

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
                $ruled = self::ruleType($m['body']);   // 첨부·확인·잡담은 본문만으로 정해진다 — 모델에 안 간다
                $rows[] = KakaoMessage::create([
                    'room_id' => $room->id,
                    'read_id' => $read->id,
                    'msg_key' => $m['msg_key'],
                    'sent_at' => $m['sent_at'],
                    'sender' => $m['sender'],
                    'body' => $m['body'],
                    'type' => $ruled,
                    'to_side' => $ruled === null ? null : '없음',
                    'ai' => $ruled === null || $ruled === '첨부' ? null : ['rule' => $ruled],
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

        // ② 이웃이 필요한 규칙 — 반복(같은 사람 같은 본문 N분 안) · 호출(@이름만 → 앞뒤 메시지에 멘션)
        $this->applyNeighborRules($room, $fresh);

        $this->classify($room, $fresh, $read);   // 규칙이 찍은 것·첨부도 같이 넘긴다 — 창에 표식으로만 들어가 흐름을 보여 준다(apply 는 건너뛴다)

        return $read;
    }

    /** 설정이 끝난 방의 미분류 메시지(type null)를 분류한다 — 설정 전에 쌓인 것·실패했던 것·시간 예산에 밀린 것. 상한에 닿았으면 null. */
    public function classifyPending(KakaoRoom $room, User $user): ?KakaoRead
    {
        if (! $room->isSetUp() || $this->spentToday($user) >= Setting::kakaoDailyCostCapKrw()) {
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
        $this->applyNeighborRules($room, $pending);
        $this->classify($room, $pending, $read);

        return $read;
    }

    /** 아직 분류 안 된(type null) 메시지 수 — 화면의 「이어서 분류」 판단용 */
    public static function pendingCount(KakaoRoom $room): int
    {
        return KakaoMessage::where('room_id', $room->id)->whereNull('type')->count();
    }

    private function spentToday(User $user): float
    {
        return (float) KakaoRead::where('user_id', $user->id)->whereDate('created_at', now()->toDateString())->sum('cost_krw');
    }

    /** 본문만으로 정해지는 유형 — 첨부 · 확인 · 잡담. 모델에 보낼 것이면 null. */
    public static function ruleType(string $body): ?string
    {
        if (KakaoExportParser::isAttachment($body)) {
            return '첨부';
        }

        return KakaoRules::typeOf($body);
    }

    /**
     * 이웃을 봐야 하는 규칙 두 가지. $fresh 는 sent_at 순.
     *   반복 — 같은 사람이 REPEAT_MINUTES 안에 같은 본문을 또 보냈으면 뒤 건은 「반복」(첫 건만 분류).
     *   호출 — 「@이름」만 있는 메시지는 「호출」로 찍고, 같은 사람의 앞(10분)·뒤(15분) 내용 메시지에 그 이름을 멘션으로 붙인다.
     *          둘 다 없으면 남의 바로 앞(3분) 메시지. 그래도 없으면 붙일 곳 없이 「호출」만(창에 남고 할 일엔 안 뜬다).
     *   앞 읽기 끝에 걸려 있던 호출(붙일 곳 없던 것)도 이번 읽기의 다음 메시지에 붙인다(늦게 온 뒷 메시지).
     */
    private function applyNeighborRules(KakaoRoom $room, Collection $fresh): void
    {
        if ($fresh->isEmpty()) {
            return;
        }
        $fresh = $fresh->sortBy([['sent_at', 'asc'], ['id', 'asc']])->values();
        foreach ($fresh as $m) {   // 본문 규칙(확인·잡담) — 적재 때 찍히지만, 이 규칙이 생기기 전에 쌓인 미분류(classifyPending)도 여기서 걸러진다
            if ($m->type === null && ($t = self::ruleType($m->body)) !== null) {
                $m->update(['type' => $t, 'to_side' => '없음', 'ai' => $t === '첨부' ? null : ['rule' => $t]]);
            }
        }
        $from = $fresh->first()->sent_at->copy()->subMinutes(max(KakaoRules::REPEAT_MINUTES, self::CALL_PREV_MINUTES, self::CALL_NEXT_MINUTES));
        $to = $fresh->last()->sent_at->copy()->addMinutes(self::CALL_NEXT_MINUTES);
        $freshIds = $fresh->pluck('id')->flip();
        // 이웃 = 이 창 앞뒤의 기존 메시지 + 이번 메시지 (한 번에)
        $neighbors = KakaoMessage::where('room_id', $room->id)->whereBetween('sent_at', [$from, $to])
            ->whereNotIn('id', $freshIds->keys()->all())->get()
            ->concat($fresh)->sortBy([['sent_at', 'asc'], ['id', 'asc']])->values();
        $members = array_merge(...array_values($room->sideMembers() ?: [[]]));

        // ① 반복
        $lastSeen = [];   // sender|key → ['at' => Carbon, 'id' => int]
        foreach ($neighbors as $m) {
            if (in_array($m->type, ['첨부', '호출', '반복'], true)) {
                continue;
            }
            $k = $m->sender.'|'.KakaoRules::repeatKey($m->body);
            if ($k === $m->sender.'|') {
                continue;
            }
            $prev = $lastSeen[$k] ?? null;
            if ($prev && $freshIds->has($m->id) && $m->type === null && self::minutesBetween($m->sent_at, $prev['at']) <= KakaoRules::REPEAT_MINUTES) {
                $m->update(['type' => KakaoRules::TYPE_REPEAT, 'to_side' => '없음', 'ai' => ['rule' => 'repeat', 'of' => $prev['id']]]);

                continue;   // 반복은 다음 반복의 기준이 되지 않는다 — 첫 건 기준으로 10분
            }
            $lastSeen[$k] = ['at' => $m->sent_at, 'id' => $m->id];
        }

        // ② 호출
        $isContent = fn (KakaoMessage $m) => ! in_array($m->type, ['첨부', '호출', '반복', '확인', '잡담'], true);
        $list = $neighbors->values();
        foreach ($list as $i => $m) {
            $names = null;
            if ($m->type === KakaoRules::TYPE_CALL) {
                if (($m->ai['target'] ?? null) !== null) {
                    continue;   // 이미 붙었다
                }
                $names = explode(',', (string) $m->mention);   // 앞 읽기 끝에 붙일 곳 없이 남은 호출 — 뒤 메시지가 이제 왔는지 본다
            } elseif ($freshIds->has($m->id) && $m->type === null) {
                $names = KakaoRules::callees($m->body, $members);
            }
            if ($names === null || $names === []) {
                continue;
            }
            $target = self::callTarget($list, $i, $isContent);
            $m->update([
                'type' => KakaoRules::TYPE_CALL, 'to_side' => '없음', 'mention' => implode(',', $names),
                'ai' => ['rule' => 'call', 'target' => $target?->id],
            ]);
            if ($target) {
                $merged = array_values(array_unique(array_filter(array_merge(explode(',', (string) $target->mention), $names), fn ($s) => trim($s) !== '')));
                $target->update(['mention' => implode(',', $merged)]);
                $target->ruleMentions = $merged;   // 아직 분류 전이면 apply() 가 모델의 mention 과 합친다
            }
        }
    }

    /** 호출이 붙을 메시지 — 같은 사람 앞(10분) → 같은 사람 뒤(15분) → 아무나 바로 앞(3분) */
    private static function callTarget(Collection $list, int $i, callable $isContent): ?KakaoMessage
    {
        $call = $list[$i];
        for ($j = $i - 1; $j >= 0; $j--) {
            $c = $list[$j];
            if (self::minutesBetween($call->sent_at, $c->sent_at) > self::CALL_PREV_MINUTES) {
                break;
            }
            if ($c->sender === $call->sender && $isContent($c)) {
                return $c;
            }
        }
        for ($j = $i + 1, $n = $list->count(); $j < $n; $j++) {
            $c = $list[$j];
            if (self::minutesBetween($c->sent_at, $call->sent_at) > self::CALL_NEXT_MINUTES) {
                break;
            }
            if ($c->sender === $call->sender && $isContent($c)) {
                return $c;
            }
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            $c = $list[$j];
            if (self::minutesBetween($call->sent_at, $c->sent_at) > self::CALL_ANYONE_PREV_MINUTES) {
                break;
            }
            if ($isContent($c)) {
                return $c;
            }
        }

        return null;
    }

    /** Carbon 3 의 diffInMinutes 는 부호가 있다(§8 #34) — 앞뒤를 가리지 않는 간격 */
    private static function minutesBetween(CarbonInterface $a, CarbonInterface $b): float
    {
        return abs($a->diffInMinutes($b));
    }

    /**
     * 분류 — type null 인 것만 CHUNK_SIZE 씩 모델에, 그 사이의 규칙 메시지는 표식으로 같이. 시간 예산을 넘기면 나머지는 남긴다(partial).
     */
    private function classify(KakaoRoom $room, Collection $fresh, KakaoRead $read): void
    {
        $started = microtime(true);
        $fresh = $fresh->sortBy([['sent_at', 'asc'], ['id', 'asc']])->values();
        $targets = $fresh->filter(fn ($m) => $m->type === null)->values();
        $indexOf = $fresh->pluck('id')->flip();
        $chunks = $targets->chunk(self::CHUNK_SIZE)->values();
        $usage = ['input_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 0];
        $model = null;
        $status = 'done';
        $error = null;
        $leftover = 0;

        try {
            $sides = $room->sideMembers();
            $system = KakaoPrompt::system($sides, $room->sideRoles(), $room->personRoles(), $room->corrections());   // 사람이 고친 분류를 예시로(jin 11-10)
            $schema = KakaoPrompt::schema($sides);
            $lastChunkSeconds = 0.0;
            foreach ($chunks as $i => $chunk) {
                // 다음 조각이 직전 조각만큼 걸린다고 보고, 그걸 더해도 예산 안일 때만 시작한다(44초에 시작해 80초에 끝나면 nginx 90s 벽에 닿는다)
                if ($i > 0 && microtime(true) - $started + $lastChunkSeconds > self::$budgetSeconds) {
                    $leftover = $targets->count() - $i * self::CHUNK_SIZE;
                    $status = 'partial';
                    break;
                }
                $slice = $fresh->slice($indexOf[$chunk->first()->id], $indexOf[$chunk->last()->id] - $indexOf[$chunk->first()->id] + 1)->values();   // 조각 사이의 표식 줄까지
                $open = $this->openItems($room, $slice);
                $chunkStarted = microtime(true);
                $result = $this->client->classify($system, KakaoPrompt::user($open, $slice), $schema);
                $lastChunkSeconds = microtime(true) - $chunkStarted;
                $this->apply($room, $slice, $open, $result['items'], $sides);
                foreach ($usage as $k => $v) {
                    $usage[$k] += (int) ($result['usage'][$k] ?? 0);
                }
                $model = $result['model'];
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 2000);
            if ($e instanceof KakaoClassifyException && $e->usage) {
                foreach ($usage as $k => $v) {
                    $usage[$k] += (int) ($e->usage[$k] ?? 0);
                }
                $model = $model ?? $e->model;
            }
            report($e);
        }

        $read->update([
            'status' => $status,
            'finished_at' => now(),
            'error' => $error,
            'model' => $model,
            'input_tokens' => $usage['input_tokens'],
            'cache_read_tokens' => $usage['cache_read_tokens'],
            'cache_write_tokens' => $usage['cache_write_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'cost_krw' => $model ? self::costKrw($model, $usage) : 0,
        ]);
        $read->pendingAfter = $targets->filter(fn ($m) => $m->type === null)->count();   // 예산에 밀린 것 + 실패한 조각 (앞 조각 결과는 보존)
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

    /** 모델 판정을 메시지에 적는다 — 값 검증(유형·편·resolves) + 차량 매칭. 미지의 id 는 무시. 규칙이 찍은 메시지는 건너뛴다. */
    private function apply(KakaoRoom $room, Collection $fresh, Collection $open, array $items, array $sides): void
    {
        $byId = $fresh->keyBy('id');
        $knownIds = $byId->keys()->merge($open->pluck('id'))->flip();
        $validTo = array_merge(array_keys($sides), ['없음']);

        DB::transaction(function () use ($byId, $knownIds, $validTo, $items, $room) {
            foreach ($items as $it) {
                $msg = $byId->get((int) ($it['id'] ?? 0));
                if (! $msg || $msg->type !== null) {
                    continue;
                }
                $type = in_array($it['type'] ?? null, KakaoPrompt::MODEL_VISIBLE_TYPES, true) ? $it['type'] : null;
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
                // 멘션 = 모델이 본문에서 읽은 것 + 호출 규칙이 붙인 것(「@이름」만 있던 앞뒤 메시지)
                $mention = array_filter(array_map('trim', explode(',', (string) ($it['mention'] ?? ''))), fn ($s) => $s !== '');
                $mention = array_values(array_unique(array_merge($mention, $msg->ruleMentions ?? [])));

                $msg->update([
                    'type' => $type,
                    'to_side' => $to,
                    'mention' => self::str($mention === [] ? null : implode(',', $mention), 200),
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
