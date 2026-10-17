<?php

namespace App\Services\Dashboard;

use App\Models\CashSnapshot;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 업무 대시보드 역할 뷰 5개(담당 영업·수출통관·재무·관리·업무관리자)의 **데이터 층** (jin 2026-10-17 «로컬에 구현해서 한번 보자»).
 *
 * 설계 원칙
 *  - 조건을 옮겨 적지 않는다 — 큐는 전부 `Vehicle::scopeAction()`, 매입 미지급은 `Vehicle::purchaseUnpaidRawExpr()`,
 *    「떠났나」는 `departed()/notDeparted()`, 미수·등급은 캐시 컬럼(`sale_unpaid_amount_krw_cache`·`receivable_risk`).
 *  - 범위(`$sids`) = 담당 영업 Salesman.id 배열. null = 전체. 관리의 범위는 `User::getSubordinateSalesmanIds()` 가 준다.
 *  - 없는 개념은 만들지 않는다 — 매입 잔금 「약속일」은 없으므로 **미래 날짜 잔금 행의 가장 이른 날짜**를 「예정일」로만 보여주고,
 *    「오래 멈춘 차」는 단계별 N일 설정이 없으므로 **이미 있는 정체 큐**(통관 정체 30일·말소 기한·미수 stale·ETA 없음)를 합친다.
 *  - 무거운 accessor 루프는 상위 몇 대에만. 합계·건수는 SQL.
 */
class WorkDashboardData
{
    /** 오래 멈춘 차 큐 — 멈춘 곳 => [action 키, 기준 날짜 컬럼, 추가 스코프명]. 단일 출처(목록·대수가 같이 본다). 「떠났나」는 Vehicle::notDeparted() 를 부른다(조건 복사 금지). */
    public const STUCK_QUEUES = [
        'clearance' => ['clearance_stuck', 'sale_date', null],
        'deregistration' => ['deregistration_due', 'purchase_date', null],
        'receivable' => ['receivable_danger', 'sale_date', 'notDeparted'],
        'eta' => ['eta_missing', 'shipping_date', null],
    ];

    private string $today;

    public function __construct()
    {
        $this->today = now()->toDateString();
    }

    private function base(?array $sids): Builder
    {
        return Vehicle::query()->whereNull('deleted_at')
            ->when($sids !== null, fn ($q) => $q->whereIn('salesman_id', $sids));
    }

    private function sumPurchaseUnpaid(Builder $q): int
    {
        return (int) ((clone $q)->selectRaw('SUM('.Vehicle::purchaseUnpaidRawExpr().') as total', [$this->today])->value('total') ?? 0);
    }

    // ───────────────────────── 담당 영업 ─────────────────────────

    /** 팀 합계 한 줄 — 미수·미지급·진행중. */
    public function teamTotals(?array $sids): array
    {
        $unpaid = $this->base($sids)->action('sale_unpaid');
        $purchase = $this->base($sids)->action('purchase_unpaid');

        return [
            'unpaid_krw' => (int) (clone $unpaid)->sum('sale_unpaid_amount_krw_cache'),
            'unpaid_count' => (clone $unpaid)->count(),
            'risk_count' => (clone $unpaid)->whereIn('receivable_risk', ['danger', 'critical'])->count(),
            'purchase_krw' => $this->sumPurchaseUnpaid($purchase),
            'purchase_count' => (clone $purchase)->count(),
            'active_count' => $this->base($sids)->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')->orWhereNull('progress_status_cache'))->count(),
        ];
    }

    /**
     * 영업별 카드 — 담당자마다 미수·미지급·입금률·가장 급한 차 3대.
     *
     * @param  Collection<int, Salesman>  $salesmen
     */
    public function salesmanCards(Collection $salesmen): array
    {
        $cards = [];
        foreach ($salesmen as $sm) {
            $sids = [$sm->id];
            $unpaidQ = $this->base($sids)->action('sale_unpaid');
            $purchaseQ = $this->base($sids)->action('purchase_unpaid');
            $top = (clone $unpaidQ)->with(['buyer:id,name'])
                ->orderByDesc('sale_unpaid_amount_krw_cache')->limit(3)
                ->get();
            $cards[] = [
                'salesman' => $sm,
                'active_count' => $this->base($sids)->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')->orWhereNull('progress_status_cache'))->count(),
                'unpaid_krw' => (int) (clone $unpaidQ)->sum('sale_unpaid_amount_krw_cache'),
                'unpaid_count' => (clone $unpaidQ)->count(),
                'risk_count' => (clone $unpaidQ)->whereIn('receivable_risk', ['danger', 'critical'])->count(),
                'purchase_krw' => $this->sumPurchaseUnpaid($purchaseQ),
                'purchase_count' => (clone $purchaseQ)->count(),
                // 입금률 = 100 − 집계 미수율(§13 단일 출처). 미수 차가 없으면 null(표시 안 함).
                'paid_pct' => ($r = Vehicle::aggregateUnpaidRatioPct($unpaidQ)) === null ? null : max(0, round(100 - $r, 1)),
                'top' => $top,
            ];
        }

        return $cards;
    }

