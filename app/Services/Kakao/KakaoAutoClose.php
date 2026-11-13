<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 「이미 끝난 일인데 또 뜬다」 줄이기 — 답글 연결(resolves_id)이 놓친 요청·질문을 대화 흐름과 ERP 로 닫는다 (jin 2026-11-13).
 *
 * 실측(ssancarerp 수출팀 방 22개·3일, 건별로 읽어 판정): 할 일에 걸린 73건 중 37건이 닫히고 맞음 29 · 애매 7 · 틀림 1.
 * 가장 큰 원인은 ERP 가 아니라 **답이 있었는데 옆 글에 연결된 것**이었다(한 사람이 여러 줄로 나눠 쓰고 답은 한 줄에만 붙는다).
 *
 * 규칙(위에서부터, 먼저 맞는 것):
 *   erp      차를 아는 매입 서식·입금 요청 → 그 차 **매입** 지급 확정. 🚫 판매 입금과 맞추지 말 것(매입 서식이 그 차 판매 입금으로 닫혔다)
 *   plate    짧은 입금 요청(「입금 부탁」「934 부탁」) 뒤 60분 안, 다른 사람이 **차량번호만** 적은 짧은 답(재경팀이 송금증과 함께 올리는 모양)
 *   erp_pay  같은 짧은 입금 요청 → 방에서 언급된 차의 담당 영업 차에 60분 안 매입 지급(숫자가 있으면 금액 일치·24시간). 지급 1건 = 요청 1건
 *   file     파일을 달라는 요청(사진·인보이스·선적지시서·등록증…) 뒤 24시간 안에 **요청자와 다른 부서** 사람이 파일을 올림
 *   ack      연결 없는 「넵·알겠습니다·확인했습니다」(30분 안, 다른 사람) — 그 사이 더 가까운 다른 요청이 없을 때만
 *   thread   같은 사람의 앞뒤 30분 안 다른 글이, 이 글보다 뒤(60분 안)에 다른 사람의 답을 받음 → 이 글도 닫는다
 *   bundle   2분 안에 이어 쓴 글 중 하나가 **답글로** 닫힘 → 나머지도
 *
 * 🚫 넣지 말 것(같은 날 실험에서 버림): 「방 담당 차 중 그 종류 사건이 있었다」로 등록·선적·통관 닫기(싼카는 하루 수십 대가 바뀌어 24건 중 19건 틀림) ·
 *    「뒤에 아무 사진이나 올라오면 닫기」(지급 안 된 계약금 요청이 닫혔다) · 서식을 묶음·이어짐에 넣기(서식은 한 장이 차 한 대다).
 * 🧭 「아직 확인 안 되고 있습니다」 같은 부정 답도 닫는다(jin 11-13 — 답글 규칙과 같은 성질. 일이 안 끝났으면 다시 요청이 온다).
 * 화면(KakaoTodoBoard)이 매번 계산한다 — 저장하지 않으므로 파일·지급이 나중에 오면 그때 닫힌다. [다시 할 일로] 되돌린 글은 보드가 다시 닫지 않는다.
 */
class KakaoAutoClose
{
    public const BUNDLE_SECONDS = 120;

    public const THREAD_MINUTES = 30;

    public const REPLY_WITHIN_MINUTES = 60;

    public const ACK_WITHIN_MINUTES = 30;

    public const FILE_WITHIN_HOURS = 24;

    public const PAY_SHORT_CHARS = 40;

    private const PAY_SHORT = '/입금|계약금|잔금|송금|\d{2,5}\s*(만\s*원?\s*)?(더\s*)?부탁|급합니다/u';

    private const PLATE = '/\d{2,3}[가-힣]\d{4}/u';

    private const FILE_NOUN = '/사진|인보이스|선적\s*지시서|등록증|송금증|증권|계약서|캡[쳐처]|주소|서류|명함|파일/u';

    private const FILE_VERB = '/올려|보내|주세요|부탁|전달/u';

    /** 내가 보내는 글(「통관서류 보내드립니다」)은 파일을 달라는 요청이 아니다 */
    private const FILE_GIVING = '/보내\s*드립니다|전달\s*드립니다|올렸습니다|보냈습니다/u';

