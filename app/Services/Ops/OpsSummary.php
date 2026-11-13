<?php

namespace App\Services\Ops;

use App\Models\AlimtalkLog;
use App\Models\KakaoRead;
use App\Models\ScheduledTaskRun;
use App\Services\SystemHealthReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 이 서버 한 곳의 시스템 요약 — 관제센터(jin 2026-11-12, 설계자용·jin 단독)가 회사마다 한 번씩 읽는다.
 *
 * 🔑 **회사 돈 숫자는 넣지 않는다**(jin «내가 각 회사 금액을 알 필요 없다 — 시스템 설계자로서 알아야 할 것»).
 *    이상 감지가 금액을 말해야 할 때(미수 하루 급변)만 「무엇이 이상한가」의 근거로 한 줄 싣는다.
 * ⚙️ 무거운 계산 금지 — 페이지가 5분 캐시로 부르지만 3사가 각자 계산한다. 쿼리 몇 개 + 로그 끝부분만 읽는다.
 * 🧩 판정을 옮겨 적지 않는다 — 점검 항목은 SystemHealthReport, 작업 기록은 ScheduledTaskRun 을 그대로 쓴다.
 */
class OpsSummary
{
    /** 로그 끝에서 이만큼만 읽는다 — 큰 파일을 통째로 열지 않는다 */
    private const LOG_TAIL_BYTES = 2_000_000;

    /** 판매가 단위 의심 — 외화 판매가×환율이 매입가의 이 배수를 넘으면(원화가 외화 칸에 들어간 모양, 11-02·11-09 실례) */
    public const SALE_UNIT_RATIO = 10;

    /** 차종 빈 칸 — 등록 후 이 일수가 지나도 비어 있을 때만 알린다 */
    public const EMPTY_MODEL_DAYS = 15;

    /** 미수 스냅샷 하루 변동 임계(원) — 11-09 실례 +211억 */
    public const RECEIVABLE_JUMP_KRW = 2_000_000_000;

