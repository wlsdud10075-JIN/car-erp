<?php

namespace App\Services\Kakao;

use App\Models\AuditLog;
use App\Models\KakaoMessage;
use App\Models\KakaoMessageVehicle;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 할 일 행 아래 「ERP 카드」 + ERP 로 보는 점검·자동 완료 (jin 11-11, 설계 §14 ②③).
 *
 * 보드는 render 마다 돈다(§8 #96-C) — 여기서는 **행별 쿼리 금지**. 메시지 묶음 하나에 쿼리 고정 개수:
 *   links 1 · (못 찾은 식별자 다시 찾기 ≤3) · vehicles+관계 5 · 감사로그 1.
 * 숫자는 Vehicle accessor 만(sale_unpaid_amount · unpaid_ratio · purchase_unpaid_amount) — 🚫 새 공식 금지(SKILLS §13 「5곳 정합표」).
 *
 * 반환(메시지 id → 배열):
 *   cards      차마다 한 줄(차량번호·차종·단계·매입·판매 미수·입금률·말소·선적 방식·포워딩·빈 칸·신고서)
 *   summary    2대 이상이면 요약 한 줄(「5대 중 2대 선적 칸 부족 · 3대 판매완납 · RORO 3」)
 *   inherit    이어받은 앞 글(보낸이·시각·첫 줄) | null
 *   flag       null | unregistered(서식인데 ERP 에 없음) | registered(그 뒤 ERP 에 생김) | check(ERP 점검 — VIN 다름·차종 빔)
 *   flag_note  사람이 읽는 한 줄
 *   flag_at    registered 일 때 그 차가 ERP 에 생긴 시각
 *   processed  ['what' => '말소서류 올라옴', 'at' => Carbon] | null — 요청 뒤에 ERP 에서 처리됨(자동 완료 후보)
 */
class KakaoErpCards
{
    /** 요청 본문 → 그 요청이 끝났다고 볼 ERP 사건. 🚫 ERP 가 모르는 것(보험·서류 발송·운임)은 넣지 않는다 */
    public const PROCESSED_RULES = [
        'dereg' => ['/말소/u', '말소서류 올라옴'],
        'down' => ['/계약금/u', '계약금 지급 확정'],
        'bl' => ['/서렌더|B\s*\/\s*L|비엘|OBL/iu', 'B/L 올라옴'],
    ];

    /**
     * @param  Collection<int, KakaoMessage>  $messages
     * @return array<int, array>
     */
    public static function for(Collection $messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $ids = $messages->pluck('id')->all();
        $links = KakaoMessageVehicle::whereIn('message_id', $ids)->orderBy('id')->get()->groupBy('message_id');

        // 저장 때 못 찾은 식별자 — 그 뒤 등록됐을 수 있으니 다시 찾는다(묶어서)
        $missing = $links->flatten()->whereNull('vehicle_id')->pluck('ident')->unique()->values()->all();
        $late = self::resolveMany($missing);

        $vehicleIds = $links->flatten()->pluck('vehicle_id')->filter()
            ->merge(array_filter($late))
            ->merge($messages->pluck('vehicle_id')->filter())   // 링크 없는 옛 글 — 모델이 고른 1대로 폴백(소급 없이도 카드)
            ->unique()->values()->all();
        $vehicles = $vehicleIds === [] ? collect() : Vehicle::with(['finalPayments', 'receivableHistories', 'purchaseBalancePayments', 'forwardingCompany'])
            ->whereIn('id', $vehicleIds)->get()->keyBy('id');

        $inheritFrom = $links->flatten()->where('via', 'inherit')->pluck('from_message_id')->filter()->unique()->values()->all();
        $sources = $inheritFrom === [] ? collect() : KakaoMessage::whereIn('id', $inheritFrom)->get(['id', 'sender', 'sent_at', 'body'])->keyBy('id');

        $minSent = $messages->min('sent_at');
        $audits = $vehicleIds === [] ? collect() : AuditLog::where('auditable_type', Vehicle::class)
            ->whereIn('auditable_id', $vehicleIds)
            ->whereIn('column_name', ['deregistration_document', 'bl_document'])
            ->whereNotNull('new_value')->where('new_value', '!=', '')
            ->where('created_at', '>=', $minSent)
            ->get(['auditable_id', 'column_name', 'created_at'])
            ->groupBy(fn ($a) => $a->auditable_id.'|'.$a->column_name);

        $out = [];
        foreach ($messages as $m) {
            $rows = $links->get($m->id, collect());
            if ($rows->isEmpty() && $m->vehicle_id) {
                $rows = collect([new KakaoMessageVehicle(['message_id' => $m->id, 'vehicle_id' => $m->vehicle_id, 'ident' => 'id:'.$m->vehicle_id, 'via' => 'self'])]);
            }
            if ($rows->isEmpty()) {
                continue;
            }
            $one = self::one($m, $rows, $late, $vehicles, $sources, $audits);
            if ($one['cards'] === [] && $one['flag'] === null && $one['summary'] === null) {
                continue;   // 식별자는 있는데 ERP 에 없는 한 줄 언급 — 빈 상자를 띄우지 않는다(「이어받음」만 남는 것도 같이)
            }
            $out[$m->id] = $one;
        }

        return $out;
    }

    private static function one(KakaoMessage $m, Collection $rows, array $late, Collection $vehicles, Collection $sources, Collection $audits): array
    {
        $resolved = [];      // vehicle_id => ['late' => 저장 때는 못 찾았고 지금 찾음]
        $strong = 0;
        foreach ($rows as $l) {
            $vid = $l->vehicle_id ?: ($late[$l->ident] ?? null);
            $isStrong = ! str_starts_with($l->ident, 'V:');
            $strong += $isStrong ? 1 : 0;
            if ($vid && $vehicles->has($vid)) {
                $resolved[$vid] = ['late' => ($resolved[$vid]['late'] ?? true) && $l->vehicle_id === null];
            }
        }
        // 못 찾은 대수 — 한 대의 서식엔 VIN·차량번호가 같이 있으니 식별자 수가 아니라 「종류별 최대 − 찾은 대수」
        $vinCount = $rows->filter(fn ($l) => str_starts_with($l->ident, 'VIN:'))->count();
        $plateCount = $rows->filter(fn ($l) => str_starts_with($l->ident, 'P:'))->count();
        $unresolvedStrong = max(0, max($vinCount, $plateCount) - count($resolved));

        $cards = [];
        foreach (array_keys($resolved) as $vid) {
            $cards[] = self::card($vehicles[$vid]);
        }

        $inherit = null;
        $first = $rows->first();
        if ($first->via === 'inherit' && ($src = $sources->get($first->from_message_id))) {
            $inherit = ['sender' => $src->sender, 'at' => $src->sent_at?->format('H:i'), 'title' => mb_substr(KakaoExportParser::firstLine($src->body), 0, 30)];
        }

        // ③ 미등록·등록됨·점검 — 서식 글에만(한 줄 언급 「48조5021 저당 없습니다」엔 안 띄운다: 이미 등록된 차를 말하는 경우가 대부분)
        $flag = null;
        $flagNote = null;
        $flagAt = null;
        if ($first->via === 'self' && KakaoVehicleLinks::isForm($m->body) && $strong > 0) {
            if ($resolved === []) {
                $flag = 'unregistered';
                $flagNote = 'ERP 미등록 — 차량 등록 필요';
            } elseif (collect($resolved)->every(fn ($r) => $r['late'])) {
                $flag = 'registered';
                $flagNote = 'ERP 등록됨';
                $flagAt = $vehicles->only(array_keys($resolved))->max('created_at');
            } else {
                $issues = self::formIssues($m->body, $vehicles->only(array_keys($resolved)));
                if ($issues !== []) {
                    $flag = 'check';
                    $flagNote = 'ERP 점검(등록) — '.implode(' · ', $issues);
                }
            }
        }

        return [
            'cards' => $cards,
            'summary' => count($cards) >= 2 || (count($cards) >= 1 && $unresolvedStrong > 0) ? self::summary($cards, $unresolvedStrong) : null,
            'unregistered_count' => $unresolvedStrong,
            'inherit' => $inherit,
            'flag' => $flag,
            'flag_note' => $flagNote,
            'flag_at' => $flagAt,
            'processed' => in_array($m->type, ['요청', '질문'], true) ? self::processed($m, $vehicles->only(array_keys($resolved)), $audits) : null,
        ];
    }

    /** 차 1대 카드 — 전부 기존 accessor·컬럼 */
    public static function card(Vehicle $v): array
    {
        $sold = (float) $v->sale_price > 0;
        $ratio = $v->unpaid_ratio;
        $blank = [];
        if ($sold) {
            foreach (['forwarding_company_id' => '포워딩', 'vessel_name' => 'VSL', 'shipping_date' => '선적일', 'eta_date' => 'ETA'] as $col => $label) {
                $val = $v->getAttribute($col);
                if ($val === null || $val === '') {
                    $blank[] = $label;
                }
            }
        }
        $purchaseUnpaid = (int) $v->purchase_unpaid_amount;

        return [
            'id' => $v->id,
            'number' => $v->vehicle_number,
            'model' => trim(($v->brand ?? '').' '.($v->model_type ?? '')),
            'stage' => $v->progress_status_cache,
            'purchase' => (float) $v->purchase_price <= 0 ? '매입가 없음' : ($purchaseUnpaid <= 0 ? '매입 완납' : '매입 미지급 '.number_format($purchaseUnpaid)),
            'sale_empty' => ! $sold,
            'currency' => $v->currency,
            'sale_unpaid' => $sold ? (float) $v->sale_unpaid_amount : null,
            'paid_pct' => $sold && $ratio !== null ? (int) round((1 - $ratio) * 100) : null,
            'sale_paid' => $sold && (float) $v->sale_unpaid_amount <= 0,
            'dereg' => $v->is_deregistered ? ($v->deregistration_document ? '말소 완료' : '말소 체크(서류 없음)') : '말소 전',
            'method' => $v->shipping_method,
            'forwarder' => $v->forwardingCompany?->name,
            'vessel' => $v->vessel_name,
            'blank' => $blank,
            'declaration' => (bool) $v->export_declaration_document,
            'bl' => (bool) $v->bl_document,
        ];
    }

    /** 「5대 중 2대 선적 칸 부족 · 3대 판매완납 · RORO 3 · 컨테이너 2 · 1대 ERP 미등록」 */
    public static function summary(array $cards, int $unregistered = 0): string
    {
        $n = count($cards) + $unregistered;
        $parts = [];
        $short = count(array_filter($cards, fn ($c) => $c['blank'] !== []));
        if ($short) {
            $parts[] = $short.'대 선적 칸 부족';
        }
        $paid = count(array_filter($cards, fn ($c) => $c['sale_paid']));
        if ($paid) {
            $parts[] = $paid.'대 판매완납';
        }
        $noSale = count(array_filter($cards, fn ($c) => $c['sale_empty']));
        if ($noSale) {
            $parts[] = $noSale.'대 판매금액 없음';
        }
        $roro = count(array_filter($cards, fn ($c) => $c['method'] === 'RORO'));
        $con = count(array_filter($cards, fn ($c) => $c['method'] === 'CONTAINER'));
        if ($roro) {
            $parts[] = 'RORO '.$roro;
        }
        if ($con) {
            $parts[] = '컨테이너 '.$con;
        }
        if ($unregistered) {
            $parts[] = $unregistered.'대 ERP 미등록';
        }

        return $n.'대'.($parts === [] ? '' : ' 중 '.implode(' · ', $parts));
    }

    /** 서식과 ERP 가 어긋남 — VIN 다름 · 차종 비어 있음. 「있음」 판정은 빈 문자열까지 본다(§8 #97) */
    private static function formIssues(string $body, Collection $vehicles): array
    {
        $issues = [];
        $vins = array_map(fn ($i) => substr($i, 4), array_filter(KakaoVehicleLinks::identifiers($body), fn ($i) => str_starts_with($i, 'VIN:')));
        foreach ($vehicles as $v) {
            $erpVin = strtoupper(trim((string) $v->nice_reg_vin));
            if ($vins !== [] && $erpVin !== '' && ! in_array($erpVin, $vins, true)) {
                $issues[] = $v->vehicle_number.' 차대번호 다름(ERP '.$erpVin.')';
            }
            if (trim((string) $v->model_type) === '') {
                $issues[] = $v->vehicle_number.' 차종 비어 있음';
            }
        }

        return $issues;
    }

    /** 요청 뒤에 ERP 에서 그 일이 처리됐나 — 연결된 차 **전부**에 사건이 메시지 시각 뒤에 있어야. 사건 시각 = 가장 늦은 것 */
    private static function processed(KakaoMessage $m, Collection $vehicles, Collection $audits): ?array
    {
        if ($vehicles->isEmpty() || ! $m->sent_at) {
            return null;
        }
        foreach (self::PROCESSED_RULES as $key => [$pattern, $label]) {
            if (! preg_match($pattern, $m->body)) {
                continue;
            }
            $times = [];
            foreach ($vehicles as $v) {
                $at = match ($key) {
                    'dereg' => $audits->get($v->id.'|deregistration_document', collect())->where('created_at', '>', $m->sent_at)->max('created_at'),
                    'bl' => $audits->get($v->id.'|bl_document', collect())->where('created_at', '>', $m->sent_at)->max('created_at'),
                    'down' => $v->purchaseBalancePayments->where('type', 'down')->filter(fn ($p) => $p->confirmed_at && $p->confirmed_at->gt($m->sent_at))->max('confirmed_at'),
                };
                if (! $at) {
                    $times = null;
                    break;
                }
                $times[] = Carbon::parse($at);
            }
            if ($times) {
                return ['what' => $label, 'at' => collect($times)->max()];
            }
        }

        return null;
    }

    /**
     * 식별자 여러 개 → 차량 id (묶음 쿼리 ≤3). 저장 때 못 찾은 것을 화면이 다시 찾을 때.
     *
     * @return array<string, ?int>
     */
    public static function resolveMany(array $idents): array
    {
        $out = array_fill_keys($idents, null);
        $vins = [];
        $plates = [];
        $tails = [];
        foreach ($idents as $i) {
            if (str_starts_with($i, 'VIN:')) {
                $vins[strtoupper(substr($i, 4))] = $i;
            } elseif (str_starts_with($i, 'P:')) {
                $plates[preg_replace('/\s+|^헤이/u', '', substr($i, 2))] = $i;
            } elseif (str_starts_with($i, 'V:')) {
                $tails[substr($i, 2)] = $i;
            }
        }
        if ($vins !== []) {
            $marks = implode(',', array_fill(0, count($vins), '?'));
            foreach (Vehicle::whereRaw("UPPER(nice_reg_vin) IN ($marks)", array_keys($vins))->get(['id', 'nice_reg_vin']) as $v) {
                $out[$vins[strtoupper($v->nice_reg_vin)]] = $v->id;
            }
        }
        if ($plates !== []) {
            $marks = implode(',', array_fill(0, count($plates), '?'));
            foreach (Vehicle::whereRaw("REPLACE(vehicle_number, ' ', '') IN ($marks)", array_keys($plates))->get(['id', 'vehicle_number']) as $v) {
                $key = str_replace(' ', '', $v->vehicle_number);
                if (isset($plates[$key])) {
                    $out[$plates[$key]] = $v->id;
                }
            }
        }
        if ($tails !== []) {
            $found = Vehicle::where(function ($q) use ($tails) {
                foreach (array_keys($tails) as $t) {
                    $q->orWhere('nice_reg_vin', 'like', '%'.$t);
                }
            })->get(['id', 'nice_reg_vin']);
            foreach ($tails as $t => $ident) {
                $hits = $found->filter(fn ($v) => str_ends_with(strtoupper((string) $v->nice_reg_vin), strtoupper((string) $t)));
                $out[$ident] = $hits->count() === 1 ? $hits->first()->id : null;   // 둘 이상이면 모르는 것으로(matchVehicle 과 같음)
            }
        }

        return $out;
    }
}