    private const ACK = '/^\s*(네|넵|넹|넴|예|옙|알겠습니다|확인했습니다|확인하겠습니다)/u';

    /**
     * @param  Collection<int, KakaoRoom>  $rooms
     * @return array<int, array{rule: string, at: ?Carbon, note: string}> 요청·질문 메시지 id => 닫힌 근거(답글로 이미 닫힌 글은 넣지 않는다)
     */
    public static function for(Collection $rooms, \DateTimeInterface $since): array
    {
        if ($rooms->isEmpty()) {
            return [];
        }
        $all = KakaoMessage::whereIn('room_id', $rooms->pluck('id'))->where('sent_at', '>=', $since)
            ->orderBy('sent_at')->orderBy('id')->get(['id', 'room_id', 'sent_at', 'sender', 'body', 'type', 'resolves_id', 'vehicle_id'])->groupBy('room_id');
        $links = DB::table('kakao_message_vehicles')->whereIn('message_id', $all->flatten()->pluck('id'))->whereNotNull('vehicle_id')->get(['message_id', 'vehicle_id'])->groupBy('message_id');

        // 방마다: 그 방 글이 말한 차 → 그 차들의 담당 영업(= 그 방의 범위)
        $roomVehicles = [];
        foreach ($all as $rid => $msgs) {
            $roomVehicles[$rid] = $msgs->pluck('vehicle_id')->merge($msgs->flatMap(fn ($m) => collect($links->get($m->id, []))->pluck('vehicle_id')))->filter()->unique()->values();
        }
        $vehicleIds = collect($roomVehicles)->flatten()->unique();
        $salesmanOf = $vehicleIds->isEmpty() ? collect() : DB::table('vehicles')->whereIn('id', $vehicleIds)->whereNotNull('salesman_id')->pluck('salesman_id', 'id');
        $scopeAll = $salesmanOf->unique()->values();
        $payments = $vehicleIds->isEmpty() ? collect() : DB::table('purchase_balance_payments as p')->join('vehicles as v', 'v.id', '=', 'p.vehicle_id')
            ->whereNull('v.deleted_at')->where('p.confirmed_at', '>=', $since)
            ->where(fn ($q) => $q->whereIn('p.vehicle_id', $vehicleIds)->orWhereIn('v.salesman_id', $scopeAll))
            ->orderBy('p.confirmed_at')->get(['p.id', 'p.vehicle_id', 'p.amount', 'p.confirmed_at', 'v.vehicle_number', 'v.salesman_id']);

        $out = [];
        foreach ($rooms as $room) {
            $msgs = ($all->get($room->id) ?? collect())->values();
            if ($msgs->isEmpty()) {
                continue;
            }
            $scope = $roomVehicles[$room->id]->map(fn ($vid) => $salesmanOf->get($vid))->filter()->unique()->values()->all();
            $out += self::room($msgs, $links, $payments, $scope, $room->personRoles());
        }

        return $out;
    }