    /** 돈이 막힌 차 — 미수 큰 순. */
    public function blockedSales(?array $sids, int $limit = 10): Collection
    {
        return $this->base($sids)->action('sale_unpaid')
            ->with(['buyer:id,name', 'salesman:id,name', 'finalPayments' => fn ($q) => $q->whereNotNull('confirmed_at')->orderByDesc('payment_date')])
            ->orderByDesc('sale_unpaid_amount_krw_cache')->limit($limit)->get();
    }

    /** 딜러에게 줄 매입 잔금 — 미지급 큰 순. 「예정일」 = 미래 날짜 잔금 행 중 가장 이른 날(약속일 개념은 없다). */
    public function purchaseDue(?array $sids, int $limit = 10): Collection
    {
        $expr = Vehicle::purchaseUnpaidRawExpr();

        return $this->base($sids)->action('purchase_unpaid')
            ->with(['salesman:id,name'])
            ->select('vehicles.*')
            ->selectRaw($expr.' as unpaid_calc', [$this->today])
            ->selectRaw('(SELECT MIN(payment_date) FROM purchase_balance_payments WHERE vehicle_id = vehicles.id AND payment_date > ?) as next_due', [$this->today])
            ->orderByDesc('unpaid_calc')->limit($limit)->get();
    }

    /**
     * 다음 단계로 넘길 것 — 영업별 대수. 행 = 큐, 열 = 영업.
     *
     * @return array<string, array<int, int>> [queue => [salesman_id => count]]
     */
    public function nextStepCounts(?array $sids): array
    {
        $out = [];
        foreach (['clearance_needed', 'freight_confirm_pending', 'deregistration_needed'] as $key) {
            $out[$key] = $this->base($sids)->action($key)
                ->selectRaw('salesman_id, COUNT(*) as cnt')->groupBy('salesman_id')->pluck('cnt', 'salesman_id')->map(fn ($c) => (int) $c)->all();
        }
        $out['general_stock'] = $this->base($sids)->generalStock()
            ->selectRaw('salesman_id, COUNT(*) as cnt')->groupBy('salesman_id')->pluck('cnt', 'salesman_id')->map(fn ($c) => (int) $c)->all();

        return $out;
    }

    // ───────────────────────── 수출통관 ─────────────────────────

    /** 서류 단계 칸반 4칸 — 큐 키·대수·가장 오래된 날·상위 3대. 「며칠」은 판매일 기준(말소 대기만 매입일). */
    public function documentKanban(?array $sids): array
    {
        $cols = [
            'deregistration_needed' => ['date' => 'purchase_date', 'badge' => 'badge-blue'],
            'export_declaration_upload_needed' => ['date' => 'sale_date', 'badge' => 'badge-amber'],
            'shipping_process_needed' => ['date' => 'sale_date', 'badge' => 'badge-green'],
            'bl_upload_needed' => ['date' => 'sale_date', 'badge' => 'badge-gray'],
        ];
        $out = [];
        foreach ($cols as $key => $meta) {
            $q = $this->base($sids)->action($key);
            $top = (clone $q)->orderByRaw($meta['date'].' IS NULL, '.$meta['date'].' ASC')->limit(3)->get(['id', 'vehicle_number', $meta['date']]);
            $out[$key] = [
                'badge' => $meta['badge'],
                'count' => (clone $q)->count(),
                'top' => $top->map(fn ($v) => ['id' => $v->id, 'number' => $v->vehicle_number, 'days' => $this->daysSince($v->{$meta['date']})]),
            ];
            $out[$key]['oldest_days'] = $out[$key]['top']->max('days');
        }

        return $out;
    }

