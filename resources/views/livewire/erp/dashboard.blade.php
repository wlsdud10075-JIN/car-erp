<?php

use App\Models\ApprovalRequest;
use App\Models\CashAccount;
use App\Models\CashAccountBalance;
use App\Models\CashSnapshot;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Services\CapitalStatusService;
use App\Services\CashAccountBalanceService;
use App\Services\Dashboard\WorkDashboardData;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    public int $selectedSalesmanId = 0;
    public int $perPage = 10;
    public const STUCK_PER_PAGE = 15;

    /** 업무관리자 뷰 「오래 멈춘 차」 현재 페이지 — Livewire 프로퍼티라 30초 폴링을 넘겨도 남는다. */
    public int $stuckPage = 1;

    /** 「멈춘 곳」 필터 칩 (jin 10-17) — '' = 전체, 그 외 WorkDashboardData::STUCK_QUEUES 키. 바꾸면 1페이지로. */
    public string $stuckWhere = '';
    // 역할 뷰 (jin 2026-10-17 역할별 새 모양 적용) — localStorage 연동.
    //   '영업' = 담당 영업(관리가 자기 담당 영업들의 돈을 묶어 본다 — 영업은 로그인하지 않는다)
    //   '수출통관' = 서류 칸반 + 이번 주 배 / '재무' = 받을 돈·줄 돈 + 정산 진행줄 + 통장잔액
    //   '관리' = 수출통관 + 재무 합본(「오늘 먼저」) / '업무관리자' = 제출·감독(결재는 대표 — admin·업무관리자만 연다)
    //   🔀 구 「담당자별/역할별」 모드 토글(viewMode)은 폐지 — 담당 영업 뷰가 그 둘을 합쳤다.
    public string $roleView = '영업';

    public const ROLE_VIEWS = ['영업', '수출통관', '재무', '관리'];

    public const SUPERVISOR_VIEW = '업무관리자';

    // 자금 현황 — 통장 마감잔액 입력 (재무·[관리]·업무관리자). 손익은 관리자 대시보드(대표) 전용.
    public string $cashDate = '';   // 기준일 (기본 오늘, 소급 입력 허용)
    public string $cashKrw = '';
    // 🔀 2026-10-14 (jin «이제 자판에서 +,- 기입할 수 있는데 토글로 할 필요가 있나») — ± 토글 폐지. 원화 칸은 data-money-signed 로 − 를 직접 친다.
    public string $cashUsd = '';
    public string $cashEur = '';
    public ?string $cashSavedAt = null;

    // 🏦 계좌별 잔액 (jin 2026-10-14 «싼카는 계좌가 많아서 드롭박스로 계좌를 선택하고 잔액을 기입») — 계좌가 1개 이상 등록되면
    //   아래 폼이 이 모드로 바뀐다. 0개면 종전 3칸 폼(heyman·karaba 가 배포 다음 날도 그대로 입력). 합계는 CashAccountBalanceService 가 스냅샷으로.
    public string $cashAccountId = '';
    /** @var array<string, string> 통화 → 금액 문자열(원화는 부호 포함) */
    // 🐛 2026-10-22 (heymanerp 외화 계좌에 원화가 같이 기록됨) — 빈 배열로 두면 저장 뒤 reset 이 [] 가 되어 칸의 경로가 사라지고,
    //   브라우저 칸에 남은 숫자가 다음 계좌 저장에 실려 갔다. 세 통화 키를 늘 빈 문자열로 들고 있게 한다.
    public array $cashAmounts = ['KRW' => '', 'USD' => '', 'EUR' => ''];
    public bool $showAddAccount = false;
    public string $newBank = '';
    public string $newAccountNo = '';
    public string $newLabel = '';
    public bool $newForeign = false;   // 등록할 때 「외화 계좌」 체크 (2026-10-22)
    // ✏️ 수정 중인 계좌(이 날짜 값) — null 이면 새로 넣기. 수정 중엔 비운 칸 = 그 날짜 값 삭제(jin 2026-10-22).
    public ?int $cashEditAccountId = null;
    // 🌐 외화 계좌에 원화 — 경고창이 떠 있나 / [그래도 기록] 을 눌렀나
    public bool $cashFxWarn = false;
    public bool $cashFxConfirmed = false;

    #[Computed]
    public function cashAccounts()
    {
        return CashAccount::active()->get();
    }

    /** 이 날짜에 적용되는 계좌별 값(이어쓴 값은 날짜가 다르다) — 화면 표. */
    #[Computed]
    public function cashAccountRows()
    {
        return app(CashAccountBalanceService::class)->rowsFor($this->cashDate ?: now()->toDateString());
    }

    public function updatedCashDate(): void
    {
        unset($this->cashAccountRows);
        $this->reset(['cashAmounts', 'cashEditAccountId', 'cashFxWarn', 'cashFxConfirmed']);   // 날짜가 바뀌면 수정 대상도 바뀐다
    }

    /** 계좌를 바꾸면 칸을 비운다 — 앞 계좌에 쓰던 숫자가 다음 계좌로 넘어가지 않게(2026-10-22). */
    public function updatedCashAccountId(): void
    {
        $this->reset(['cashAmounts', 'cashEditAccountId', 'cashFxWarn', 'cashFxConfirmed']);
    }

    public function saveAccountBalance(): void
    {
        $user = auth()->user();
        abort_unless($user->canEnterCashBalance(), 403);
        $account = CashAccount::active()->find((int) $this->cashAccountId);
        if (! $account) {
            $this->dispatch('notify', message: __('cash.account.pick_first'), type: 'error');

            return;
        }
        $date = $this->cashDate ?: now()->toDateString();
        if (\Illuminate\Support\Carbon::parse($date)->gt(now())) {
            $this->dispatch('notify', message: '미래 날짜는 입력할 수 없습니다.', type: 'error');

            return;
        }
        $editing = $this->cashEditAccountId === $account->id;
        $clean = fn ($v) => (float) str_replace([',', ' '], '', (string) $v);
        $amounts = [];
        $cleared = [];
        foreach (CashAccount::CURRENCIES as $cur) {   // 어느 계좌든 세 통화(jin 2026-10-14)
            $raw = trim((string) ($this->cashAmounts[$cur] ?? ''));
            if ($raw === '') {
                // 새로 넣을 때 = 비운 통화는 안 건드린다(이어쓰기). 수정 중 = 비우면 그 날짜 값을 지운다(jin 2026-10-22 «삭제하고 다시 기입»).
                if ($editing) {
                    $cleared[] = $cur;
                }

                continue;
            }
            // 원화는 칸에 친 부호 그대로(마이너스 통장 — data-money-signed, jin «이제 자판에서 +,- 기입할 수 있는데 토글로 할 필요가 있나»).
            //   외화는 종전대로 0 이상(마이너스 통장은 원화 계좌라, 외화 음수는 오타일 확률이 높다).
            $amounts[$cur] = $clean($raw);
        }
        if ($amounts === [] && $cleared === []) {
            $this->dispatch('notify', message: __('cash.account.amount_required'), type: 'error');

            return;
        }
        if (($amounts['USD'] ?? 0) < 0 || ($amounts['EUR'] ?? 0) < 0) {
            $this->dispatch('notify', message: '외화 잔액은 0 이상이어야 합니다.', type: 'error');

            return;
        }
        // 🌐 외화 계좌에 원화 — 막지 않고 한 번 묻는다(jin 2026-10-22). [그래도 기록] 이 다시 부르면 통과.
        if ($account->is_foreign && (float) ($amounts['KRW'] ?? 0) !== 0.0 && ! $this->cashFxConfirmed) {
            $this->cashFxWarn = true;

            return;
        }
        $this->cashFxWarn = false;
        $this->cashFxConfirmed = false;

        $service = app(CashAccountBalanceService::class);
        if ($cleared !== []) {
            $service->clearCurrencies($account, $date, $cleared, $user);
        }
        $amounts === [] ? $service->captureTotals($date, $user) : $service->save($account, $date, $amounts, $user, $editing);
        $this->cashSavedAt = $date;
        $this->reset(['cashAmounts', 'cashEditAccountId']);
        unset($this->cashAccountRows);
        $this->dispatch('notify', message: __($editing ? 'cash.account.edited' : 'cash.account.saved', ['date' => $date, 'account' => $account->display_name]), type: 'success');
    }

    /** 경고창 [그래도 기록] — 외화 계좌에 원화를 넣는 게 맞다고 확인. */
    public function confirmFxSave(): void
    {
        $this->cashFxConfirmed = true;
        $this->saveAccountBalance();
    }

    public function cancelFxSave(): void
    {
        $this->cashFxWarn = false;
        $this->cashFxConfirmed = false;
    }

    /** [수정] — 그 계좌의 이 날짜 값을 칸에 불러온다. 비우고 저장하면 그 통화는 지워진다(이어쓴 값은 그 날짜 것이라 안 건드린다). */
    public function editCashAccount(int $id): void
    {
        abort_unless(auth()->user()->canEnterCashBalance(), 403);
        $account = CashAccount::active()->findOrFail($id);
        $date = $this->cashDate ?: now()->toDateString();
        $this->cashAccountId = (string) $account->id;
        $this->cashEditAccountId = $account->id;
        $this->cashFxWarn = false;
        $this->cashFxConfirmed = false;
        $this->cashAmounts = ['KRW' => '', 'USD' => '', 'EUR' => ''];
        $rows = CashAccountBalance::where('cash_account_id', $account->id)->whereDate('snapshot_date', $date)->get();
        foreach ($rows as $b) {
            $this->cashAmounts[$b->currency] = $b->currency === 'KRW'
                ? number_format((float) $b->balance)
                : rtrim(rtrim(number_format((float) $b->balance, 2, '.', ''), '0'), '.');
        }
    }

    public function cancelCashEdit(): void
    {
        $this->reset(['cashAmounts', 'cashEditAccountId', 'cashFxWarn', 'cashFxConfirmed']);
    }

    /** [이 날짜 기록 삭제] — 그 계좌의 이 날짜 값 세 통화를 모두 지운다. 지운 뒤엔 앞 날짜 값을 이어 쓴다. */
    public function deleteCashEntry(): void
    {
        $user = auth()->user();
        abort_unless($user->canEnterCashBalance(), 403);
        $account = CashAccount::active()->findOrFail((int) $this->cashEditAccountId);
        $date = $this->cashDate ?: now()->toDateString();
        $service = app(CashAccountBalanceService::class);
        $service->clearCurrencies($account, $date, CashAccount::CURRENCIES, $user);
        $service->captureTotals($date, $user);
        $this->reset(['cashAmounts', 'cashEditAccountId', 'cashFxWarn', 'cashFxConfirmed']);
        unset($this->cashAccountRows);
        $this->dispatch('notify', message: __('cash.account.deleted', ['date' => $date, 'account' => $account->display_name]), type: 'success');
    }

    /** 수정 칸 안의 「외화 계좌」 체크 — 바로 저장·감사. */
    public function toggleCashForeign(): void
    {
        abort_unless(auth()->user()->canEnterCashBalance(), 403);
        $account = CashAccount::active()->findOrFail((int) $this->cashEditAccountId);
        $old = $account->is_foreign;
        $account->update(['is_foreign' => ! $old]);
        \App\Models\AuditLog::recordChange($account, 'is_foreign', $old, ! $old);
        unset($this->cashAccounts);
    }

    /** 「n행 추가」 — 계좌 등록. 잔액 입력 권한자면 누구나(jin «추천대로»). */
    public function addCashAccount(): void
    {
        abort_unless(auth()->user()->canEnterCashBalance(), 403);
        $this->validate([
            'newBank' => 'required|string|max:40',
            'newAccountNo' => 'required|string|max:60',
            'newLabel' => 'nullable|string|max:60',
        ], [], ['newBank' => __('cash.account.bank'), 'newAccountNo' => __('cash.account.number')]);
        $acc = CashAccount::create([
            'bank' => trim($this->newBank), 'account_no' => trim($this->newAccountNo), 'label' => trim($this->newLabel) ?: null,
            'is_foreign' => $this->newForeign,
            'currencies' => CashAccount::CURRENCIES,
            'sort' => (int) CashAccount::max('sort') + 1, 'is_active' => true,
        ]);
        \App\Models\AuditLog::recordChange($acc, 'cash_account', null, $acc->display_name);
        $this->reset(['newBank', 'newAccountNo', 'newLabel', 'newForeign', 'showAddAccount']);
        $this->cashAccountId = (string) $acc->id;
        unset($this->cashAccounts, $this->cashAccountRows);
        $this->dispatch('notify', message: __('cash.account.added'), type: 'success');
    }

    /** 계좌 숨김 — 지우지 않는다(잔액 이력이 달려 있다). 합계에서만 빠진다. */
    public function deactivateCashAccount(int $id): void
    {
        abort_unless(auth()->user()->canEnterCashBalance(), 403);
        $acc = CashAccount::findOrFail($id);
        $acc->update(['is_active' => false]);
        \App\Models\AuditLog::recordChange($acc, 'cash_account_active', true, false);
        if ($this->cashAccountId === (string) $id) {
            $this->cashAccountId = '';
        }
        $this->reset(['cashAmounts', 'cashEditAccountId', 'cashFxWarn', 'cashFxConfirmed']);
        unset($this->cashAccounts, $this->cashAccountRows);
    }

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && ! $user->isManager()) {
            $salesman = $user->salesman;
            if ($salesman) {
                $this->selectedSalesmanId = $salesman->id;
            }
            // 본인 role이 영업/통관/정산/관리면 그것을 초기 뷰로 (큐 14-2: '관리' 추가)
            if (in_array($user->role, self::ROLE_VIEWS, true)) {
                $this->roleView = $user->role;
            }
        } else {
            // 최고관리자·업무관리자의 첫 화면 = 업무관리자(제출·감독). localStorage 에 저장된 뷰가 있으면 그걸로 복원된다.
            $this->roleView = self::SUPERVISOR_VIEW;
        }

        // 자금 현황 입력 카드 — 입력칸은 항상 빈칸으로 시작 (jin 2026-07-24).
        // 최근 입력 날짜만 컨텍스트로 표시하고, 금액은 프리필하지 않음(매일 새 잔액 입력).
        if ($user->canEnterCashBalance()) {
            $this->cashDate = now()->toDateString();
            $latest = CashSnapshot::orderByDesc('snapshot_date')->first();
            $this->cashSavedAt = $latest?->snapshot_date->format('Y-m-d');
        }
    }

    /** 통장 마감잔액 저장 → 그 시점 재고·미수·미지급 자동 캡처 (하루 1건 upsert). */
    public function saveCashBalance(): void
    {
        $user = auth()->user();
        abort_unless($user->canEnterCashBalance(), 403);

        $clean = fn ($v) => (float) str_replace([',', ' '], '', (string) $v);
        // 원화는 칸에 친 부호 그대로(data-money-signed) — 2026-10-14 ± 토글 폐지.
        $krw = $clean($this->cashKrw);
        $usd = $clean($this->cashUsd);
        $eur = $clean($this->cashEur);
        // 🏦 원화는 **음수 허용** (jin 2026-08-12) — 마이너스 통장(한도대출)을 쓰면 잔액이 +/− 를 오간다.
        //    막아두면 그 날 0 을 넣거나 입력을 건너뛰게 되고, 둘 다 청산가치를 사용액만큼 부풀린다.
        //    음수는 청산가치·순자산에서 그대로 차감돼 회계적으로 맞다("접으면 갚아야 할 돈").
        //    ⚠️ 외화는 종전대로 0 이상 — 마이너스 통장은 원화 계좌라, 외화칸의 음수는 오타일 확률이 높다.
        if ($usd < 0 || $eur < 0) {
            $this->dispatch('notify', message: '외화 잔액은 0 이상이어야 합니다.', type: 'error');

            return;
        }

        // 기준일 — 기본 오늘, 미래일 금지 (소급 입력만 허용)
        $date = $this->cashDate ?: now()->toDateString();
        if (\Illuminate\Support\Carbon::parse($date)->gt(now())) {
            $this->dispatch('notify', message: '미래 날짜는 입력할 수 없습니다.', type: 'error');

            return;
        }

        app(CapitalStatusService::class)->capture(['krw' => $krw, 'usd' => $usd, 'eur' => $eur], $user, $date);
        $this->cashSavedAt = $date;
        // 저장 후 입력칸 비움 — 다음 입력을 빈칸에서 시작 (jin 2026-07-24). 날짜는 오늘로 리셋.
        $this->reset(['cashKrw', 'cashUsd', 'cashEur']);
        $this->cashDate = now()->toDateString();
        $this->dispatch('notify', message: $date.' 통장 잔액을 저장했습니다.', type: 'success');
    }


    /** 이 사용자가 열 수 있는 역할 뷰 — 최고관리자·업무관리자 = 5개 / 관리 = 4개 / 그 외 = 본인 role 하나. */
    public function allowedRoleViews(): array
    {
        $user = auth()->user();
        if ($user->isAdmin() || $user->isManager()) {
            return [...self::ROLE_VIEWS, self::SUPERVISOR_VIEW];
        }
        if ($user->role === '관리') {
            return self::ROLE_VIEWS;
        }

        return [in_array($user->role, self::ROLE_VIEWS, true) ? $user->role : '영업'];
    }

    // M2 보안: 비-admin·비-관리가 selectedSalesmanId 변경 시 본인 ID로 즉시 복귀.
    // 큐 14-2 — '관리' role도 서브관리자라 다른 담당자 시각 조회 허용 (업무 파악 의도).
    public function updatedSelectedSalesmanId(): void
    {
        $user = auth()->user();
        if (! $user->isAdmin() && ! $user->isManager() && $user->role !== '관리') {
            $this->selectedSalesmanId = $user->salesman?->id ?? 0;
        }
    }

    // M3 가드: 토글 권한 없는 user가 roleView 변경 시도 → 본인 role로 강제 복귀.
    public function updatedRoleView(): void
    {
        $allowed = $this->allowedRoleViews();
        if (! in_array($this->roleView, $allowed, true)) {
            // 토글 권한 없는 사용자는 본인 role 로, 권한자가 없는 값을 넣으면 첫 뷰로.
            $this->roleView = $allowed[0];
        }
    }

    // M2 SQL 강제: 담당자 필터는 영업 뷰에서만 의미 있음.
    // admin이 영업 뷰에서 담당자 N 선택 → 통관/정산 뷰로 전환 시 N 무시 (KPI/목록 정합성).
    private function effectiveSalesmanId(): ?int
    {
        if ($this->roleView !== '영업') {
            return null;
        }
        $user = auth()->user();

        // admin/super/업무관리자: selectedSalesmanId 자유 선택 (전체 영업)
        if ($user->isAdmin() || $user->isManager()) {
            return $this->selectedSalesmanId ?: null;
        }

        // 회의확장씬 #11 (2026-05-22) — [관리] 는 본인 담당 영업 중에서만 선택 가능.
        // UI 제한 (salesmen() select 옵션) + 보안 가드 (임의 ID 차단) 이중.
        if ($user->role === '관리') {
            if (! $this->selectedSalesmanId) {
                return null;
            }
            $allowed = $user->getSubordinateSalesmanIds();

            return in_array((int) $this->selectedSalesmanId, $allowed, true)
                ? (int) $this->selectedSalesmanId
                : null;   // 본인 담당 외 임의 ID → 빈 결과 (보안 가드)
        }

        return $user->salesman?->id;
    }

    /**
     * 큐·목록·스트립의 담당자 범위(Salesman.id 배열, null = 전체) — 담당 영업 뷰에서만 좁힌다.
     *   effectiveSalesmanId() 는 「고른 한 명」이고, 관리는 아무도 안 고르면 **담당 영업 전원**이 범위다(teamScope).
     */
    private function scopeIds(): ?array
    {
        return $this->roleView === '영업' ? $this->teamScope() : null;
    }

    // 회의확장씬 #7 (2026-05-22) — 실시간 환율 (네이버 marketindex 1h 캐시).
    // [관리]/일반사용자 대시보드 위젯 + 잔금N+ 자동 기입 데이터 소스.
    #[Computed]
    public function exchangeRates(): ?array
    {
        return app(ExchangeRateService::class)->getRates();
    }

    public function refreshExchangeRates(): void
    {
        app(ExchangeRateService::class)->refresh();
        unset($this->exchangeRates);
        $this->dispatch('notify', message: __('dashboard.fx_refreshed'), type: 'success');
    }

    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->sales()->orderBy('name');   // sales() = 검차직원 제외(월정산 v3)

        // 회의확장씬 #11 (2026-05-22) — [관리] 본인 담당 영업만 select 옵션 노출.
        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get(['id', 'name']);
    }

    #[Computed]
    public function kpis(): array
    {
        return match ($this->roleView) {
            '수출통관' => $this->buildClearanceKpis(),
            '재무' => $this->buildSettlementKpis(),
            '관리', self::SUPERVISOR_VIEW => $this->buildManagementKpis(),
            default => $this->buildSalesKpis(),
        };
    }

    #[Computed]
    public function actions(): array
    {
        return match ($this->roleView) {
            '수출통관' => $this->buildClearanceActions(),
            '재무' => $this->buildSettlementActions(),
            // 관리 = 수출통관 + 재무 겸직 → 두 큐를 「오늘 먼저」 한 줄로(긴급 먼저). 결재·감독 큐는 업무관리자 몫.
            '관리' => $this->buildManageTodayActions(),
            self::SUPERVISOR_VIEW => $this->buildManagementActions(),
            default => $this->buildSalesActions(),
        };
    }

    // ── 역할 뷰 데이터 (jin 2026-10-17) — 쿼리는 전부 WorkDashboardData, 여기선 범위만 정한다 ──

    /**
     * 담당 영업 뷰의 범위 = Salesman.id 배열. null = 전체.
     *   최고관리자·업무관리자 = 고른 담당자 또는 전체 / 관리 = 담당 영업(고르면 그중 하나) / 영업 = 본인.
     */
    public function teamScope(): ?array
    {
        $user = auth()->user();
        if ($user->isAdmin() || $user->isManager()) {
            return $this->selectedSalesmanId ? [$this->selectedSalesmanId] : null;
        }
        if ($user->role === '관리') {
            $team = $user->getSubordinateSalesmanIds();
            if ($this->selectedSalesmanId && in_array($this->selectedSalesmanId, $team, true)) {
                return [$this->selectedSalesmanId];
            }

            return $team;
        }

        return [$user->salesman?->id ?? 0];
    }

    /** 영업별 카드에 세울 담당자 — 범위 안의 활성 영업(검차 제외). */
    public function teamSalesmen()
    {
        $scope = $this->teamScope();

        return Salesman::query()->where('is_active', true)->sales()->orderBy('name')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->get(['id', 'name', 'type']);
    }

    private function data(): WorkDashboardData
    {
        return app(WorkDashboardData::class);
    }

    #[Computed] public function teamTotals(): array { return $this->data()->teamTotals($this->teamScope()); }
    #[Computed] public function salesmanCards(): array { return $this->data()->salesmanCards($this->teamSalesmen()); }
    #[Computed] public function blockedSales() { return $this->data()->blockedSales($this->teamScope(), $this->perPage); }
    #[Computed] public function purchaseDue() { return $this->data()->purchaseDue($this->teamScope(), $this->perPage); }
    #[Computed] public function nextStepCounts(): array { return $this->data()->nextStepCounts($this->teamScope()); }
    #[Computed] public function documentKanban(): array { return $this->data()->documentKanban(null); }
    #[Computed] public function weekCalendar(): array { return $this->data()->weekCalendar(null); }
    #[Computed] public function documentTable() { return $this->data()->documentTable(null, $this->perPage); }
    #[Computed] public function moneyOverview(): array { return $this->data()->moneyOverview(); }
    #[Computed] public function settlementSteps(): array { return $this->data()->settlementSteps(); }
    #[Computed] public function riskTable() { return $this->data()->riskTable(null, $this->perPage); }
    #[Computed] public function submissionStatus(): array { return $this->data()->submissionStatus(); }
    #[Computed] public function stuckVehicles() { return $this->data()->stuckVehicles(null); }

    /** 오래 멈춘 차 — 한 페이지 분량 + 자리 정보 (jin 10-17 «너무 많아지면 아래로 쭉 나열될 순 없잖아»). 폴링으로 줄어들면 마지막 페이지로 조인다. */
    #[Computed] public function stuckPaged(): array
    {
        $all = $this->stuckVehicles;
        $counts = $all->countBy('where')->all();   // 칩 숫자 — 목록과 같은 줄을 센다(stuckCount 와 같은 큐)
        $where = array_key_exists($this->stuckWhere, WorkDashboardData::STUCK_QUEUES) ? $this->stuckWhere : '';
        $rows = $where === '' ? $all : $all->where('where', $where)->values();
        $total = $rows->count();
        $pages = max(1, (int) ceil($total / self::STUCK_PER_PAGE));
        $page = min(max(1, $this->stuckPage), $pages);
        $from = ($page - 1) * self::STUCK_PER_PAGE;

        return ['rows' => $rows->slice($from, self::STUCK_PER_PAGE)->values(), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'from' => $total ? $from + 1 : 0, 'to' => min($total, $from + self::STUCK_PER_PAGE),
            'where' => $where, 'all' => $all->count(), 'counts' => $counts];
    }

    public function moveStuckPage(int $delta): void
    {
        $this->stuckPage = max(1, $this->stuckPaged['page'] + $delta);
        unset($this->stuckPaged);
    }

    /** 멈춘 곳 칩 — 같은 칩을 다시 누르면 전체로. 모르는 키는 전체. */
    public function setStuckWhere(string $where): void
    {
        $where = array_key_exists($where, WorkDashboardData::STUCK_QUEUES) ? $where : '';
        $this->stuckWhere = $where === $this->stuckWhere ? '' : $where;
        $this->stuckPage = 1;
        unset($this->stuckPaged);
    }
    #[Computed] public function managerNames(): array { return $this->data()->managerNamesBySalesman(); }
    #[Computed] public function managerTeams(): array { return $this->data()->managerTeams(); }
    #[Computed] public function reportNumbers(): array { return $this->data()->reportNumbers(); }

    /** 관리 뷰 「오늘 먼저」 — 수출통관·재무 큐 중 건수 있는 것을 긴급(빨강) 먼저, 같은 급은 건수 많은 순. */
    private function buildManageTodayActions(): array
    {
        $rows = array_merge($this->buildSettlementActions(), $this->buildClearanceActions());
        $rank = fn (array $r) => ($r['urgent'] ? 0 : 1).'|'.str_pad((string) (999999 - $r['count']), 6, '0', STR_PAD_LEFT);
        usort($rows, fn ($a, $b) => strcmp($rank($a), $rank($b)));

        return $rows;
    }

    /** 한 차량의 편집 패널로 가는 링크 — 알람함과 같은 방식(`?openVehicle=`). */
    public function vehicleLink(int $id): string
    {
        return route('erp.vehicles.index', ['openVehicle' => $id]);
    }

    /** 진행상태 뱃지 색 — SKILLS §10 매핑 단일 출처(이 화면 안). */
    public function progressBadge(?string $status): string
    {
        return match (true) {
            in_array($status, ['매입중', '매입완료', '말소완료'], true) => 'badge-blue',
            in_array($status, ['판매중', '판매완료'], true) => 'badge-purple',
            in_array($status, ['선적중', '선적완료', '수출통관중', '수출통관완료'], true) => 'badge-amber',
            in_array($status, ['통관중', '통관완료'], true) => 'badge-green',
            default => 'badge-gray',
        };
    }

    public function krw(int|float|null $v): string
    {
        return '₩'.\App\Support\Money::krw((int) ($v ?? 0));
    }

    #[Computed]
    public function activeVehicles()
    {
        $ids = $this->scopeIds();

        // 안건 J 본격 (2026-05-20) — v2/v3 호환. progress_status_cache 단일 출처.
        return Vehicle::query()
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->when($ids !== null, fn ($q) => $q->whereIn('salesman_id', $ids))
            ->with(['salesman', 'finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->orderBy('purchase_date', 'desc')
            ->limit($this->perPage)
            ->get();
    }

    // 큐 2번 — 파이프라인 카운트 스트립 (11단계).
    // 영업 뷰면 본인 salesman 한정, 통관/정산/admin은 전체.
    #[Computed]
    public function pipelineCounts(): array
    {
        $ids = $this->scopeIds();

        $counts = Vehicle::query()
            ->whereNull('deleted_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('salesman_id', $ids))
            ->where('cancel_status', Vehicle::CANCEL_NONE)   // 매입취소 제외 — 파이프라인 오염 방지 (jin 2026-07-18)
            ->selectRaw('progress_status_cache, COUNT(*) as cnt')
            ->groupBy('progress_status_cache')
            ->pluck('cnt', 'progress_status_cache')
            ->toArray();

        // 🚫 「매입중」을 넓히지 않는다 — 이 표는 **분포**다(어느 단계에 몇 대). 넓히면 한 차가
        //    「선적완료」이면서 「매입중」이 되어 단계 합이 총 대수를 넘는다(jin 2026-09-10 결정).
        //    차량관리 필터의 「매입중」은 더 넓다(매입 잔금이 남은 차 전부) — 그래서 이 숫자를 눌러
        //    들어가면 목록이 더 많이 나온다. 의도된 차이이고, 매입 미지급은 아래 할일 카드가 따로 센다.
        return $counts;
    }

    public function pipelineUrl(string $status): string
    {
        $params = ['progressFilter' => $status];
        $sid = $this->effectiveSalesmanId();
        if ($sid) {
            $params['salesmanId'] = $sid;
        }

        return route('erp.vehicles.index').'?'.http_build_query($params);
    }

    // ── 영업 role 빌더 ───────────────────────────────────
    private function buildSalesKpis(): array
    {
        $sid = $this->effectiveSalesmanId();
        $ms = now()->startOfMonth()->toDateString();
        $me = now()->endOfMonth()->toDateString();

        $base = Vehicle::query()->whereNull('deleted_at')
            ->when($sid, fn ($q) => $q->where('salesman_id', $sid));

        // 안건 J 본격 (2026-05-20) — v2/v3 호환. progress_status_cache 단일 출처.
        $active = (clone $base)->where(fn ($q) => $q
            ->where('progress_status_cache', '!=', '거래완료')
            ->orWhereNull('progress_status_cache'))->count();
        $monthBuy = (clone $base)->whereBetween('purchase_date', [$ms, $me])->count();
        $monthSale = (clone $base)->where('sale_price', '>', 0)->whereBetween('sale_date', [$ms, $me])->count();
        $monthDone = (clone $base)->where('progress_status_cache', '거래완료')->whereBetween('updated_at', [$ms.' 00:00:00', $me.' 23:59:59'])->count();
        $monthLabel = app()->getLocale() === 'en' ? now()->format('M') : now()->format('n').'월';
        $unit = __('dashboard.unit_vehicle');

        return [
            ['label' => __('dashboard.kpi.sales.active.l'),     'value' => $active,    'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.active.h')],
            ['label' => __('dashboard.kpi.sales.month_buy.l'),  'value' => $monthBuy,  'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_buy.h', ['month' => $monthLabel])],
            ['label' => __('dashboard.kpi.sales.month_sale.l'), 'value' => $monthSale, 'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_sale.h', ['month' => $monthLabel])],
            ['label' => __('dashboard.kpi.sales.month_done.l'), 'value' => $monthDone, 'suffix' => $unit, 'hint' => __('dashboard.kpi.sales.month_done.h', ['month' => $monthLabel])],
        ];
    }

    private function buildSalesActions(): array
    {
        $ids = $this->scopeIds();
        $base = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)
            ->when($ids !== null, fn ($q) => $q->whereIn('salesman_id', $ids));

        $pendingSettlements = Settlement::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('salesman_id', $ids))
            ->where('settlement_status', 'pending')
            ->count();

        // 매입취소 (jin 2026-07-18) — 취소완료·미수·마감 모두 카운트(같이 표시). 클릭 시 채권관리(취소필터).
        $cancelTotal = (int) Vehicle::query()->whereNull('deleted_at')
            ->where('cancel_status', '!=', Vehicle::CANCEL_NONE)
            ->when($ids !== null, fn ($q) => $q->whereIn('salesman_id', $ids))
            ->count();

        $t = fn (string $k, string $f) => __("dashboard.act.sales.$k.$f");

        return [
            $this->row($t('purchase_unpaid', 'l'),  $t('purchase_unpaid', 'd'),  $base('purchase_unpaid')->count(),  'bg-red-500',   'purchase_unpaid',  true),
            $this->row($t('sale_unpaid', 'l'),      $t('sale_unpaid', 'd'),      $base('sale_unpaid')->count(),      'bg-amber-500', 'sale_unpaid',      true),
            $this->row($t('clearance_needed', 'l'), $t('clearance_needed', 'd'), $base('clearance_needed')->count(), 'bg-blue-500',  'clearance_needed'),
            $this->row($t('shipping_needed', 'l'),  $t('shipping_needed', 'd'),  $base('shipping_needed')->count(),  'bg-green-500', 'shipping_needed'),
            // 운임/인코텀즈 확정 대기 (jin 2026-07-09) — 완납이지만 정산 게이트에 막힌 차량. amber(정보 누락).
            $this->row($t('freight_confirm', 'l'), $t('freight_confirm', 'd'), $base('freight_confirm_pending')->count(), 'bg-amber-500', 'freight_confirm_pending'),
            // DHL 할일 뱃지 제거 (jin 2026-07-06 — DHL 흐름 축소, 수동 입력만). scopeAction 'dhl_needed' 는 존치.
            // 정산 대기는 settlements 라우트로 직접 이동
            ['label' => $t('settlement_wait', 'l'), 'desc' => $t('settlement_wait', 'd'),
             'count' => $pendingSettlements, 'dot' => 'bg-violet-500', 'urgent' => false,
             'href' => route('erp.settlements.index').'?status=pending'],
            // 매입취소 → 채권관리(취소 필터)로. 취소완료·미수 모두 표시.
            ['label' => $t('cancel_unpaid', 'l'), 'desc' => $t('cancel_unpaid', 'd'),
             'count' => $cancelTotal, 'dot' => 'bg-rose-500', 'urgent' => false,
             'href' => route('erp.receivables.index').'?cancelFilter=cancelled&classification=cancelled_all'],
        ];
    }

    // ── 통관 role 빌더 ───────────────────────────────────
    private function buildClearanceKpis(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $unit = __('dashboard.unit_vehicle');
        $t = fn (string $k, string $f) => __("dashboard.kpi.clearance.$k.$f");

        return [
            ['label' => $t('derg_wait', 'l'),    'value' => $c('deregistration_needed'),            'suffix' => $unit, 'hint' => $t('derg_wait', 'h')],
            ['label' => $t('clr_req_wait', 'l'), 'value' => $c('clearance_request_needed'),         'suffix' => $unit, 'hint' => $t('clr_req_wait', 'h')],
            ['label' => $t('decl_wait', 'l'),    'value' => $c('export_declaration_upload_needed'), 'suffix' => $unit, 'hint' => $t('decl_wait', 'h')],
            ['label' => $t('ship_wait', 'l'),    'value' => $c('shipping_process_needed'),          'suffix' => $unit, 'hint' => $t('ship_wait', 'h')],
            ['label' => $t('dhl_wait', 'l'),     'value' => $c('dhl_dispatch_needed'),              'suffix' => $unit, 'hint' => $t('dhl_wait', 'h')],
        ];
    }

    private function buildClearanceActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.clearance.$k.$f");

        return [
            $this->row($t('deregistration_needed', 'l'),            $t('deregistration_needed', 'd'),            $c('deregistration_needed'),            'bg-red-500',   'deregistration_needed', true),
            $this->row($t('clearance_request_needed', 'l'),         $t('clearance_request_needed', 'd'),         $c('clearance_request_needed'),         'bg-blue-500',  'clearance_request_needed'),
            $this->row($t('clearance_info_missing', 'l'),           $t('clearance_info_missing', 'd'),           $c('clearance_info_missing'),           'bg-amber-500', 'clearance_info_missing', true),
            // 2026-06-18 데이터 보정 — 선적했는데 도착일(ETA) 미입력. 클릭 → 알림함(도착일 인라인 입력).
            $this->row($t('eta_missing', 'l'),                      $t('eta_missing', 'd'),                      $c('eta_missing'),                      'bg-amber-500', 'eta_missing', true, route('erp.alarms.index')),
            $this->row($t('forwarding_missing', 'l'),               $t('forwarding_missing', 'd'),               $c('forwarding_missing'),               'bg-amber-500', 'forwarding_missing', true),
            $this->row($t('export_declaration_upload_needed', 'l'), $t('export_declaration_upload_needed', 'd'), $c('export_declaration_upload_needed'), 'bg-blue-500',  'export_declaration_upload_needed'),
            $this->row($t('shipping_process_needed', 'l'),          $t('shipping_process_needed', 'd'),          $c('shipping_process_needed'),          'bg-amber-500', 'shipping_process_needed'),
            $this->row($t('bl_upload_needed', 'l'),                 $t('bl_upload_needed', 'd'),                 $c('bl_upload_needed'),                 'bg-green-500', 'bl_upload_needed'),
            $this->row($t('dhl_dispatch_needed', 'l'),              $t('dhl_dispatch_needed', 'd'),              $c('dhl_dispatch_needed'),              'bg-teal-500',  'dhl_dispatch_needed'),
        ];
    }

    // ── 정산 role 빌더 ───────────────────────────────────
    private function buildSettlementKpis(): array
    {
        // M4 — "판매 미입금 총액"은 환율 입력 차량만 합산 (KRW 캐시 NOT NULL).
        //       환율 미입력 외화 차량은 별도 KPI "환율 미입력 외화"에서만 카운트.
        // 안건 J 본격 (2026-05-20) — v2/v3 호환.
        $totalSaleUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('sale_price', '>', 0)
            ->whereNotNull('sale_unpaid_amount_krw_cache')
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            // 결제대기(grace) 제외 — 판매일+10일 미경과 선적전 미수는 아직 채권 아님 (jin 2026-07-06).
            ->excludeReceivableGrace()
            ->sum('sale_unpaid_amount_krw_cache') ?? 0);

        $today = now()->toDateString();
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP 후 단순화.
        $totalPurchaseUnpaid = (int) (Vehicle::query()
            ->whereNull('deleted_at')
            // 🔀 **진행 중만 센다** (jin 2026-09-14). 바로 아래 할일 「매입 미지급」은 거래완료를 빼는데
            //    이 금액만 포함해서 **같은 화면에서 모집단이 달랐다**(실측 39대 vs 37대 규모).
            //    화면 문구가 이미 「진행중 매입 잔금 합계」라고 말하고 있어 금액 쪽을 맞췄다.
            //    🚫 조건을 옮겨 적지 말 것 — `scopeAction('purchase_unpaid')` 와 같은 active 한정이다.
            ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('purchase_price', '>', 0)
            // CAST AS SIGNED — BIGINT UNSIGNED 빼기 결과가 음수면 underflow. WHERE/SELECT 양쪽 적용.
            // Review.md #6 (2026-06-09) — confirmed_at IS NOT NULL 필터 추가.
            // 누락 시 미확정 Draft PBP(매입가 입력 시 자동생성, payment_date=매입일)가 집계를 깎아
            // 카드 카운트(scopeAction·accessor=confirmed만)와 KPI 금액 모집단이 불일치했음.
            ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0)) > 0', [$today])
            ->selectRaw('SUM(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0)) as total', [$today])
            ->value('total') ?? 0);

        $pendingSettlements = Settlement::query()->where('settlement_status', 'pending')->count();
        $exchangeMissing = Vehicle::query()->whereNull('deleted_at')->action('exchange_rate_missing')->count();

        // 2026-05-20 #2 피드백 — 거래완료지만 미수금 남은 차량 (정산 진행 차단 상태).
        $settlementBlockedQuery = Vehicle::query()->whereNull('deleted_at')->action('settlement_blocked_by_unpaid');
        $settlementBlockedCount = (clone $settlementBlockedQuery)->count();
        $settlementBlockedAmount = (int) ((clone $settlementBlockedQuery)->sum('sale_unpaid_amount_krw_cache') ?? 0);

        // 매입취소 (jin 2026-07-18) — 취소완료·미수·마감 모두. 정산차단 카드 옆 KPI 로 항상 표시.
        $cancelTotal = (int) Vehicle::query()->whereNull('deleted_at')->where('cancel_status', '!=', Vehicle::CANCEL_NONE)->count();

        $t = fn (string $k, string $f) => __("dashboard.kpi.settlement.$k.$f");

        return [
            ['label' => $t('pur_unpaid', 'l'),  'value' => $this->formatKrw($totalPurchaseUnpaid), 'suffix' => '',                            'hint' => $t('pur_unpaid', 'h')],
            ['label' => $t('sale_unpaid', 'l'), 'value' => $this->formatKrw($totalSaleUnpaid),     'suffix' => '',                            'hint' => $t('sale_unpaid', 'h')],
            ['label' => $t('wait', 'l'),        'value' => $pendingSettlements,                    'suffix' => __('dashboard.unit_count'),    'hint' => $t('wait', 'h')],
            ['label' => $t('fx_missing', 'l'),  'value' => $exchangeMissing,                       'suffix' => __('dashboard.unit_vehicle'),  'hint' => $t('fx_missing', 'h')],
            // 2026-05-20 #2 피드백 — 거래완료 미수금 차량 (정산 진행 차단)
            ['label' => $t('blocked', 'l'),     'value' => $settlementBlockedCount, 'suffix' => __('dashboard.unit_vehicle'),
             'hint' => __('dashboard.kpi.settlement.blocked.h', ['amount' => $this->formatKrw($settlementBlockedAmount)])],
            // 매입취소 KPI (취소완료·미수 모두) — 정산차단 옆 상시 표시
            ['label' => $t('cancel', 'l'),      'value' => $cancelTotal,            'suffix' => __('dashboard.unit_vehicle'),
             'hint' => $t('cancel', 'h')],
        ];
    }

    private function buildSettlementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.settlement.$k.$f");

        // 지급 게이트 (jin 2026-07-08) — 미수로 지급보류된 확정 정산. 클릭 시 정산처리 ?held=1 딥링크.
        $heldCount = Settlement::query()->payoutHeldByUnpaid()->count();
        // 2차 정산 마감 대기 (jin 2026-07-26) — paid 후 secondary_status=pending. 비용·환차 정정 후 마감.
        $secondaryPending = Settlement::query()->where('secondary_status', 'pending')->count();

        return [
            $this->row($t('purchase_unpaid', 'l'),           $t('purchase_unpaid', 'd'),           $c('purchase_unpaid'),           'bg-red-500',    'purchase_unpaid',           true),
            $this->row($t('sale_unpaid', 'l'),               $t('sale_unpaid', 'd'),               $c('sale_unpaid'),               'bg-amber-500',  'sale_unpaid',               true),
            $this->row($t('exchange_rate_missing', 'l'),     $t('exchange_rate_missing', 'd'),     $c('exchange_rate_missing'),     'bg-red-500',    'exchange_rate_missing',     true),
            // 운임(인코텀즈) 미확정 = 정산 생성 게이트. 재무가 "왜 정산이 안 생기지"를 여기서 인지 (영업 role 과 동일 출처).
            $this->row($t('freight_confirm', 'l'),           $t('freight_confirm', 'd'),           $c('freight_confirm_pending'),   'bg-amber-500',  'freight_confirm_pending'),
            $this->row($t('settlement_create_needed', 'l'),  $t('settlement_create_needed', 'd'),  $c('settlement_create_needed'),  'bg-blue-500',   'settlement_create_needed'),
            $this->row($t('settlement_confirm_needed', 'l'), $t('settlement_confirm_needed', 'd'), $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row($t('settlement_pay_needed', 'l'),     $t('settlement_pay_needed', 'd'),     $c('settlement_pay_needed'),     'bg-violet-500', 'settlement_pay_needed'),
            $this->row($t('payout_held', 'l'),               $t('payout_held', 'd'),               $heldCount,                      'bg-red-500',    '',                          true, route('erp.settlements.index').'?held=1'),
            $this->row($t('secondary_close', 'l'),           $t('secondary_close', 'd'),           $secondaryPending,               'bg-violet-500', '',                          false, route('erp.settlements.index').'?secondary=pending'),
            $this->row($t('receivable_risk', 'l'),           $t('receivable_risk', 'd'),           $c('receivable_risk'),           'bg-red-500',    'receivable_risk',           true),
            // 매입취소 미수 (jin 2026-07-18) — 위약금 미수령 취소건(미수 마감/손실 처리). 채권관리(취소필터)로.
            ['label' => $t('cancel_unpaid', 'l'), 'desc' => $t('cancel_unpaid', 'd'),
             'count' => (int) Vehicle::query()->whereNull('deleted_at')->where('cancel_status', '!=', Vehicle::CANCEL_NONE)->count(),
             'dot' => 'bg-rose-500', 'urgent' => false,
             'href' => route('erp.receivables.index').'?cancelFilter=cancelled&classification=cancelled_all'],
        ];
    }

    // ── 관리 role 빌더 (큐 14-2) ─────────────────────────
    // 서브관리자 시각 — 승인 대기·정산 대기·채권 위험·정체 차량 모니터링.
    // 회의록 2026-05-14-management-role-dashboard.md §2 4 KPI 합의안.
    private function buildManagementKpis(): array
    {
        // 승인 대기 = 차량간이체·보증금 선지급·미수 우회 등 (/erp/approvals 와 동일 출처).
        // 🔀 **폐기된 승인 유형을 빼고 센다** (jin 2026-09-14) — 승인 화면·사이드바가 쓰는 것과 같은
        //    `actionable()` 단일 출처다. 안 쓰면 카드만 폐기 유형까지 세어 숫자가 크게 나온다.
        $pendingApprovals = ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)->count();

        $pendingSettlements = Settlement::query()
            ->whereIn('settlement_status', ['pending', 'confirmed'])
            ->count();

        $riskCount = Vehicle::query()->whereNull('deleted_at')
            ->whereIn('receivable_risk', ['danger', 'critical'])
            ->count();

        $stuckDate = now()->subDays(30)->toDateString();
        // 안건 J 본격 (2026-05-20) — v2/v3 호환.
        $stuckCount = Vehicle::query()->whereNull('deleted_at')
            ->where(fn ($q) => $q
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('sale_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('sale_unpaid_amount_krw_cache')
                ->orWhere('sale_unpaid_amount_krw_cache', '<=', 0))
            ->whereNull('export_declaration_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '<=', $stuckDate)
            ->count();

        $cnt = __('dashboard.unit_count');
        $unit = __('dashboard.unit_vehicle');
        $t = fn (string $k, string $f) => __("dashboard.kpi.management.$k.$f");

        return [
            ['label' => $t('appr_wait', 'l'),   'value' => $pendingApprovals,   'suffix' => $cnt,  'hint' => $t('appr_wait', 'h')],
            ['label' => $t('settle_wait', 'l'), 'value' => $pendingSettlements, 'suffix' => $cnt,  'hint' => $t('settle_wait', 'h')],
            ['label' => $t('risk', 'l'),        'value' => $riskCount,          'suffix' => $unit, 'hint' => $t('risk', 'h')],
            ['label' => $t('clr_stuck', 'l'),   'value' => $stuckCount,         'suffix' => $unit, 'hint' => $t('clr_stuck', 'h')],
        ];
    }

    private function buildManagementActions(): array
    {
        $c = fn (string $a) => Vehicle::query()->whereNull('deleted_at')->action($a)->count();

        $t = fn (string $k, string $f) => __("dashboard.act.management.$k.$f");

        // 🔀 **폐기된 승인 유형을 빼고 센다** (jin 2026-09-14) — 승인 화면·사이드바가 쓰는 것과 같은
        //    `actionable()` 단일 출처다. 안 쓰면 카드만 폐기 유형까지 세어 숫자가 크게 나온다.
        $pendingApprovals = ApprovalRequest::actionable()->where('status', ApprovalRequest::STATUS_PENDING)->count();
        // 재고 감독 (jin 2026-07-26) — 관리/업무관리자 몫. 일반재고(투기·장기) 건수 신호 + 재고관리 딥링크.
        $generalStock = Vehicle::query()->generalStock()->count();

        return [
            // 승인 대기 → /erp/approvals (차량간이체·보증금 선지급·미수 우회 승인). 카운트 = 승인화면 단일출처.
            $this->row($t('approval_wait', 'l'), $t('approval_wait', 'd'), $pendingApprovals, 'bg-violet-500', '', $pendingApprovals > 0, route('erp.approvals.index')),
            $this->row($t('settlement_confirm_needed', 'l'), $t('settlement_confirm_needed', 'd'), $c('settlement_confirm_needed'), 'bg-violet-500', 'settlement_confirm_needed'),
            $this->row($t('settlement_pay_needed', 'l'),     $t('settlement_pay_needed', 'd'),     $c('settlement_pay_needed'),     'bg-violet-500', 'settlement_pay_needed'),
            $this->row($t('receivable_risk', 'l'),           $t('receivable_risk', 'd'),           $c('receivable_risk'),           'bg-red-500',    'receivable_risk', true),
            $this->row($t('inventory', 'l'),                 $t('inventory', 'd'),                 $generalStock,                   'bg-amber-500',  '',                false, route('erp.inventory.index').'?category=general'),
            $this->row($t('clearance_stuck', 'l'),           $t('clearance_stuck', 'd'),           $c('clearance_stuck'),           'bg-amber-500',  'clearance_stuck', true),
        ];
    }

    private function row(string $label, string $desc, int $count, string $dot, string $action, bool $urgent = false, ?string $href = null): array
    {
        return [
            'label' => $label, 'desc' => $desc, 'count' => $count,
            'dot' => $dot, 'urgent' => $urgent,
            'href' => $href ?? $this->vehiclesUrl($action),
        ];
    }

    private function vehiclesUrl(string $action): string
    {
        $url = route('erp.vehicles.index').'?action='.$action;
        $sid = $this->effectiveSalesmanId();
        if ($sid) {
            $url .= '&salesmanId='.$sid;
        }

        return $url;
    }

    private function formatKrw(int $amount): string
    {
        // 로케일별 축약 (ko: 억/만 만단위까지 정확 / en: K/M/B). 공통 헬퍼 단일 출처.
        return '₩'.\App\Support\Money::krw($amount);
    }
}; ?>

