<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Consignee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 선적일·ETA 일괄 지정 (jin 2026-07-28) — 한 선박에 실린 수백 대를 한 번에 처리.
 * 2026-08-12 선박명 · 2026-09-01 컨테이너 접두어 · 2026-09-08 수출신고번호·컨테이너번호가 합류해
 * 실질은 **「대상 차량 여럿에 같은 선적·통관 값을 찍는」 단일 도구**다(클래스명은 최초 용도 그대로).
 *
 * 배경: 선박 1척에 300대를 배정해도 실제로는 280대만 실린다. 포워더 자료로 실린 차를 추려
 * (차량목록 필터 → 「건수만」 → 엑셀 대조) 그 조건에 걸린 차량 전체에 같은 날짜를 찍는다.
 *
 * 대상은 **호출부가 넘긴 쿼리로 서버에서 다시 도출**한다. 클라이언트가 보낸 ID 목록을 믿지 않는다
 * (SKILLS §8 #26 — 수백 건 변경이라 IDOR 피해가 크다). 차량별로 canScopeVehicle 재인가.
 *
 * 흐름 (차량별, 트랜잭션):
 *   ① 스코프 밖 차량은 skip 리포트.
 *   ② 값이 이미 같으면 unchanged — 재적용해도 감사로그가 부풀지 않는다.
 *   ③ update → Vehicle updated 훅 recordChange 가 컬럼별 old→new 를 자동 감사
 *      (shipping_date·eta_date 는 AUDITED_COLUMNS 등재).
 *   ④ 일괄 출처(선박명 등 사유)는 bulk_shipping_date_applied 로 별도 보존.
 *
 * 선적일·ETA 는 진행상태 v4 cascade 에 안 들어가므로 progress_status_cache 는 바뀌지 않는다.
 * 그래도 모델 update 를 쓰는 이유는 감사·캐시 훅을 정상 경로로 태우기 위함이다(bulk update 는 훅이 안 뜬다).
 */
class BulkVehicleShippingDateService
{
    /**
     * 이 도구가 건드릴 수 있는 컬럼 — 그 외는 예외. 지정한 필드 밖으로 번지지 않게 봉인.
     *
     * 2026-09-08 (jin) — 수출신고번호·컨테이너번호 합류. 선적요청 묶음 화면에만 있던 일괄 기입을
     * 차량관리 「선택 N대」 에서도 쓸 수 있게 한 것이다(묶음이 아직 없거나 묶음을 넘나드는 경우).
     * ⚠️ `container_number` 는 이 목록의 **전체 값 기입**과 아래 `$containerPrefix` **접두어 치환**이
     *    같은 컬럼을 노린다 — 한 호출에 둘 다 오면 어느 쪽이 이기는지 사람이 알 수 없으므로 예외로 막는다.
     */
    /**
     * 이 도구가 만질 수 있는 컬럼 — **화이트리스트**. 여기 없으면 호출측이 넘겨도 무시된다.
     *
     * 🔀 2026-09-17 `bl_number` 추가 (jin) — 선적요청 묶음 화면에만 있던 것을 차량관리 「번호 일괄 기입」
     *    에서도 쓸 수 있게. 🚫 **`bl_document`(파일)는 넣지 않는다** — 그건 G1 100% 완납 게이트가 걸린
     *    자리이고, 선적요청 묶음 폼도 같은 이유로 제외해 뒀다.
     * 🔀 2026-10-27 `bl_loading_location`(반입지) 추가 (jin «모달에 선적일·도착일·선박명 아래 반입지») — 수출 탭 반입지와 같은
     *    항구 마스터(unloading) 값을 찍는다. ⚠️ **반입지는 v4 cascade(선적중)·수출 진입 게이트(말소 체크·컨사이니·입금률)를 탄다** —
     *    게이트에 걸린 차는 예외로 묶음 전체가 죽지 않게 **그 차만 skipped(gate:사유)** 로 돌려준다(§8 #67). 선박명처럼 섞임 경고도 탄다.
     * 🔑 `bl_number` 는 **진행상태·게이트 판정에 안 쓰인다**(2026-09-14 실측으로 기각 — 자유 입력칸이라
     *    빈 문자열·`-`·한글 메모가 실재한다, §8 #97). 그래서 일괄로 찍어도 단계가 넘어가지 않는다.
     */
    public const FIELDS = ['shipping_date', 'eta_date', 'vessel_name', 'export_declaration_number', 'container_number', 'bl_number', 'bl_loading_location'];

    /**
     * 🚢 **수출 정보 묶음 — 빈칸만 채움** (jin 2026-11-18 «알바니아 로로는 반입지·포워딩사·선적항·선적방법·인코텀즈·도착항이 거의 같다»).
     *
     * 실측 ssancarerp(06-01~, RORO+DURRESS 1,287대) — 5칸 조합 하나가 1,273대(98.9%). 그래서 같은 바이어 묶음엔 한 번에 찍는다.
     * 🔑 위 FIELDS(덮어쓰기)와 **규칙이 다르다** — 이미 값이 있는 칸은 건드리지 않는다(jin (가) — FOB·BUSAN 같은 예외를 지킨다).
     * 🔑 **대상이 한 바이어일 때만**(jin «바이어가 같다는 조건») — 컨사이니가 바이어에 종속이라 섞이면 무결성이 깨진다.
     * 🚪 반입지가 채워지면 수출 진입 게이트(말소·컨사이니·입금률)를 탄다. 선적방법 RORO + 대기 허용 도착항을 **같은 저장에서**
     *    넣으므로 `isShippingWaitRoute()` 가 서서 입금률(C5)은 건너뛴다 — 말소 체크·컨사이니는 그대로 막는다.
     * 컨사이니는 선적(bl) 칸에 넣고, 비어 있는 통관(export) 컨사이니·선적/통관 바이어도 같이 채운다(패널 inheritParties 와 같은 방향).
     */
    public const ROUTE_FIELDS = ['bl_loading_location', 'forwarding_company_id', 'port_of_loading', 'shipping_method', 'incoterms', 'discharge_port_id', 'bl_consignee_id'];

    /** 날짜로 다룰 컬럼(8자리 정규화·형식 검사 대상). 나머지는 문자열 그대로. */
    private const DATE_FIELDS = ['shipping_date', 'eta_date'];

    /**
     * 컨테이너 번호 **접두어** 꼴 — `6.08_G RORO 12-33_5` 의 앞 토큰 `6.08_G`.
     *   6 = 26년 · 08 = 월 · G = 선박 약칭(GMT). ssancarerp 실무 표기(jin 2026-09-01).
     *
     * 이 꼴이 아닌 것(ISO 번호 `EISU8533921` · 옛 표기 `RORO 4-19` · `-`)은 접두어로 치지 않는다.
     * 실측(2026-09-01 ssancarerp 4,407건): 접두어형 3,576 · ISO 748 · 그 외 83 · 접두어 종류 22.
     */
    private const PREFIX_SHAPE = '/^\d+\.\d+_[A-Za-z0-9]+$/u';

    /**
     * 대상 안의 기존 선박명 분포 — `['MV A' => 182, '' => 112]`. 빈 값은 `''` 키로 센다.
     *
     * 🧭 **왜 세는가** (jin 2026-08-12): 필터가 여러 배의 차를 함께 걷어왔는데 모르고 덮으면
     * **다른 배에 실린 차의 선박명이 통째로 날아간다**. 수백 대라 되돌리기도 어렵다.
     * 그래서 섞여 있으면 화면이 먼저 보여주고, 사람이 「그래도 덮기」를 눌러야 진행한다.
     *
     * ⚠️ **빈 값은 "다름" 으로 치지 않는다** — 처음 채우는 게 이 도구의 주 용도라,
     *    비어 있는 차가 섞였다고 매번 경고하면 경고가 무의미해진다(늘 뜨면 아무도 안 읽는다).
     *    판정은 `distinctCount()` 가 하고, 표시는 빈 값도 함께 보여준다(무엇이 바뀌는지 알아야 하므로).
     *
     * @return array<string, int> 선박명 => 대수 (대수 많은 순)
     */
    public function vesselBreakdown(Builder $query): array
    {
        return $this->valueBreakdown($query, 'vessel_name');
    }

    /**
     * 위 판정의 일반형 — 아무 FIELDS 컬럼의 기존 값 분포. 빈 값은 `''` 키.
     *
     * 🚫 컬럼마다 같은 SQL 을 옮겨 적지 말 것(SKILLS §8 #45) — 「선박명은 경고가 뜨는데 신고번호는 안 뜨는」
     *    형태가 된다. 신고번호·컨테이너번호 일괄 기입(2026-09-08)도 이 함수를 쓴다.
     *
     * @return array<string, int> 값 => 대수 (대수 많은 순)
     */
    public function valueBreakdown(Builder $query, string $column): array
    {
        if (! in_array($column, self::FIELDS, true)) {
            throw new InvalidArgumentException("분포 집계 불가 컬럼: {$column}");
        }

        $rows = $query->clone()
            ->selectRaw("COALESCE(NULLIF(TRIM({$column}), ''), '') as bucket, COUNT(*) as cnt")
            ->reorder()->groupBy('bucket')->pluck('cnt', 'bucket')->all();

        arsort($rows);

        return array_map('intval', $rows);
    }

    /** 실제로 값이 들어 있는 선박명 종류 수 — 2 이상이면 경고 대상. */
    public static function distinctCount(array $breakdown): int
    {
        return count(array_filter(
            $breakdown,
            fn ($cnt, $vsl) => trim((string) $vsl) !== '' && $cnt > 0,
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * 대상 안의 **컨테이너 접두어 분포** — `['6.08_G' => 296, '' => 831]`.
     *
     * 🧭 **왜 세는가** — 선적이 밀리면 한 배(접두어) 통째로 다음 배로 넘긴다. 그런데 같은 접두어를
     *    단 차 중 **실제로 실린 차와 안 실린 차가 섞여 있다** — 화면 필터로 먼저 추린 뒤,
     *    여기 뜬 숫자로 «몇 대가 바뀌는지» 를 눈으로 확인하고 누르게 한다.
     *
     * 🚫 SQL 로 자르지 않는다 — `SUBSTRING_INDEX` 는 MySQL 전용이라 SQLite 테스트에서 갈린다
     *    (SKILLS §8 #36 의 그 부류). 대상은 많아야 수천 건이라 PHP 로 묶는 게 안전하고 충분하다.
     *
     * @return array<string, int> 접두어 => 대수 (대수 많은 순). 접두어 없는 차는 `''` 키.
     */
    public function containerPrefixBreakdown(Builder $query): array
    {
        $rows = [];
        $query->clone()->reorder()->select('container_number')
            ->chunk(1000, function ($chunk) use (&$rows) {
                foreach ($chunk as $v) {
                    $p = self::containerPrefixOf($v->container_number);
                    $rows[$p] = ($rows[$p] ?? 0) + 1;
                }
            });
        arsort($rows);

        return $rows;
    }

    /** 컨테이너 번호의 접두어 — 접두어 꼴이 아니면 `''`(ISO 번호·옛 표기·빈칸). */
    public static function containerPrefixOf(?string $value): string
    {
        $first = preg_split('/\s+/u', trim((string) $value))[0] ?? '';

        return preg_match(self::PREFIX_SHAPE, $first) === 1 ? $first : '';
    }

    /**
     * 접두어만 갈아끼운다 — `6.08_G RORO 12-33_5` → `6.09_A RORO 12-33_5`.
     *
     * 🔑 **뒤 토큰은 손대지 않는다.** 바뀌는 건 «어느 배 · 몇 월» 뿐이고 화물 자리(`RORO 12-33_5`)는
     *    그대로다. 그래서 문자열 치환이 아니라 **앞 토큰 경계**로 자른다 —
     *    `str_replace` 를 쓰면 뒤쪽에 우연히 같은 문자열이 있을 때 거기까지 바뀐다.
     *
     * 대상이 아니면 `null`(= 이 차는 안 건드림). ISO 번호는 접두어로 시작하지 않아 자동으로 걸러진다.
     */
    public static function replaceContainerPrefix(?string $value, string $from, string $to): ?string
    {
        $value = (string) $value;
        $from = trim($from);
        if ($from === '' || $value === '') {
            return null;
        }
        if ($value === $from) {
            return $to;
        }
        $rest = substr($value, strlen($from));
        if (str_starts_with($value, $from) && $rest !== '' && preg_match('/^\s/u', $rest) === 1) {
            return $to.$rest;
        }

        return null;
    }

    /** 일괄 출처 감사줄의 칸 목록 — 묶음 칸(+이어받은 바이어·컨사이니)은 'export_route' 로 접고 60자 안에 맞춘다. */
    public static function auditColumns(array $cols): string
    {
        $route = array_merge(self::ROUTE_FIELDS, ['bl_buyer_id', 'export_buyer_id', 'export_consignee_id']);
        $plain = array_values(array_diff($cols, $route));
        if (count($plain) !== count($cols)) {
            $plain[] = 'export_route';
        }

        return mb_substr(implode(',', $plain), 0, 60);
    }

    /**
     * 대상의 바이어 — 한 명이면 그 id, 섞였거나 바이어 없는 차가 있으면 null(묶음 비활성).
     *
     * @return array{buyer_id: ?int, buyers: int, no_buyer: int}
     */
    public function routeTargetBuyer(Builder $query): array
    {
        $ids = $query->clone()->reorder()->select('buyer_id')->distinct()->pluck('buyer_id');
        $noBuyer = $ids->contains(fn ($id) => $id === null) ? $query->clone()->whereNull('buyer_id')->count() : 0;
        $real = $ids->filter()->values();

        return [
            'buyer_id' => ($real->count() === 1 && $noBuyer === 0) ? (int) $real->first() : null,
            'buyers' => $real->count(),
            'no_buyer' => $noBuyer,
        ];
    }

    /**
     * 묶음 기본값 — 그 바이어 차량에서 **가장 많은 조합**(6칸이 다 찬 차만). 없으면 회사 전체 최근 6개월의 가장 많은 조합.
     * 컨사이니는 그 바이어의 활성 컨사이니가 **1명일 때만** 미리 고른다(2명 이상이면 사람이 고른다 — jin 11-18).
     *
     * @return array<string, string> ROUTE_FIELDS => 값(문자열)
     */
    public function routeDefaults(int $buyerId): array
    {
        $cols = array_values(array_diff(self::ROUTE_FIELDS, ['bl_consignee_id']));
        $top = function (Builder $q) use ($cols): ?array {
            foreach ($cols as $c) {
                $q->whereNotNull($c)->where($c, '<>', '');
            }
            $row = $q->reorder()->selectRaw(implode(',', $cols).', COUNT(*) as n')->groupBy($cols)->orderByDesc('n')->first();

            return $row ? collect($cols)->mapWithKeys(fn ($c) => [$c => (string) $row->{$c}])->all() : null;
        };

        $out = $top(Vehicle::query()->where('buyer_id', $buyerId))
            ?? $top(Vehicle::query()->where('created_at', '>=', now()->subMonths(6)))
            ?? array_fill_keys($cols, '');

        $consignees = Consignee::where('buyer_id', $buyerId)->where('is_active', true)->pluck('id');
        $out['bl_consignee_id'] = $consignees->count() === 1 ? (string) $consignees->first() : '';

        return $out;
    }

    /**
     * 미리보기 — 칸마다 「지금 빈 차」 수 + 컨사이니 현재 분포(이름 => 대수, 빈칸 = '').
     *
     * @return array{empty: array<string,int>, consignees: array<string,int>, total: int}
     */
    public function routePreview(Builder $query): array
    {
        $empty = [];
        foreach (self::ROUTE_FIELDS as $c) {
            $empty[$c] = $query->clone()->where(fn ($w) => $w->whereNull($c)->orWhere($c, ''))->count();
        }
        $byCons = $query->clone()->reorder()->selectRaw('bl_consignee_id, COUNT(*) as n')->groupBy('bl_consignee_id')->pluck('n', 'bl_consignee_id');
        $names = Consignee::withTrashed()->whereIn('id', $byCons->keys()->filter())->pluck('name', 'id');
        $consignees = [];
        foreach ($byCons as $id => $n) {
            $consignees[$id ? (string) ($names[$id] ?? '#'.$id) : ''] = (int) $n;
        }
        arsort($consignees);

        return ['empty' => $empty, 'consignees' => $consignees, 'total' => $query->clone()->count()];
    }

    /**
     * @param  Builder  $query  대상 차량 쿼리(화면 필터를 서버에서 재도출한 것)
     * @param  array<string, string|null>  $values  FIELDS 중 채운 것만 — 빈 칸은 그대로 유지된다
     * @param  string  $reason  일괄 적용 사유(선박명 등) — 감사에 남는다
     * @param  ?array{from:string,to:string}  $containerPrefix  컨테이너 접두어 교체 (null=안 함)
     * @param  ?array<string, string>  $routeFill  수출 정보 묶음(ROUTE_FIELDS) — **빈칸만 채움**, 대상이 한 바이어일 때만 (null=안 함)
     * @return array{applied:int, unchanged:int, skipped:list<array{id:int,number:?string,reason:string}>}
     */
    public function apply(Builder $query, array $values, User $by, string $reason, ?array $containerPrefix = null, ?array $routeFill = null): array
    {
        $routeBuyerId = null;
        if ($routeFill !== null) {
            $routeFill = array_filter(
                array_map(fn ($v) => trim((string) ($v ?? '')), array_intersect_key($routeFill, array_flip(self::ROUTE_FIELDS))),
                fn ($v) => $v !== ''
            );
            if ($routeFill === []) {
                $routeFill = null;
            }
        }
        if ($routeFill !== null) {
            $routeBuyerId = $this->routeTargetBuyer($query)['buyer_id'];
            if ($routeBuyerId === null) {
                throw new InvalidArgumentException('수출 정보 묶음은 대상 차량이 모두 같은 바이어일 때만 적용할 수 있습니다.');
            }
            if (isset($routeFill['shipping_method']) && ! in_array($routeFill['shipping_method'], ['RORO', 'CONTAINER'], true)) {
                throw new InvalidArgumentException("선적방법 값이 올바르지 않습니다: {$routeFill['shipping_method']}");
            }
            if (isset($routeFill['incoterms']) && ! in_array($routeFill['incoterms'], ['FOB', 'CFR'], true)) {
                throw new InvalidArgumentException("인코텀즈 값이 올바르지 않습니다: {$routeFill['incoterms']}");
            }
            if (isset($routeFill['bl_consignee_id'])
                && ! Consignee::where('id', (int) $routeFill['bl_consignee_id'])->where('buyer_id', $routeBuyerId)->exists()) {
                throw new InvalidArgumentException('고른 컨사이니가 대상 바이어의 컨사이니가 아닙니다.');
            }
        }
        if (! $by->canAccessClearance()) {
            throw new AuthorizationException('선적·통관 정보 일괄 기입 권한 없음 (수출통관/관리 전용)');
        }

        $payload = [];
        foreach ($values as $column => $value) {
            if (! in_array($column, self::FIELDS, true)) {
                throw new InvalidArgumentException("일괄 지정 불가 컬럼: {$column}");
            }
            $value = trim((string) ($value ?? ''));
            // 빈 칸 = "안 건드림". 여기서 걸러야 "아무것도 안 쓰고 넘어가면 원래 값 유지" 가 성립한다.
            //   ⚠️ 그래서 이 도구로는 **값을 비울 수 없다**(빈 문자열이 지우기 신호가 되면 오조작 한 번에
            //      수백 대의 선박명이 날아간다). 지우는 건 차량 편집에서 개별로.
            if ($value === '') {
                continue;
            }
            if (in_array($column, self::DATE_FIELDS, true)) {
                // 8자리 타이핑(20260728)이 그대로 오면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 1970 이 된다.
                //   화면은 focusout 에서 정규화하지만(app.js), 수백 대를 바꾸는 입구라 서버에서도 막는다.
                $value = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $value);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    throw new InvalidArgumentException("날짜 형식이 올바르지 않습니다: {$value}");
                }
            }
            $payload[$column] = $value;
        }
        // 컨테이너 접두어 — 차량마다 뒤 토큰이 달라 $payload(공통값)에 못 넣는다. 별도로 검증해 루프에서 계산.
        if ($containerPrefix !== null) {
            $from = trim((string) ($containerPrefix['from'] ?? ''));
            $to = trim((string) ($containerPrefix['to'] ?? ''));
            if ($from === '' || $to === '') {
                throw new InvalidArgumentException('컨테이너 접두어는 「바꿀 것」과 「새 값」을 모두 입력해야 합니다.');
            }
            // 공백이 들어가면 토큰 경계가 깨져 뒤 화물 자리가 통째로 밀린다 — 입구에서 막는다.
            if (preg_match('/\s/u', $to) === 1) {
                throw new InvalidArgumentException("새 접두어에 공백은 넣을 수 없습니다: {$to}");
            }
            $containerPrefix = ['from' => $from, 'to' => $to];
        }

        // 같은 컬럼을 두 방식이 동시에 노리면 결과가 사람의 예상과 갈린다 — 조용히 한쪽을 이기게 두지 않는다.
        if (isset($payload['container_number']) && $containerPrefix !== null) {
            throw new InvalidArgumentException('컨테이너 번호는 「전체 기입」과 「접두어 치환」을 동시에 할 수 없습니다.');
        }

        // 반입지를 위(덮어쓰기)와 묶음(빈칸만) 양쪽에 다 넣으면 덮어쓰기가 이긴다 — 묶음 쪽은 뺀다(사람이 명시로 고른 쪽 우선).
        if ($routeFill !== null && isset($payload['bl_loading_location'])) {
            unset($routeFill['bl_loading_location']);
        }

        if ($payload === [] && $containerPrefix === null && $routeFill === null) {
            throw new InvalidArgumentException('일괄 기입할 값을 최소 하나는 입력해야 합니다.');
        }

        $applied = 0;
        $unchanged = 0;
        $skipped = [];

        DB::transaction(function () use ($query, $payload, $containerPrefix, $routeFill, $routeBuyerId, $by, $reason, &$applied, &$unchanged, &$skipped) {
            // chunkById — 수백~수천 대여도 메모리 안전. update 가 정렬 대상 컬럼을 안 건드려서 재방문 없음.
            $query->clone()->chunkById(200, function ($vehicles) use ($payload, $containerPrefix, $routeFill, $routeBuyerId, $by, $reason, &$applied, &$unchanged, &$skipped) {
                foreach ($vehicles as $vehicle) {
                    if (! $by->canScopeVehicle($vehicle)) {
                        $skipped[] = ['id' => $vehicle->id, 'number' => $vehicle->vehicle_number, 'reason' => 'no_scope'];

                        continue;
                    }

                    $diff = [];
                    foreach ($payload as $column => $value) {
                        // 날짜는 Carbon 이라 포맷해서, 선박명은 문자열 그대로 비교한다.
                        //   같으면 건너뛰어야 재적용해도 감사로그가 부풀지 않는다.
                        $current = in_array($column, self::DATE_FIELDS, true)
                            ? $vehicle->{$column}?->format('Y-m-d')
                            : (string) ($vehicle->{$column} ?? '');
                        if ((string) $current !== (string) $value) {
                            $diff[$column] = $value;
                        }
                    }
                    // 접두어 교체 — 접두어가 안 맞는 차(ISO 번호·다른 배)는 null 이 와서 그냥 안 바뀐다.
                    if ($containerPrefix !== null) {
                        $newNo = self::replaceContainerPrefix(
                            $vehicle->container_number, $containerPrefix['from'], $containerPrefix['to']
                        );
                        if ($newNo !== null && $newNo !== (string) $vehicle->container_number) {
                            $diff['container_number'] = $newNo;
                        }
                    }

                    // 🚢 수출 정보 묶음 — 빈 칸만. 컨사이니를 넣으면 비어 있는 선적·통관 바이어와 통관 컨사이니도 같은 값으로(패널 이어받기 방향).
                    if ($routeFill !== null) {
                        $isEmpty = fn (string $c) => ($vehicle->{$c} ?? '') === '' || $vehicle->{$c} === null;
                        foreach ($routeFill as $column => $value) {
                            // 선적 바이어가 판매 바이어와 다른 차엔 컨사이니를 안 넣는다(컨사이니는 바이어 종속 — 엉뚱한 쌍이 된다).
                            if ($column === 'bl_consignee_id' && ! $isEmpty('bl_buyer_id') && (int) $vehicle->bl_buyer_id !== $routeBuyerId) {
                                continue;
                            }
                            if ($isEmpty($column) && ! array_key_exists($column, $diff)) {
                                $diff[$column] = in_array($column, ['forwarding_company_id', 'discharge_port_id', 'bl_consignee_id'], true) ? (int) $value : $value;
                            }
                        }
                        if (array_key_exists('bl_consignee_id', $diff)) {
                            foreach (['bl_buyer_id', 'export_buyer_id'] as $bc) {
                                if ($isEmpty($bc)) {
                                    $diff[$bc] = $routeBuyerId;
                                }
                            }
                            if ($isEmpty('export_consignee_id')) {
                                $diff['export_consignee_id'] = $diff['bl_consignee_id'];
                            }
                        }
                    }

                    if ($diff === []) {
                        $unchanged++;

                        continue;
                    }

                    try {
                        $vehicle->fill($diff);
                        // 🚪 수출 진입 게이트(C4 말소 체크 · 컨사이니 · C5 입금률)는 모델 훅이 아니라 **패널 저장이 직접 부른다**
                        //    (vehicles/index::save → guardStageOrderForExport). 반입지는 그 게이트의 진입 신호라 여기서도 같은 함수를
                        //    부른다 — 안 부르면 일괄 기입이 게이트를 통째로 우회한다. 선적일·ETA 만 찍는 종전 호출은 그대로(동작 보존).
                        //    묶음이 반입지를 채우는 경우도 같다(빈칸 → 값 = 수출 진입).
                        if (array_key_exists('bl_loading_location', $diff)) {
                            $vehicle->guardStageOrderForExport();
                        }
                        $vehicle->save();
                    } catch (ValidationException $e) {
                        // 🚪 반입지는 수출 진입 게이트(말소 체크·컨사이니·입금률)를 탄다 — 한 대가 막혔다고 묶음 전체를 죽이지 않고
                        //    그 차만 사유와 함께 제외한다(§8 #67). 저장 훅이 던졌으니 DB 엔 아무것도 안 남았다.
                        $first = collect($e->errors())->flatten()->first();
                        $skipped[] = ['id' => $vehicle->id, 'number' => $vehicle->vehicle_number, 'reason' => 'gate:'.(string) $first];

                        continue;
                    }
                    AuditLog::create([
                        'user_id' => $by->id,
                        'auditable_type' => Vehicle::class,
                        'auditable_id' => $vehicle->id,
                        'action' => 'bulk_shipping_date_applied',
                        // ⚠️ audit_logs.column_name = varchar(60) — 묶음 10칸을 그대로 이으면 MySQL 이 1406 으로 거부한다(SQLite 는 통과, §8 #36).
                        //    묶음 칸은 'export_route' 한 단어로 접는다(칸별 old→new 는 Vehicle 감사 훅이 따로 남긴다).
                        'column_name' => self::auditColumns(array_keys($diff)),
                        'old_value' => null,
                        'new_value' => $reason,
                        'ip_address' => request()?->ip(),
                    ]);
                    $applied++;
                }
            });
        });

        return ['applied' => $applied, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }
}