    /** 이번 주 달력 — 월~일, 날짜마다 선적(선박별)·ETA 대수. */
    public function weekCalendar(?array $sids, ?CarbonImmutable $start = null): array
    {
        $start = ($start ?? CarbonImmutable::now())->startOfWeek();   // 월요일
        $end = $start->endOfWeek();
        $ships = $this->base($sids)->where('cancel_status', Vehicle::CANCEL_NONE)
            ->whereBetween('shipping_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('shipping_date, vessel_name, COUNT(*) as cnt')->groupBy('shipping_date', 'vessel_name')->get();
        $etas = $this->base($sids)->where('cancel_status', Vehicle::CANCEL_NONE)
            ->whereBetween('eta_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('eta_date, COUNT(*) as cnt')->groupBy('eta_date')->get();
        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $days[] = [
                'date' => $d,
                'is_today' => $key === $this->today,
                'ships' => $ships->filter(fn ($r) => $r->shipping_date?->toDateString() === $key)->map(fn ($r) => ['vessel' => $r->vessel_name, 'count' => (int) $r->cnt])->values(),
                'eta' => (int) $etas->first(fn ($r) => $r->eta_date?->toDateString() === $key)?->cnt,
            ];
        }

        return ['start' => $start, 'end' => $end, 'days' => $days,
            'ship_total' => (int) $ships->sum('cnt'), 'eta_total' => (int) $etas->sum('cnt')];
    }

    /** 진행중 차량 — 서류 기준. 선적일 가까운 순(없는 차는 뒤로). */
    public function documentTable(?array $sids, int $limit = 10): Collection
    {
        return $this->base($sids)
            ->where('cancel_status', Vehicle::CANCEL_NONE)
            ->where('sale_price', '>', 0)
            ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')->orWhereNull('progress_status_cache'))
            ->with(['buyer:id,name', 'exportBuyer:id,name', 'salesman:id,name'])
            ->orderByRaw('shipping_date IS NULL, shipping_date ASC')->orderByDesc('id')
            ->limit($limit)->get();
    }

    // ───────────────────────── 재무 ─────────────────────────

    /** 받을 돈 / 줄 돈. */
    public function moneyOverview(?array $sids = null): array
    {
        $unpaid = $this->base($sids)->action('sale_unpaid');
        $risk = (clone $unpaid)->whereIn('receivable_risk', ['danger', 'critical']);
        $purchase = $this->base($sids)->action('purchase_unpaid');
        $weekEnd = CarbonImmutable::now()->endOfWeek()->toDateString();

        return [
            'receivable_krw' => (int) (clone $unpaid)->sum('sale_unpaid_amount_krw_cache'),
            'receivable_count' => (clone $unpaid)->count(),
            'after_krw' => (int) $this->base($sids)->action('receivable_after_shipping')->sum('sale_unpaid_amount_krw_cache'),
            'after_count' => $this->base($sids)->action('receivable_after_shipping')->count(),
            'before_krw' => (int) $this->base($sids)->action('receivable_before_shipping')->sum('sale_unpaid_amount_krw_cache'),
            'before_count' => $this->base($sids)->action('receivable_before_shipping')->count(),
            'grace_krw' => (int) $this->base($sids)->where('receivable_risk', 'grace')->sum('sale_unpaid_amount_krw_cache'),
            'grace_count' => $this->base($sids)->where('receivable_risk', 'grace')->where('sale_unpaid_amount_krw_cache', '>', 0)->count(),
            'risk_krw' => (int) (clone $risk)->sum('sale_unpaid_amount_krw_cache'),
            'risk_count' => (clone $risk)->count(),
            'payable_krw' => $this->sumPurchaseUnpaid($purchase),
            'payable_count' => (clone $purchase)->count(),
            // 이번 주 지급 예정 = 미래 날짜 잔금 행이 이번 주 안에 있는 차
            'payable_week_count' => (clone $purchase)->whereExists(fn ($q) => $q->selectRaw('1')->from('purchase_balance_payments')
                ->whereColumn('vehicle_id', 'vehicles.id')->where('payment_date', '>', $this->today)->where('payment_date', '<=', $weekEnd))->count(),
            'cancel_count' => $this->base($sids)->where('cancel_status', Vehicle::CANCEL_ACTIVE)->where('sale_unpaid_amount_krw_cache', '>', 0)->count(),
        ];
    }

    /** 정산 배치 진행줄 — 대기 → 확정 → 보류 → 2차. */
    public function settlementSteps(): array
    {
        return [
            'pending' => Settlement::query()->whereIn('settlement_status', ['pending', 'calculating'])->count(),
            'confirmed' => Settlement::query()->where('settlement_status', 'confirmed')->whereNull('payout_batch_id')->count(),
            'held' => Settlement::query()->payoutHeldByUnpaid()->count(),
            'secondary' => Settlement::query()->where('secondary_status', 'pending')->count(),
        ];
    }

    /** 채권 위험 표 — 위험·심각, 미수 큰 순. */
    public function riskTable(?array $sids = null, int $limit = 10): Collection
    {
        return $this->base($sids)->action('receivable_risk')
            ->with(['buyer:id,name', 'salesman:id,name', 'receivableManager:id,name'])
            ->orderByDesc('sale_unpaid_amount_krw_cache')->limit($limit)->get();
    }

    // ───────────────────────── 업무관리자 ─────────────────────────

    /** 이번 달 올릴 것 — 월정산 제출 준비 · 퇴사 정산 · 대표 결재 중. */
    public function submissionStatus(): array
    {
        $ym = now()->format('Y-m');
        $batch = SettlementPayoutBatch::monthly()->where('month', $ym)->orderByDesc('id')->first();

        return [
            'month' => $ym,
            'batch' => $batch,
            'eligible_count' => SettlementPayoutBatch::eligibleSettlementIds($ym)->count(),
            'unconfirmed_count' => Settlement::query()->whereIn('settlement_status', ['pending', 'calculating'])->count(),
            'held_count' => Settlement::query()->payoutHeldByUnpaid()->count(),
            'leavers' => Salesman::query()->immediateLeavers()->orderBy('left_at')->get(['id', 'name', 'left_at']),
            'awaiting' => SettlementPayoutBatch::query()->where('status', SettlementPayoutBatch::STATUS_PENDING)
                ->with('submitter:id,name')->orderBy('submitted_at')->get(),
            'last_approved' => SettlementPayoutBatch::monthly()->where('status', SettlementPayoutBatch::STATUS_APPROVED)->orderByDesc('decided_at')->first(),
        ];
    }

    /**
     * 오래 멈춘 차 — 기존 정체 큐 4개(통관 정체 30일 · 말소 기한 · 미수 stale(안 떠남) · ETA 없음)를 합쳐 오래된 순.
     * 🚫 새 N일 기준을 만들지 않는다(jin 2026-10-17). 같은 차가 두 큐에 걸리면 두 줄(멈춘 곳이 다르다).
     * 전량을 돌려준다 — 페이지는 호출자가 자른다(jin 10-17 «너무 많아지면 아래로 쭉 나열될 순 없잖아»).
     * 실측 ssancarerp 10-17 = 399줄 · 전량 로드 0.08초라 PHP 에서 잘라도 된다. $limit 은 옛 호출 호환(상위 N줄).
     *
     * @return Collection<int, array{vehicle: Vehicle, where: string, days: ?int}>
     */
    public function stuckVehicles(?array $sids = null, ?int $limit = null): Collection
    {
        $rows = collect();
        foreach (self::STUCK_QUEUES as $where => [$action, $dateCol, $scope]) {
            $rows = $rows->merge($this->stuckQuery($sids, $action, $scope)
                ->with(['salesman:id,name'])
                ->orderByRaw($dateCol.' IS NULL, '.$dateCol.' ASC')->get()
                ->map(fn (Vehicle $v) => ['vehicle' => $v, 'where' => $where, 'days' => $this->daysSince($v->{$dateCol})]));
        }

        $rows = $rows->unique(fn ($r) => $r['vehicle']->id.'|'.$r['where'])->sortByDesc('days')->values();

        return $limit === null ? $rows : $rows->take($limit)->values();
    }

    /** 오래 멈춘 차 대수 — 모델을 안 띄우고 센다(관리별 팀 표). 큐마다 「멈춘 곳」이 달라 합이 곧 줄 수다. */
    public function stuckCount(?array $sids = null): int
    {
        $n = 0;
        foreach (self::STUCK_QUEUES as [$action, $dateCol, $scope]) {
            $n += $this->stuckQuery($sids, $action, $scope)->count();
        }

        return $n;
    }

    private function stuckQuery(?array $sids, string $action, ?string $scope): Builder
    {
        return $this->base($sids)->action($action)->when($scope, fn ($q) => $q->{$scope}());
    }

    /**
     * 영업 Salesman.id → 담당 관리 이름 목록 (manager_salesman 피벗 ∪ 레거시 users.manager_user_id).
     *
     * @return array<int, string[]>
     */
    public function managerNamesBySalesman(): array
    {
        $pivot = DB::table('manager_salesman as ms')
            ->join('users as m', 'm.id', '=', 'ms.manager_user_id')
            ->join('salesmen as s', 's.user_id', '=', 'ms.salesman_user_id')
            ->select('s.id as salesman_id', 'm.name')->get();
        $legacy = DB::table('users as u')
            ->join('users as m', 'm.id', '=', 'u.manager_user_id')
            ->join('salesmen as s', 's.user_id', '=', 'u.id')
            ->select('s.id as salesman_id', 'm.name')->get();
        $map = [];
        foreach ($pivot->merge($legacy) as $r) {
            $map[(int) $r->salesman_id][] = $r->name;
        }

        return array_map(fn ($names) => array_values(array_unique($names)), $map);
    }

    /**
     * 관리별 팀 단위 미처리 — 관리 한 명이 담당하는 영업들의 서류·미수·미지급·멈춘 차.
     * 마지막 줄 = 어느 관리에게도 배정되지 않은 영업(「미배정」).
     */
    public function managerTeams(): array
    {
        $managers = User::query()->where('permission', 'user')->where('role', '관리')->orderBy('name')->get();
        $rows = [];
        $assigned = [];
        foreach ($managers as $m) {
            $sids = $m->ownSubordinateSalesmanIds();
            $assigned = array_merge($assigned, $sids);
            $rows[] = $this->teamRow($m->name, $sids, $m);
        }
        $unassigned = Salesman::query()->where('is_active', true)->sales()->whereNotIn('id', $assigned)->pluck('id')->all();
        if ($unassigned !== []) {
            $rows[] = $this->teamRow(null, $unassigned, null);
        }

        return $rows;
    }

    private function teamRow(?string $name, array $sids, ?User $manager): array
    {
        $docs = 0;
        foreach (['deregistration_needed', 'export_declaration_upload_needed', 'shipping_process_needed', 'bl_upload_needed'] as $key) {
            $docs += $this->base($sids)->action($key)->count();
        }
        $totals = $this->teamTotals($sids);

        return [
            'name' => $name,
            'manager' => $manager,
            'delegating' => $manager ? $manager->delegationsGiven()->effective()->exists() : false,
            'salesmen' => Salesman::query()->whereIn('id', $sids)->orderBy('name')->pluck('name')->all(),
            'docs' => $docs,
            'unpaid_krw' => $totals['unpaid_krw'],
            'risk_count' => $totals['risk_count'],
            'purchase_krw' => $totals['purchase_krw'],
            'held' => Settlement::query()->payoutHeldByUnpaid()->whereIn('salesman_id', $sids)->count(),
            'stuck' => $this->stuckCount($sids),
        ];
    }

    /** 보고용 숫자 — 업무관리자 화면 안에 둔다(관리자 대시보드는 대표만 본다, jin 2026-10-17 「전자」). */
    public function reportNumbers(): array
    {
        $money = $this->moneyOverview();
        $week = $this->weekCalendar(null);
        $latest = CashSnapshot::query()->orderByDesc('snapshot_date')->first();

        return [
            'receivable_krw' => $money['receivable_krw'], 'risk_count' => $money['risk_count'],
            'payable_krw' => $money['payable_krw'], 'payable_week_count' => $money['payable_week_count'],
            'ship_week' => $week['ship_total'], 'eta_week' => $week['eta_total'],
            'cash_as_of' => $latest?->snapshot_date?->format('Y-m-d'),
        ];
    }

    // ───────────────────────── 공통 ─────────────────────────

    public function daysSince($date): ?int
    {
        if (! $date) {
            return null;
        }
        $d = $date instanceof \DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);

        return (int) $d->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());
    }
}