    /** 방 하나 — $msgs 는 시각 순. */
    private static function room(Collection $msgs, Collection $links, Collection $payments, array $scope, array $people): array
    {
        $byId = $msgs->keyBy('id');
        $isAsk = fn (KakaoMessage $m) => in_array($m->type, ['요청', '질문'], true);
        $isForm = fn (KakaoMessage $m) => KakaoVehicleLinks::isForm((string) $m->body);
        // 부서 — 카톡 이름에 적힌 팀(「싼카-수출팀 ○○」)을 먼저, 없으면 ERP 명부 부서, 그것도 없으면 그 사람 자신(외부·영업은 서로 다른 부서로 본다).
        //   두 출처의 낱말을 하나로 맞춘다 — 안 맞추면 같은 수출팀인데 「수출팀」≠「수출통관」으로 달라 보여 동료의 파일이 답이 된다.
        $dept = function (string $name) use ($people) {
            if (preg_match('/(수출팀|재경팀|경리)/u', $name, $d)) {
                return $d[1] === '경리' ? '재경팀' : $d[1];
            }

            return match ($people[$name] ?? null) {
                '수출통관' => '수출팀',
                '재무' => '재경팀',
                default => '외부:'.$name,
            };
        };
        $replied = $msgs->pluck('resolves_id')->filter()->flip();   // 답글이 달린 글 id
        $closed = [];
        $usedAnswer = [];   // 한 답은 한 요청만
        $usedPayment = [];

        foreach ($msgs as $i => $m) {
            if (! $isAsk($m) || isset($replied[$m->id])) {
                continue;
            }
            $t = $m->sent_at;
            $body = (string) $m->body;
            $after = $msgs->slice($i + 1);
            $payShort = mb_strlen($body) <= self::PAY_SHORT_CHARS && preg_match(self::PAY_SHORT, $body);

            // erp — 차를 아는 매입 서식·입금 요청 → 그 차 매입 지급 확정
            $vids = collect($links->get($m->id, []))->pluck('vehicle_id')->push($m->vehicle_id)->filter()->unique();
            if ($vids->isNotEmpty() && preg_match('/차량\s*대금|계약금|잔금|입금|송금/u', $body)) {
                $p = $payments->first(fn ($p) => $vids->contains($p->vehicle_id) && Carbon::parse($p->confirmed_at)->gt($t));
                if ($p) {
                    $closed[$m->id] = self::r('erp', $p->confirmed_at, 'ERP 처리됨 — '.$p->vehicle_number.' 매입 지급 '.number_format((float) $p->amount).' 확정');

                    continue;
                }
            }
            if ($payShort) {
                // plate — 다른 사람이 차량번호만 적은 짧은 답
                $ans = $after->first(fn ($a) => $a->sender !== $m->sender && $a->sent_at->lte($t->copy()->addMinutes(self::REPLY_WITHIN_MINUTES)) && ! isset($usedAnswer[$a->id])
                    && mb_strlen(trim((string) $a->body)) <= 14 && preg_match(self::PLATE, (string) $a->body));
                if ($ans) {
                    $usedAnswer[$ans->id] = true;
                    $closed[$m->id] = self::r('plate', $ans->sent_at, '답이 달림 — '.self::quote($ans));

                    continue;
                }
                // erp_pay — 60분 안 매입 지급(방 담당 영업 차). 숫자가 있으면 금액이 같은 것만(24시간)
                if ($scope !== []) {
                    $amount = preg_match('/(?<!\d)(\d{2,5})\s*(만|부탁|더)/u', $body, $am) ? (int) $am[1] * 10000 : null;
                    $until = $t->copy()->addMinutes($amount ? 24 * 60 : self::REPLY_WITHIN_MINUTES);
                    $p = $payments->first(fn ($p) => in_array($p->salesman_id, $scope) && ! isset($usedPayment[$p->id])
                        && Carbon::parse($p->confirmed_at)->gt($t) && Carbon::parse($p->confirmed_at)->lte($until) && (! $amount || (int) $p->amount === $amount));
                    if ($p) {
                        $usedPayment[$p->id] = true;
                        $closed[$m->id] = self::r('erp_pay', $p->confirmed_at, 'ERP 처리됨(추정) — '.$p->vehicle_number.' 매입 지급 '.number_format((float) $p->amount).' 확정'.($amount ? ' · 금액 일치' : ''));

                        continue;
                    }
                }
            }
            // file — 파일을 달라는 요청 + 다른 부서(외부 포함) 사람이 파일을 올림
            if ($m->type === '요청' && preg_match(self::FILE_NOUN, $body) && preg_match(self::FILE_VERB, $body) && ! preg_match(self::FILE_GIVING, $body)) {
                $f = $after->first(fn ($a) => $a->type === '첨부' && $a->sender !== $m->sender && $a->sent_at->lte($t->copy()->addHours(self::FILE_WITHIN_HOURS)) && $dept($a->sender) !== $dept($m->sender));
                if ($f) {
                    $closed[$m->id] = self::r('file', $f->sent_at, '파일이 올라옴 — '.$f->sent_at->format('m/d H:i').' '.$f->sender);

                    continue;
                }
            }
            // ack — 연결 없는 「넵·알겠습니다」. 그 사이에 더 가까운 다른 요청이 있으면 그쪽 몫이라 닫지 않는다.
            if ($m->type === '요청') {
                $ack = $after->first(fn ($a) => $a->sender !== $m->sender && $a->sent_at->lte($t->copy()->addMinutes(self::ACK_WITHIN_MINUTES)) && $a->resolves_id === null && ! isset($usedAnswer[$a->id])
                    && mb_strlen((string) $a->body) <= 40 && ($a->type === '확인' || preg_match(self::ACK, (string) $a->body)));
                if ($ack) {
                    $nearer = $msgs->first(fn ($x) => $isAsk($x) && $x->id !== $m->id && $x->sender !== $ack->sender && ! isset($replied[$x->id])
                        && $x->sent_at->gt($t) && $x->sent_at->lte($ack->sent_at)
                        && ! ($x->sender === $m->sender && $t->diffInSeconds($x->sent_at) <= self::BUNDLE_SECONDS));   // 같은 사람이 바로 이어 쓴 줄은 같은 요청이다
                    if (! $nearer) {
                        $usedAnswer[$ack->id] = true;
                        $closed[$m->id] = self::r('ack', $ack->sent_at, '답이 달림 — '.self::quote($ack));
                    }
                }
            }
        }

        // thread — 같은 사람의 앞뒤 30분 안 다른 글이, 이 글보다 뒤에 다른 사람의 답을 받았다
        foreach ($msgs as $m) {
            if (! $isAsk($m) || isset($replied[$m->id]) || isset($closed[$m->id]) || $isForm($m)) {
                continue;
            }
            $r = $msgs->first(function ($a) use ($m, $byId, $isForm) {
                if ($a->resolves_id === null || $a->sender === $m->sender || $a->sent_at->lte($m->sent_at) || $a->sent_at->gt($m->sent_at->copy()->addMinutes(self::REPLY_WITHIN_MINUTES))) {
                    return false;
                }
                $x = $byId->get($a->resolves_id);

                return $x && $x->id !== $m->id && $x->sender === $m->sender && ! $isForm($x) && abs($x->sent_at->diffInMinutes($m->sent_at)) <= self::THREAD_MINUTES;
            });
            if ($r) {
                $closed[$m->id] = self::r('thread', $r->sent_at, '이어진 글에 답이 달림 — '.self::quote($r));
            }
        }

        // bundle — 같은 사람이 2분 안에 이어 쓴 글(사이에 다른 사람 글 없음) 중 하나가 답글로 닫혔다
        $run = [];
        $flush = function () use (&$run, &$closed, $replied, $msgs) {
            $hit = collect($run)->first(fn ($x) => isset($replied[$x->id]));
            if ($hit && count($run) > 1) {
                $answer = $msgs->first(fn ($a) => $a->resolves_id === $hit->id);
                foreach ($run as $x) {
                    if (! isset($replied[$x->id]) && ! isset($closed[$x->id])) {
                        $closed[$x->id] = self::r('bundle', $answer?->sent_at, '이어 쓴 글에 답이 달림'.($answer ? ' — '.self::quote($answer) : ''));
                    }
                }
            }
            $run = [];
        };
        $prev = null;
        foreach ($msgs as $m) {
            if ($prev && ($prev->sender !== $m->sender || $prev->sent_at->diffInSeconds($m->sent_at) > self::BUNDLE_SECONDS)) {
                $flush();
            }
            if ($isAsk($m) && ! $isForm($m)) {
                $run[] = $m;
            }
            $prev = $m;
        }
        $flush();

        return $closed;
    }

    private static function r(string $rule, mixed $at, string $note): array
    {
        return ['rule' => $rule, 'at' => $at ? Carbon::parse($at) : null, 'note' => $note];
    }

    private static function quote(KakaoMessage $a): string
    {
        return $a->sent_at->format('H:i').' '.$a->sender.' 「'.mb_substr(KakaoExportParser::firstLine((string) $a->body), 0, 30).'」';
    }
}