    public static function collect(): array
    {
        app()->setLocale('ko');   // 점검 문구(SystemHealthReport)가 서명 요청(로그인 없음)에서도 한국어로
        $out = ['generated_at' => now()->toIso8601String(), 'company' => (string) config('company.template_set', 'system')];
        foreach (['server' => fn () => self::server(), 'routines' => fn () => self::routines(), 'health' => fn () => self::health(),
            'links' => fn () => self::links(), 'anomalies' => fn () => self::anomalies()] as $k => $fn) {
            try {
                $out[$k] = $fn();
            } catch (\Throwable $e) {   // 한 칸이 죽어도 나머지는 보낸다
                $out[$k] = ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return $out;
    }

    public static function server(): array
    {
        $base = base_path();
        $total = @disk_total_space($base) ?: 0;
        $free = @disk_free_space($base) ?: 0;

        return [
            'version' => self::gitSha(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'env' => app()->environment(),
            'pending_migrations' => self::pendingMigrations(),
            'disk_used_pct' => $total > 0 ? (int) round((1 - $free / $total) * 100) : null,
            'db_mb' => self::dbMegabytes(),
            'errors_today' => self::errorsToday(),
        ];
    }

    /** 예약 작업 — 작업별 마지막 실행 + 마지막 성공(ScheduledTaskRun, 11-12 부터 쌓임) */
    public static function routines(): array
    {
        $rows = [];
        foreach (ScheduledTaskRun::latestByCommand() as $cmd => $x) {
            $rows[] = [
                'command' => $cmd,
                'expression' => $x['last']->expression,
                'status' => $x['last']->status,
                'started_at' => $x['last']->started_at?->toIso8601String(),
                'duration_ms' => $x['last']->duration_ms,
                'error' => $x['last']->error ? mb_substr($x['last']->error, 0, 200) : null,
                'last_ok_at' => $x['last_ok_at']?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /** 매일 아침 점검과 같은 항목(환율·알림톡·공휴일·백업·board 전송) — SystemHealthReport 그대로 */
    public static function health(): array
    {
        return array_map(fn ($r) => ['key' => $r['key'], 'ok' => (bool) $r['ok'], 'detail' => (string) ($r['detail'] ?? '')], app(SystemHealthReport::class)->rows());
    }

    /** 외부 연동 — 오늘 숫자 몇 개(키 원문은 절대 싣지 않는다, 있음/없음만) */
    public static function links(): array
    {
        $today = now()->toDateString();

        return [
            // 🔀 2026-11-13 (jin «처리했으면 0건이 되어야») — 「오늘 실패 누적」이 아니라 아직 확인 안 한 실패·미도달(전 기간).
            //   점검줄(SystemHealthReport alimtalk_failed)과 같은 scope 라 두 숫자가 같이 움직인다. 키 이름은 3사 응답 호환으로 그대로.
            'alimtalk_failed_today' => AlimtalkLog::needsAttention()->count(),
            'alimtalk_sent_today' => AlimtalkLog::whereDate('created_at', $today)->where('status', 'sent')->count(),
            'claude_cost_today_krw' => Schema::hasTable('kakao_reads') ? round((float) KakaoRead::whereDate('created_at', $today)->sum('cost_krw')) : null,
            'keys' => [
                'anthropic' => (string) config('services.anthropic.key') !== '',
                'nice' => (string) config('services.nice.provide_token') !== '',
                'holiday' => (string) config('services.holiday.key') !== '',
                'board_sync' => (string) config('services.purchase_sync.hmac_secret') !== '',
                'erp_peer' => (string) config('services.erp_peer.hmac_secret') !== '',
            ],
        ];
    }

    /**
     * 데이터 이상 감지 — 「틀린 숫자가 대표에게 가기 전에」 설계자가 먼저 보는 줄들.
     * level: 3 긴급 · 2 주의 · 1 참고. 각 줄은 몇 건 + 예시 몇 개만.
     *
     * @return list<array{level:int, kind:string, text:string, count:int}>
     */
    public static function anomalies(): array
    {
        $a = [];

        // ① 판매가 단위 의심 — 원화 금액이 외화 칸에(63부3056 13,798,204 EUR · 287보1135, board 연동으로 들어옴)
        $unit = DB::table('vehicles')->whereNull('deleted_at')->where('currency', '!=', 'KRW')
            ->where('sale_price', '>', 0)->where('exchange_rate', '>', 0)->where('purchase_price', '>', 0)
            ->whereRaw('sale_price * exchange_rate > purchase_price * ?', [self::SALE_UNIT_RATIO])
            ->orderByDesc('updated_at')->limit(50)->pluck('vehicle_number');
        if ($unit->isNotEmpty()) {
            $a[] = ['level' => 3, 'kind' => '판매가 단위 의심', 'count' => $unit->count(),
                'text' => '외화 판매가×환율이 매입가의 '.self::SALE_UNIT_RATIO.'배 넘음 — '.$unit->take(5)->implode(', ')];
        }

        // ② 환율 범위 밖(CapitalStatusService 의 상식 범위와 같은 값)
        $fx = DB::table('vehicles')->whereNull('deleted_at')->where('sale_price', '>', 0)->where(fn ($q) => $q
            ->where(fn ($q2) => $q2->where('currency', 'USD')->where(fn ($r) => $r->where('exchange_rate', '<', 800)->orWhere('exchange_rate', '>', 2500)))
            ->orWhere(fn ($q2) => $q2->where('currency', 'EUR')->where(fn ($r) => $r->where('exchange_rate', '<', 900)->orWhere('exchange_rate', '>', 3000))))
            ->limit(50)->pluck('vehicle_number');
        if ($fx->isNotEmpty()) {
            $a[] = ['level' => 3, 'kind' => '환율 범위 밖', 'count' => $fx->count(), 'text' => 'USD 800~2,500 · EUR 900~3,000 밖 — '.$fx->take(5)->implode(', ')];
        }

        // ③ 미수 스냅샷 하루 급변
        if (Schema::hasTable('cash_snapshots')) {
            $last = DB::table('cash_snapshots')->orderByDesc('snapshot_date')->limit(2)->get(['snapshot_date', 'receivable_krw']);
            if ($last->count() === 2) {
                $diff = (int) $last[0]->receivable_krw - (int) $last[1]->receivable_krw;
                if (abs($diff) >= self::RECEIVABLE_JUMP_KRW) {
                    $a[] = ['level' => 3, 'kind' => '미수 하루 급변', 'count' => 1,
                        'text' => $last[1]->snapshot_date.' → '.$last[0]->snapshot_date.' 미수 '.($diff > 0 ? '+' : '−').number_format(abs($diff) / 100_000_000, 1).'억 — 오입력·정정부터 확인'];
                }
            }
        }

        // ④ 끊긴 참조 — 지운 바이어·컨사이니를 가리키는 차
        // 🔎 2026-11-13 (jin «주의 뜬 차량이 뭔지 알 수 있게») — 대수만 말하면 찾으러 갈 수가 없다. 차량번호를 몇 대까지 같이 싣는다.
        $deadBuyerQ = DB::table('vehicles as v')->join('buyers as b', 'b.id', '=', 'v.buyer_id')->whereNull('v.deleted_at')->whereNotNull('b.deleted_at');
        $deadBuyer = (clone $deadBuyerQ)->count();
        if ($deadBuyer > 0) {
            $a[] = ['level' => 2, 'kind' => '끊긴 참조', 'count' => $deadBuyer, 'text' => "삭제된 바이어를 가리키는 차 {$deadBuyer}대".self::plates($deadBuyerQ, $deadBuyer)];
        }
        // 컨사이니는 엑셀 적재 차(imported_at)를 뺀다 — 적재가 만든 쓰레기 컨사이니(「0.5」·「103」)를 지운 흔적이라 알고 있는 것(ssancarerp 222대 전부, jin 11-12 «적재로 넣은 건 포함되지 않게»).
        //   바이어는 안 뺀다 — 바이어는 돈(미수·정산현황)에 걸리므로 적재 차여도 끊기면 고쳐야 한다(11-12 AP Bilar 3대 재연결).
        //   🔀 2026-11-13 (jin) — 수출 탭 컨사이니(서류·선적에 쓰는 칸)가 살아 있으면 뺀다. 판매 쪽 칸만 옛 중복 컨사이니를 가리키는 차는
        //      화면·서류가 멀쩡해 손볼 게 없다(heymanerp 4대 중 3대가 그랬다 — jin «컨사이니 잘 들어간 것 같은데?»).
        $deadConQ = DB::table('vehicles as v')->join('consignees as c', 'c.id', '=', 'v.consignee_id')->leftJoin('consignees as e', 'e.id', '=', 'v.export_consignee_id')
            ->whereNull('v.deleted_at')->whereNull('v.imported_at')->whereNotNull('c.deleted_at')
            ->where(fn ($q) => $q->whereNull('e.id')->orWhereNotNull('e.deleted_at'));
        $deadCon = (clone $deadConQ)->count();
        if ($deadCon > 0) {
            $a[] = ['level' => 2, 'kind' => '끊긴 참조', 'count' => $deadCon, 'text' => "삭제된 컨사이니를 가리키는 차 {$deadCon}대 (엑셀 적재 차 · 수출 컨사이니가 정상인 차 제외)".self::plates($deadConQ, $deadCon)];
        }

        // ⑤ 정기 작업 실패(마지막 실행이 실패)
        $failed = ScheduledTaskRun::latestByCommand();
        $failed = array_keys(array_filter($failed, fn ($x) => $x['last']->status === 'failed'));
        if ($failed !== []) {
            $a[] = ['level' => 3, 'kind' => '정기 작업 실패', 'count' => count($failed), 'text' => implode(', ', array_slice($failed, 0, 6))];
        }

        // ⑥ 빈 칸 — 차종 비어 있음
        //   🔀 2026-11-13 (jin) — 갓 등록한 차는 바이어 미정·원부조회 전이라 비어 있는 게 정상이다. 등록 후 EMPTY_MODEL_DAYS 일이 지나도 빈 차만 본다.
        $noModelQ = DB::table('vehicles as v')->whereNull('v.deleted_at')->where('v.created_at', '<=', now()->subDays(self::EMPTY_MODEL_DAYS))
            ->where(fn ($q) => $q->whereNull('v.model_type')->orWhere('v.model_type', ''));
        $noModel = (clone $noModelQ)->count();
        if ($noModel > 0) {
            $a[] = ['level' => 1, 'kind' => '빈 칸', 'count' => $noModel, 'text' => '등록 후 '.self::EMPTY_MODEL_DAYS."일 넘도록 차종 빈 차 {$noModel}대".self::plates($noModelQ, $noModel)];
        }

        // ⑦ 카톡 — 읽다 멈춘 방
        if (Schema::hasColumn('kakao_room_users', 'last_result_ok')) {
            $stuck = DB::table('kakao_room_users')->where('selected', true)->where('last_result_ok', false)->count();
            if ($stuck > 0) {
                $a[] = ['level' => 1, 'kind' => '카톡', 'count' => $stuck, 'text' => "카톡 읽다 멈춘 방 {$stuck}개(실무자 PC)"];
            }
        }

        usort($a, fn ($x, $y) => $y['level'] <=> $x['level']);

        return $a;
    }

    /** 「 — 12가3456, 34나5678 외 2대」 — 이상 줄에 붙이는 차량번호(최근 수정 순 5대까지). 쿼리는 vehicles 별칭 v. */
    private static function plates($query, int $total, int $show = 5): string
    {
        $nos = (clone $query)->orderByDesc('v.updated_at')->limit($show)->pluck('v.vehicle_number')->filter()->all();
        if ($nos === []) {
            return '';
        }

        return ' — '.implode(', ', $nos).($total > count($nos) ? ' 외 '.($total - count($nos)).'대' : '');
    }

    private static function gitSha(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $h = trim((string) @file_get_contents($head));
        if (! str_starts_with($h, 'ref: ')) {
            return substr($h, 0, 8) ?: null;
        }
        $ref = substr($h, 5);
        $file = base_path('.git/'.$ref);
        if (is_file($file)) {
            return substr(trim((string) @file_get_contents($file)), 0, 8);
        }
        $packed = base_path('.git/packed-refs');
        if (is_file($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) @file_get_contents($packed), $m)) {
            return substr($m[1], 0, 8);
        }

        return null;
    }

    private static function pendingMigrations(): ?int
    {
        if (! Schema::hasTable('migrations')) {
            return null;
        }
        $files = array_map(fn ($f) => pathinfo($f, PATHINFO_FILENAME), File::glob(database_path('migrations/*.php')));
        $ran = DB::table('migrations')->pluck('migration')->all();

        return count(array_diff($files, $ran));
    }

    private static function dbMegabytes(): ?int
    {
        if (DB::getDriverName() !== 'mysql') {
            return null;
        }
        $bytes = DB::selectOne('SELECT SUM(data_length + index_length) AS b FROM information_schema.tables WHERE table_schema = DATABASE()')->b ?? null;

        return $bytes === null ? null : (int) round($bytes / 1048576);
    }

    /** 오늘 앱 오류 로그 줄 수 — laravel.log 끝 2MB 에서 「[오늘 …] *.ERROR」 만 센다 */
    private static function errorsToday(): ?int
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        $fh = fopen($path, 'r');
        if (! $fh) {
            return null;
        }
        fseek($fh, max(0, $size - self::LOG_TAIL_BYTES));
        $chunk = (string) stream_get_contents($fh);
        fclose($fh);

        return preg_match_all('/^\['.preg_quote(now()->toDateString(), '/').' [0-9:]+\] \w+\.(ERROR|CRITICAL|EMERGENCY|ALERT):/m', $chunk);
    }
}