{{-- UX #6 (2026-05-20) — wire:poll.30s — 사이드바 뱃지 + 페이지 데이터 30초 자동 갱신. 본인 액션은 Livewire 자체 re-render 로 즉시 반영. --}}
{{-- 🔀 2026-10-17 (jin 「적용해줘」) — 역할별 새 모양. 뷰마다 묻는 질문이 다르다:
     담당 영업 = 내 담당 영업들의 돈이 어디서 막혔나 / 수출통관 = 서류가 어디서 멈췄나·이번 주 배 / 재무 = 받을 돈·줄 돈·정산 진행
     관리 = 서류와 돈 중 오늘 먼저 할 것(두 큐 합본) / 업무관리자 = 이번 달 올릴 것·오래 멈춘 차·어느 팀이 밀렸나.
     목업 = docs/design/mock/2026-10-16/work-dashboard-role-*.html. 데이터 = App\Services\Dashboard\WorkDashboardData. --}}
<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6" x-data="{
    roleView: @entangle('roleView').live,
    allowed: @js($this->allowedRoleViews()),
    initView() {
        if (this.allowed.length < 2) return;
        const saved = localStorage.getItem('erp_dashboard_role_view');
        if (saved && this.allowed.includes(saved)) {
            this.roleView = saved;
        }
    },
    setView(v) {
        if (! this.allowed.includes(v)) return;
        this.roleView = v;
        localStorage.setItem('erp_dashboard_role_view', v);
    }
}" x-init="initView()">

@php
    $user = auth()->user();
    $allowedViews = $this->allowedRoleViews();
    $canToggleView = count($allowedViews) > 1;
    $viewLabel = __('dashboard.view_label.'.$roleView);
    $viewBadge = match($roleView) {
        '수출통관' => 'badge-amber',
        '재무' => 'badge-green',
        default => 'badge-purple',
    };
    $viewSub = match($roleView) {
        '영업' => __('dashboard.view_sub.영업', ['n' => $this->teamSalesmen()->count()]),
        '관리' => __('dashboard.view_sub.관리'),
        '업무관리자' => __('dashboard.view_sub.업무관리자'),
        default => null,
    };
    $salesmanMissing = $user->role === '영업' && ! $user->isAdmin() && ! $user->isManager() && ! $user->salesman;
    $showSalesmanPicker = $roleView === '영업' && ($user->isAdmin() || $user->isManager() || $user->role === '관리');
    $showFx = in_array($roleView, ['영업', '재무', '관리'], true);
@endphp

{{-- 헤더 --}}
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ $viewLabel }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">
            <span class="badge {{ $viewBadge }}">{{ __('dashboard.view_pill.'.$roleView) }}</span>
            @if($viewSub) · {{ $viewSub }} @endif
            · {{ app()->getLocale() === 'en' ? now()->translatedFormat('M j, Y') : now()->format('Y년 m월 d일') }}
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        {{-- 역할 뷰 pill — 최고관리자·업무관리자 5개 / 관리 4개 / 그 외 없음 --}}
        @if($canToggleView)
        <div class="flex flex-wrap items-center gap-1" data-role-pills>
            @foreach($allowedViews as $v)
            <button type="button"
                @click="setView('{{ $v }}')"
                :class="roleView === '{{ $v }}' ? 'tab-pill is-active' : 'tab-pill'"
                class="px-3 py-1 text-xs">{{ __('dashboard.view_pill.'.$v) }}</button>
            @endforeach
        </div>
        @endif
    </div>
</div>

{{-- 담당자 고르기 — 담당 영업 뷰에서만. 관리는 본인 담당 영업만 목록에 나온다(salesmen computed). --}}
@if($showSalesmanPicker)
<div class="flex flex-wrap items-center gap-2" data-salesman-picker>
    <span class="text-xs text-gray-500">{{ __('dashboard.salesman') }}</span>
    @if($this->salesmen->count() <= 6)
    <button type="button" wire:click="$set('selectedSalesmanId', 0)" class="tab-pill px-3 py-1 text-xs {{ $selectedSalesmanId === 0 ? 'is-active' : '' }}">{{ __('dashboard.all') }}</button>
    @foreach($this->salesmen as $sm)
    <button type="button" wire:click="$set('selectedSalesmanId', {{ $sm->id }})" class="tab-pill px-3 py-1 text-xs {{ $selectedSalesmanId === $sm->id ? 'is-active' : '' }}">{{ $sm->name }}</button>
    @endforeach
    @else
    <select wire:model.live="selectedSalesmanId" class="input-filter">
        <option value="0">{{ __('dashboard.all') }}</option>
        @foreach($this->salesmen as $sm)
        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    @endif
</div>
@endif

@if($salesmanMissing)
{{-- S4 영업 user salesman 미연결 empty state --}}
<div class="card flex flex-col items-center justify-center py-12 text-center">
    <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
        <svg class="h-7 w-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
    </div>
    <p class="text-base font-semibold text-gray-800">{{ __('dashboard.salesman_missing_title') }}</p>
    <p class="mt-1 text-sm text-gray-500">{{ __('dashboard.salesman_missing_sub') }}</p>
</div>
@else

{{-- 회의확장씬 #7 (2026-05-22) — 실시간 환율 (KRW 기준, 1h 캐시, 네이버 marketindex). 10-17 부터 카드 대신 한 줄, 돈을 다루는 뷰에서만. --}}
@if($showFx)
@php $exchangeRates = $this->exchangeRates; @endphp
@if($exchangeRates !== null)
<div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600" data-fx-line>
    <span class="font-medium text-gray-700">{{ __('dashboard.fx_title') }}</span>
    @foreach(['USD','JPY','EUR','GBP','CNY'] as $cur)
    <span>{{ $cur }}@if($cur === 'JPY')<span class="text-[10px]">(100)</span>@endif <b class="text-gray-800">{{ isset($exchangeRates[$cur]) ? '₩'.number_format($exchangeRates[$cur], 2) : '—' }}</b></span>
    @endforeach
    <span class="text-gray-400">{{ __('dashboard.fx_sub') }}</span>
    <button wire:click="refreshExchangeRates" class="text-violet-600 hover:underline">{{ __('dashboard.fx_refresh') }}</button>
</div>
@else
<div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ __('dashboard.fx_fail') }} <button wire:click="refreshExchangeRates" class="text-violet-600 hover:underline">{{ __('dashboard.fx_refresh') }}</button></div>
@endif
@endif

{{-- 역할 뷰 본문 --}}
@switch($roleView)
    @case('수출통관')
        @include('erp.dashboard.clearance')
        @break
    @case('재무')
        @include('erp.dashboard.finance')
        @break
    @case('관리')
        @include('erp.dashboard.manage')
        @break
    @case('업무관리자')
        @include('erp.dashboard.supervisor')
        @break
    @default
        @include('erp.dashboard.team')
@endswitch

{{-- 처리 필요 항목 — 큐 목록(모든 뷰 공통). 관리 뷰에서는 수출통관+재무 큐를 긴급순으로 합친 「오늘 먼저」다. --}}
<div class="card" data-actions>
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-700">{{ $roleView === '관리' ? __('dashboard.v.manage.today.title') : __('dashboard.actions_title') }}</h2>
        @if($roleView === '관리')<span class="text-xs text-gray-500">{{ __('dashboard.v.manage.today.sub') }}</span>@endif
    </div>
    @php
        $totalActions = collect($this->actions)->sum('count');
        $emptyKey = in_array($roleView, ['수출통관','재무'], true) ? $roleView : 'default';
        $emptyMessage = ['title' => __("dashboard.empty.$emptyKey.title"), 'sub' => __("dashboard.empty.$emptyKey.sub")];
    @endphp
    @if($totalActions === 0)
    <div class="flex flex-col items-center justify-center py-8 text-center">
        <div class="mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
            <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <p class="text-sm font-medium text-green-700">{{ $emptyMessage['title'] }}</p>
        <p class="mt-0.5 text-xs text-gray-400">{{ $emptyMessage['sub'] }}</p>
    </div>
    @else
    <div class="divide-y divide-gray-100">
        @foreach($this->actions as $action)
        @if($action['count'] > 0)
        <a href="{{ $action['href'] }}" wire:navigate
           class="-mx-4 flex items-center gap-3 px-4 py-3 transition first:-mt-1 last:-mb-1 hover:bg-gray-50">
            <span class="h-2.5 w-2.5 flex-shrink-0 rounded-full {{ $action['dot'] }}"></span>
            <div class="min-w-0 flex-1">
                <span class="text-sm font-medium text-gray-800">{{ $action['label'] }}</span>
                <span class="ml-2 hidden text-xs text-gray-400 sm:inline">{{ $action['desc'] }}</span>
            </div>
            <span class="flex-shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold
                         {{ $action['urgent'] ? 'bg-red-100 text-red-700' : 'text-gray-700' }}">
                {{ $action['count'] }}{{ __('dashboard.unit_count') }}
            </span>
            <svg class="h-4 w-4 flex-shrink-0 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        @endif
        @endforeach
    </div>
    @endif
</div>

{{-- 큐 2번 — 10단계 파이프라인 카운트 스트립. 분포표라 맨 아래(관리자 대시보드와 같은 것). --}}
@php
    $stripSubtitle = match(true) {
        $roleView === '영업' && ($user->isAdmin() || $user->isManager()) && $this->selectedSalesmanId
            => __('dashboard.subtitle_only', ['name' => $this->salesmen->firstWhere('id', $this->selectedSalesmanId)?->name ?? __('dashboard.salesman')]),
        $roleView === '영업' && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업'
            => $user->salesman?->name ? __('dashboard.subtitle_only', ['name' => $user->salesman->name]) : null,
        $roleView === '영업' && $user->role === '관리'
            => __('dashboard.view_sub.영업', ['n' => $this->teamSalesmen()->count()]),
        default => __('dashboard.subtitle_all'),
    };
@endphp
<x-erp.pipeline-strip
    :counts="$this->pipelineCounts"
    :url-builder="fn (string $s) => $this->pipelineUrl($s)"
    :subtitle="$stripSubtitle" />

@endif

</div>
</div>
