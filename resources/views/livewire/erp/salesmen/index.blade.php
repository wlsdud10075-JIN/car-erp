<?php

use App\Models\ExternalSettlementPay;
use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\User;
use App\Services\Payroll\PayrollStatementImporter;
use App\Support\SearchTerm;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    // ── 📄 급여대장 업로드 (jin 2026-10-14 «엑셀 업로드를 통해 월급명세서를 올리면 해당인원에 맞게 기입») ──
    //   xls 를 읽어(PayrollStatementImporter) 미리보기 → 사람이 미매칭을 연결 → 「기입」. 그 달·그 사람만 덮어쓴다(다른 달 보존).
    //   ⚠️ 프로퍼티 이름과 메서드 이름을 같게 두지 말 것(§8 #32) — payrollFile / parsePayrollFile.
    public $payrollFile = null;
    public bool $showImport = false;
    /** @var array{month:?string, people:list<array>, errors:list<string>}|array{} */
    public array $importPreview = [];
    /** @var array<int, string> 급여대장 행번호 → salesman_id('' = 미매칭) */
    public array $importMatches = [];
    /** 판매수당 대조 기준(그 달 사내직원별 정산금 쪽 합 · 연금 토글) — 파일을 읽을 때 한 번만 계산(드롭다운을 바꿀 때마다 한 달 배치를 다시 돌지 않게). */
    public array $commissionBase = [];

    public function openImport(): void
    {
        abort_unless(auth()->user()?->canEditPayroll(), 403);
        $this->reset(['payrollFile', 'importPreview', 'importMatches', 'commissionBase']);
        $this->showImport = true;
    }

    public function closeImport(): void
    {
        $this->reset(['payrollFile', 'importPreview', 'importMatches', 'showImport', 'commissionBase']);
    }

    public function updatedPayrollFile(): void
    {
        abort_unless(auth()->user()?->canEditPayroll(), 403);
        $this->validate(['payrollFile' => 'required|file|mimes:xls,xlsx|max:10240'], [], ['payrollFile' => __('salesman.import.file')]);
        $importer = new PayrollStatementImporter;
        $this->importPreview = $importer->parse($this->payrollFile->getRealPath());
        $this->importMatches = [];
        $month = $this->importPreview['month'] ?? null;
        $this->commissionBase = $month && PayrollEntry::isMonth($month) && ($this->importPreview['people'] ?? []) !== []
            ? \App\Services\Payout\PersonPayoutBreakdown::commissionBaseFor($month) : [];
        foreach ($importer->match($this->importPreview['people']) as $row => $id) {
            $this->importMatches[$row] = $id === null ? '' : (string) $id;
        }
    }

    /**
     * 🔎 판매수당 대조 (jin 2026-10-21 «급여대장 업로드하면 … 급여대장과 매칭해서 실수가 확인되게»).
     * 급여대장 행번호 => [sheet, expected, diff, pension_on] — 연결된 사내직원 중 급여대장에 판매수당 줄이 있는 사람만.
     * 계산은 PersonPayoutBreakdown::commissionCheckFor 한 곳(월정산 카드와 같은 식).
     */
    #[Computed]
    public function importCommissionCheck(): array
    {
        $month = $this->importPreview['month'] ?? null;
        if (! $month || ! PayrollEntry::isMonth($month)) {
            return [];
        }
        $sheets = [];
        $rowOf = [];
        foreach ($this->importPreview['people'] ?? [] as $p) {
            $id = (int) ($this->importMatches[$p['row']] ?? 0);
            if ($id === 0 || ! $p['ok']) {
                continue;
            }
            $sheets[$id] = $p['items'] ?? [];
            $rowOf[$id] = $p['row'];
        }
        $out = [];
        foreach (\App\Services\Payout\PersonPayoutBreakdown::commissionCheckWith($this->commissionBase, $sheets) as $id => $c) {
            $out[$rowOf[$id]] = $c;
        }

        return $out;
    }

    /** 미매칭을 연결할 후보 — 활성 인원 전부(유형 무관). 배열로 담는다(모델이면 페이로드가 커진다, §8 #96-C). */
    #[Computed]
    public function importCandidates(): array
    {
        return Salesman::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])->all();
    }

    public function applyImport(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->canEditPayroll(), 403);
        $month = $this->importPreview['month'] ?? null;
        if (! $month || ! PayrollEntry::isMonth($month) || ($this->importPreview['errors'] ?? []) !== []) {
            $this->dispatch('notify', message: __('salesman.import.not_ready'), type: 'error');

            return;
        }
        // 같은 사람에게 두 줄이 붙으면 멈춘다 — 나중 줄이 앞 줄을 조용히 덮는다.
        $ids = array_filter(array_map('intval', $this->importMatches));
        if (count($ids) !== count(array_unique($ids))) {
            $this->dispatch('notify', message: __('salesman.import.duplicate'), type: 'error');

            return;
        }
        $done = 0;
        $skipped = 0;
        foreach ($this->importPreview['people'] as $p) {
            $id = (int) ($this->importMatches[$p['row']] ?? 0);
            if (! $p['ok'] || $id === 0) {
                $skipped++;

                continue;
            }
            $sm = Salesman::find($id);
            if (! $sm) {
                $skipped++;

                continue;
            }
            // 사원번호를 명부에 박아 다음 달부터 이름이 달라도 자동 매칭(jin #3).
            if ($sm->employee_no !== $p['employee_no']) {
                $sm->update(['employee_no' => $p['employee_no']]);
            }
            PayrollEntry::writeMonthWithAudit($sm, $month, PayrollStatementImporter::rowsFor($p), $actor, '급여대장 업로드 '.$month);
            $done++;
        }
        unset($this->salesmen);
        if ($this->editingId) {
            $this->loadPayroll($this->editingId);
        }
        $this->dispatch('notify', message: __('salesman.import.applied', ['month' => $month, 'done' => $done, 'skipped' => $skipped]), type: 'success');
        $this->closeImport();
    }

    public string $search    = '';
    #[Url] public int $perPage = 10;
    // 2026-09-22 jin — 「ssancarerp 는 사람이 많아 하나하나 찾기 어렵다」→ 사내직원/프리랜서 pill 필터.
    //   기준은 `salesmen.type`(정산 훅이 읽는 그 값 — user.type 의 미러). 컬럼이 NOT NULL default 'employee' 라
    //   계정 미연결 담당자는 「사내직원」으로 잡힌다(실측 마이그 2026_05_20_000011).
    //   ⚠️ 메서드 이름을 프로퍼티와 같게 두지 말 것(§8 #32) — setTypeFilter().
    //   🔀 2026-10-14 (jin) — 탭 = 사내직원 / 프리랜서 / 검차직원 / 관리 / 수출통관 / 재무. 뒤 셋은 type=staff + 연결 계정의 role.
    #[Url(as: 'type')] public string $typeFilter = '';

    /** 탭 키 → 라벨. 역할 탭은 `staff:관리` 처럼 접두를 붙인다(role 이 바뀌면 자동으로 따라온다 — 동기화 불필요). */
    public static function rosterTabs(): array
    {
        $tabs = [];
        foreach (['employee', 'freelance', 'inspector'] as $t) {
            $tabs[$t] = __('salesman.type.'.$t);
        }
        foreach (User::STAFF_ROLES as $role) {
            $tabs['staff:'.$role] = __('domain.role.'.$role);
        }

        return $tabs;
    }

    public function setTypeFilter(string $type): void
    {
        $this->typeFilter = array_key_exists($type, self::rosterTabs()) ? $type : '';
        $this->resetPage();
    }

    private function applyTypeFilter($q)
    {
        if ($this->typeFilter === '') {
            return $q;
        }
        if (str_starts_with($this->typeFilter, 'staff:')) {
            $role = substr($this->typeFilter, 6);

            return $q->where('type', 'staff')->whereHas('user', fn ($u) => $u->where('role', $role));
        }

        return $q->where('type', $this->typeFilter);
    }
    public bool   $showPanel = false;
    public ?int   $editingId = null;

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public string $name        = '';
    public string $initials    = '';   // 영업담당자 이니셜 (Invoice No. 접두, item 7)
    public string $user_id_str = '';
    public string $phone       = '';
    public string $email       = '';
    public string $memo        = '';
    public bool   $is_active   = true;
    /** 🚪 퇴사일 'Y-m-d' — 비면 재직 (jin 2026-10-15). 적으면 활성이 자동으로 꺼지고, 프리랜서는 정산관리 「퇴사자」로 간다. */
    public string $left_at     = '';
    // 2026-05-21 — Salesman.type 입력 제거. User.type 단일 관리로 이동 (/admin/users 폼).
    // Salesman.type 컬럼은 user.type 미러링 결과로만 채워짐 (Vehicle::saved 훅 호환).

    // 2026-08-04 jin — 사내직원 차등정산(tier) 담당자별 on/off. 정산 금액 직결이라 canApprove() 만 수정 가능
    //   ([관리] 이상 = role 관리 · 업무관리자 · 최고관리자 · 시스템관리자).
    public bool   $per_unit_tier_enabled = false;
    public bool   $payout_excluded       = false;
    // 2026-10-21 jin — 퇴직연금 1/12 전환 대상(사람별). 돈의 갈림이라 canApprove() 만 수정.
    public bool   $pension_split_enabled = false;
    // 2026-10-21 jin — 그 달 heymanerp 정산금(ExternalSettlementPay). 급여 귀속월(payrollMonth)과 같은 달. '' = 미입력.
    public string $heymanPayStr = '';

    // 2026-09-18 jin — 예치금(프리랜서) / 기본급(사내직원). 금액칸이라 문자열로 받는다
    //   (콤마 포매터 data-money 가 붙는다 — SKILLS §14). 빈 문자열 = 미입력(null 저장).
    public string $deposit_krw_str     = '';
    public string $base_salary_krw_str = '';   // ⚠️ v3 부터 화면에서 빼고 급여 항목(PayrollEntry)이 대신한다. 저장하지 않는다.

    // 💴 급여 항목 — 귀속월별(PayrollEntry). 고정 18(ITEMS 순서, 인덱스로 바인딩) + 직접 추가 행.
    //   금액은 문자열(콤마 포매터 data-money-signed — 전월소급 등 음수). '' = 미입력.
    public string $payrollMonth = '';
    /** @var array<int, string> ITEMS 인덱스 => 금액 문자열 */
    public array $payrollItems = [];
    /** @var array<int, array{label: string, amount: string}> */
    public array $payrollCustom = [];
    public bool $payrollEntered = false;   // 그 달에 저장된 행이 하나라도 있나(「입력 전」 안내)

    #[Computed]
    public function salesmen()
    {
        return Salesman::query()
            ->with('user')
            ->tap(fn ($q) => $this->applyTypeFilter($q))
            ->when(SearchTerm::of($this->search), fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', SearchTerm::like($this->search))
                   ->orWhere('email', 'like', SearchTerm::like($this->search))
                   ->orWhere('phone', 'like', SearchTerm::like($this->search))
            ))
            ->orderBy('name')
            // 동점 tie-break (jin 2026-09-11) — 정렬키가 같은 행의 순서는 DB 가 안 정해 준다.
            //   페이지네이션에서 같은 행이 두 페이지에 나오거나 통째로 빠질 수 있다(SKILLS §8 #92).
            ->orderBy('id')
            ->paginate($this->perPage);
    }

    /*
    |----------------------------------------------------------------------
    | 퇴사 승계 (jin 2026-08-27) — A 가 하던 일을 B 가 통째로 받는다.
    |----------------------------------------------------------------------
    | 판정·실행은 전부 SalesmanHandoverService 다. 이 화면은 **보여주고 누르는 것**만 한다 —
    | 조건을 여기 옮겨 적으면 「모달엔 옮긴다고 떴는데 안 옮겨진」 행이 생긴다(SKILLS §8 #67).
    */
    public ?int $handoverFromId = null;
    public string $handoverToId = '';
    public string $handoverReason = '';
    /** 넘길 바이어 — **나눠 넘기기의 핵심**. 비우면 아무도 안 넘어간다(전부가 아니다). */
    public array $handoverBuyerIds = [];

    public function openHandover(int $fromId): void
    {
        // 정산 금액을 바꾸는 작업이라 여는 시점에도 막는다(실행 시 서비스가 다시 검사한다).
        if (! auth()->user()?->canApprove()) {
            $this->dispatch('notify', message: __('salesman.handover.forbidden'), type: 'warning');

            return;
        }
        $this->handoverFromId = $fromId;
        $this->handoverToId = '';
        $this->handoverReason = '';
        // 기본은 **전부 선택** — 한 사람에게 통째로 넘기는 게 흔한 경우라 한 번에 끝나야 한다.
        //   나눌 때만 체크를 푼다.
        $this->handoverBuyerIds = \App\Models\Buyer::where('salesman_id', $fromId)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function handoverSelectAll(): void
    {
        $this->handoverBuyerIds = \App\Models\Buyer::where('salesman_id', $this->handoverFromId)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function handoverSelectNone(): void
    {
        $this->handoverBuyerIds = [];
    }

    public function closeHandover(): void
    {
        $this->handoverFromId = null;
    }

    /** 미리보기 — 받는 사람을 고르기 전엔 null. 실행과 **같은 함수**를 쓴다. */
    #[Computed]
    public function handoverPlan(): ?array
    {
        if (! $this->handoverFromId || $this->handoverToId === '') {
            return null;
        }
        $from = Salesman::find($this->handoverFromId);
        $to = Salesman::find((int) $this->handoverToId);
        if (! $from || ! $to || $from->id === $to->id) {
            return null;
        }

        try {
            return (new \App\Services\SalesmanHandoverService)
                ->preview($from, $to, $this->handoverBuyerIds);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 받을 수 있는 사람 — **활동 중만**. 넘기는 쪽(퇴사자)은 비활성이어도 목록에 남는다. */
    #[Computed]
    public function handoverCandidates()
    {
        return Salesman::where('is_active', true)->sales()   // 검차직원에게 차량·바이어를 넘기지 않는다(월정산 v3)
            ->when($this->handoverFromId, fn ($q) => $q->where('id', '!=', $this->handoverFromId))
            ->orderBy('name')->get();
    }

    public function runHandover(): void
    {
        abort_unless(auth()->user()?->canApprove(), 403);   // 서비스가 다시 검사하지만 재무가 들어오는 화면이라 입구에서도 막는다
        $from = Salesman::find($this->handoverFromId);
        $to = Salesman::find((int) $this->handoverToId);
        if (! $from || ! $to) {
            return;
        }

        try {
            $r = (new \App\Services\SalesmanHandoverService)->apply(
                $from, $to, auth()->user(), $this->handoverReason ?: null, $this->handoverBuyerIds,
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }

        $this->handoverFromId = null;
        unset($this->salesmen);
        $this->dispatch('notify', message: __('salesman.handover.done', [
            'buyers' => $r['buyers'], 'vehicles' => $r['vehicles'], 'skipped' => $r['skipped'],
        ]), type: 'success');
    }

    public function openEdit(int $id): void
    {
        $sm = Salesman::findOrFail($id);
        $this->editingId   = $id;
        $this->name        = $sm->name;
        $this->initials    = $sm->initials ?? '';
        $this->user_id_str = $sm->user_id ? (string) $sm->user_id : '';
        $this->phone       = $sm->phone ?? '';
        $this->email       = $sm->email ?? '';
        $this->memo        = $sm->memo  ?? '';
        $this->is_active   = $sm->is_active;
        $this->left_at     = $sm->left_at?->format('Y-m-d') ?? '';
        $this->per_unit_tier_enabled = (bool) $sm->per_unit_tier_enabled;
        $this->payout_excluded = (bool) $sm->payout_excluded;
        $this->pension_split_enabled = (bool) $sm->pension_split_enabled;
        $this->deposit_krw_str     = $sm->deposit_krw     === null ? '' : (string) $sm->deposit_krw;
        $this->base_salary_krw_str = $sm->base_salary_krw === null ? '' : (string) $sm->base_salary_krw;
        if (! PayrollEntry::isMonth($this->payrollMonth)) {
            $this->payrollMonth = now()->format('Y-m');
        }
        $this->loadPayroll($id);
        $this->showPanel   = true;
    }

    // ── 💴 급여 항목 (월정산 v3) ──────────────────────────────────────────

    /** 귀속월 선택지 — 지난 2달 · 이번 달 · 다음 달(11/10 전에 10월분을 적는다). 고른 값이 밖이면 그것도 포함. */
    public function payrollMonthOptions(): array
    {
        $opts = [];
        for ($d = -2; $d <= 1; $d++) {
            $opts[] = now()->startOfMonth()->addMonths($d)->format('Y-m');
        }
        if ($this->payrollMonth !== '' && ! in_array($this->payrollMonth, $opts, true)) {
            $opts[] = $this->payrollMonth;
            sort($opts);
        }

        return $opts;
    }

    private function loadPayroll(int $salesmanId): void
    {
        $rows = PayrollEntry::where('salesman_id', $salesmanId)->forMonth($this->payrollMonth)->orderBy('sort')->orderBy('id')->get();
        $this->payrollItems = array_fill(0, count(PayrollEntry::ITEMS), '');
        $this->payrollCustom = [];
        foreach ($rows as $r) {
            $idx = $r->is_custom ? false : array_search($r->label, PayrollEntry::ITEMS, true);
            if ($idx === false) {
                $this->payrollCustom[] = ['label' => $r->label, 'amount' => (string) $r->amount];
            } else {
                $this->payrollItems[$idx] = (string) $r->amount;
            }
        }
        $this->payrollEntered = $rows->isNotEmpty();
        $heyman = ExternalSettlementPay::heymanFor($salesmanId, $this->payrollMonth);
        $this->heymanPayStr = $heyman === null ? '' : (string) $heyman;
    }

    public function updatedPayrollMonth(): void
    {
        if (! PayrollEntry::isMonth($this->payrollMonth)) {
            $this->payrollMonth = now()->format('Y-m');
        }
        if ($this->editingId) {
            $this->loadPayroll($this->editingId);
        }
    }

    public function addPayrollRow(): void
    {
        $this->payrollCustom[] = ['label' => '', 'amount' => ''];
    }

    public function removePayrollRow(int $i): void
    {
        unset($this->payrollCustom[$i]);
        $this->payrollCustom = array_values($this->payrollCustom);
    }

    /** 부호 있는 금액 문자열 → 정수 또는 null(빈칸). 콤마·공백은 걷어낸다. */
    private function signedMoneyOrNull(string $raw): ?int
    {
        $t = trim($raw);
        if ($t === '' || $t === '-') {
            return null;
        }
        $neg = str_starts_with($t, '-');
        $digits = preg_replace('/[^0-9]/', '', $t) ?? '';

        return $digits === '' ? null : ($neg ? -1 : 1) * (int) $digits;
    }

    /** 화면 입력 → PayrollEntry::replaceFor 행. 순서 = 고정 ITEMS → 직접 추가. */
    private function payrollRows(): array
    {
        $rows = [];
        foreach (PayrollEntry::ITEMS as $i => $label) {
            $rows[] = ['label' => $label, 'amount' => $this->signedMoneyOrNull((string) ($this->payrollItems[$i] ?? ''))];
        }
        foreach ($this->payrollCustom as $row) {
            $rows[] = ['label' => trim((string) ($row['label'] ?? '')), 'amount' => $this->signedMoneyOrNull((string) ($row['amount'] ?? '')), 'is_custom' => true];
        }

        return $rows;
    }

    /** 지금 입력된 값의 지급합계 — 저장 전 미리보기(화면 「지급합계」). */
    public function payrollTotal(): int
    {
        return (int) array_sum(array_map(fn ($r) => (int) ($r['amount'] ?? 0), $this->payrollRows()));
    }

    /** 금액칸 → 정수 또는 null. 빈칸·콤마·기타 문자를 걷어낸다(0 과 미입력을 구분해야 하므로 null 유지). */
    private function moneyOrNull(string $raw): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        return $digits === '' ? null : (int) $digits;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        // 2026-05-21 사용자 결정: User 가 마스터. 편집 모드에서 name/email/user_id/type 은 read-only.
        // /admin/users 폼이 자동으로 Salesman row 생성·동기화. 이 화면은 보충 정보(전화·메모·활성) 입력 전용.
        if ($this->editingId) {
            $this->validate(['name' => 'required|string|max:100'], [], ['name' => __('salesman.field.name')]);
            $sm = Salesman::findOrFail($this->editingId);
            $actor = auth()->user();
            // 월정산 v3 — 재무도 이 화면에 들어온다(급여 입력). 보충 필드·tier·제외·예치금은 종전대로 **관리 이상만**,
            //   재무가 보내온 값은 저장 시점에 버린다(§8 #26 — 화면에서 disabled 한 것과 별개).
            $canApprove = (bool) $actor?->canApprove();
            // 보충 필드만 update — name/email/user_id/type 은 손대지 않음 (User 마스터 보호).
            $data = $canApprove ? [
                'initials'  => $this->initials ? strtoupper(trim($this->initials)) : null,
                'phone'     => $this->phone ?: null,
                'memo'      => $this->memo  ?: null,
                'is_active' => $this->is_active,
            ] : [];
            // 🚪 퇴사일 (jin 2026-10-15) — 관리 이상만. 적으면 활성을 끈다(체크를 켜 둔 채 저장해도). 비우면 퇴사 해제(활성은 손대지 않는다).
            if ($canApprove) {
                $this->validate(['left_at' => 'nullable|date_format:Y-m-d'], [], ['left_at' => __('salesman.field.left_at')]);
                $data['left_at'] = $this->left_at !== '' ? $this->left_at : null;
                if ($data['left_at'] !== null) {
                    $data['is_active'] = false;
                }
            }
            // tier 는 정산 금액을 바꾸므로 화면 노출과 별개로 저장 시점에 재인가한다 (SKILLS §8 #26).
            if ($canApprove) {
                $data['per_unit_tier_enabled'] = $this->per_unit_tier_enabled;
                // 🚪 지급 대상 제외도 돈이 나가고 안 나가고를 가르므로 tier 와 같은 무게로 재인가한다.
                $data['payout_excluded'] = $this->payout_excluded;
                // 퇴직연금 1/12 — 송금액이 갈린다(2026-10-21). 사내직원·프리랜서만(검차·직원은 정산이 없다).
                if (in_array($sm->type, ['employee', 'freelance'], true)) {
                    $data['pension_split_enabled'] = $this->pension_split_enabled;
                }
            }
            // 💰 예치금·기본급 — 연결된 계정 유형이 정하는 **한 칸만** 저장한다.
            //   🚫 유형이 안 붙은 담당자는 둘 다 건드리지 않는다 — 화면에도 안 띄운다.
            //      엉뚱한 칸에 금액이 들어가는 것보다 비어 있는 게 낫다.
            //   ⚠️ 유형이 바뀌면 반대쪽 칸의 옛 값은 **지우지 않는다**(기록). 화면이 안 보여줄 뿐이다.
            //   🚨 화면에서 숨긴 것과 **별개로** 저장 시점에 권한을 다시 본다(§8 #26) —
            //      프로퍼티는 클라이언트가 직접 주입할 수 있다. 테스트가 실제로 이 구멍을 잡았다.
            //   🔀 v3 (2026-10-09): 유형의 출처 = `salesmen.type`(user.type 의 미러 + 계정 없는 검차직원). 기본급 칸은
            //      급여 항목(PayrollEntry, 귀속월별)이 대신한다 — `base_salary_krw` 는 더 이상 쓰지 않는다(10/10 뒤 비움).
            $linkedType = $canApprove ? $sm->type : null;
            if ($linkedType === 'freelance') {
                $data['deposit_krw'] = $this->moneyOrNull($this->deposit_krw_str);
            }

            $before = [
                'left_at' => $sm->left_at?->format('Y-m-d'),
                'per_unit_tier_enabled' => (bool) $sm->per_unit_tier_enabled,
                'payout_excluded' => (bool) $sm->payout_excluded,
                'pension_split_enabled' => (bool) $sm->pension_split_enabled,
                'deposit_krw' => $sm->deposit_krw,
                'base_salary_krw' => $sm->base_salary_krw,
            ];
            if ($data !== []) {
                $sm->update($data);
            }
            // 돈을 바꾸는 스위치라 누가 언제 켰는지 남긴다 (Salesman 엔 감사 훅이 없어 여기서 직접).
            //   🔑 비교는 `AuditLog::valuesDiffer()` — `!==` 로 하면 «3000000» ↔ 3000000 처럼
            //      표기만 다른 값이 변경으로 잡혀 소음이 쌓인다(§8 #108).
            foreach ($before as $col => $was) {
                if (array_key_exists($col, $data) && \App\Models\AuditLog::valuesDiffer($was, $data[$col])) {
                    \App\Models\AuditLog::recordChange($sm, $col, $was, $data[$col]);
                }
            }

            // 💴 급여 항목 — 사내직원·검차직원, 관리 이상 + 재무. 그 달을 통째로 교체하고 지급합계 변화를 감사로그 1줄로.
            if (($sm->type === 'employee' || $sm->isPayrollOnly()) && $actor?->canEditPayroll() && PayrollEntry::isMonth($this->payrollMonth)) {
                // 감사로그·결재 중 월정산 변경 이력은 writeMonthWithAudit 한 곳(급여대장 업로드와 공용, §8 #44).
                PayrollEntry::writeMonthWithAudit($sm, $this->payrollMonth, $this->payrollRows(), $actor, '급여 항목 수정');
            }
            // 💱 헤이맨 정산금 — 사내직원·프리랜서, 급여와 같은 권한·같은 귀속월. 감사로그·결재 중 변경 이력은 writeHeyman 한 곳.
            if (in_array($sm->type, ['employee', 'freelance'], true) && $actor?->canEditPayroll() && PayrollEntry::isMonth($this->payrollMonth)) {
                ExternalSettlementPay::writeHeyman($sm, $this->payrollMonth, $this->signedMoneyOrNull($this->heymanPayStr), $actor);
            }
        } else {
            // 🔀 2026-10-14 (jin «사용자관리에서 한번에 관리») — 이 화면에서는 등록하지 않는다. 검차직원을 포함한 모든 인원은
            //   사용자 관리에서 등록하면 Salesman::mirrorTypeFor() 로 여기에 올라온다. (구 10-08 「검차직원 이름만 등록」 경로 폐지)
            abort(403);
        }

        unset($this->salesmen);
        // 2026-05-21 사용자 피드백 — 저장 시 시각 피드백 + 패널 자동 닫기.
        $this->dispatch('notify', message: __('salesman.saved'), type: 'success');
        $this->close();
    }

    public function delete(int $id): void
    {
        abort_unless(auth()->user()?->canApprove(), 403);   // 재무는 급여만 — 삭제는 관리 이상(§8 #26)
        Salesman::findOrFail($id)->delete();
        unset($this->salesmen);
        session()->flash('success', __('salesman.deleted'));
    }

    public function searchNow(): void
    {
        $this->resetPage();
    }

    private function resetForm(): void
    {
        $this->name = $this->initials = $this->user_id_str = $this->phone = $this->email = $this->memo = $this->left_at = '';
        $this->is_active = true;
        $this->payrollItems = [];
        $this->payrollCustom = [];
        $this->payrollEntered = false;
        $this->heymanPayStr = '';
        $this->pension_split_enabled = false;
    }
}; ?>

<div wire:poll.30s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('salesman.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('salesman.total', ['count' => $this->salesmen->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
        @if(auth()->user()?->canApprove())
        {{-- 2026-10-14 — 등록은 사용자 관리 한 곳에서(검차직원 포함). 여기선 안내 링크만. --}}
        <a href="{{ route('admin.users.index') }}" wire:navigate class="btn-primary" title="{{ __('salesman.register_in_users_hint') }}">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('salesman.register_in_users') }}
        </a>
        @endif
        @if(auth()->user()?->canEditPayroll())
        <button type="button" wire:click="openImport" class="btn-primary" title="{{ __('salesman.import.hint') }}">{{ __('salesman.import.button') }}</button>
        @endif
    </div>
</div>

{{-- 📄 급여대장 업로드 모달 (jin 2026-10-14). 카드는 max-h + overflow(폰에서 아래 버튼 닿게, §8 #98). --}}
@if($showImport)
<div class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 p-3 sm:items-center sm:p-6" wire:click.self="closeImport">
    <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-xl bg-white p-5 shadow-2xl" data-payroll-import>
        <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">{{ __('salesman.import.title') }}</h3>
            <button type="button" wire:click="closeImport" class="rounded p-1 text-gray-400 hover:bg-gray-100">✕</button>
        </div>
        <p class="mt-1 text-xs text-gray-500">{{ __('salesman.import.hint') }}</p>
        {{-- 파일 선택은 **버튼처럼** (jin 2026-10-14 «파일선택이 그냥 텍스트 같아서») — 기본 input 은 숨기고 label 을 버튼으로. --}}
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <label class="btn-primary cursor-pointer" data-payroll-file-button>
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m0-12l-4 4m4-4l4 4"/></svg>
                <span wire:loading.remove wire:target="payrollFile">{{ __('salesman.import.choose') }}</span>
                <span wire:loading wire:target="payrollFile">{{ __('salesman.import.reading') }}</span>
                <input type="file" wire:model="payrollFile" accept=".xls,.xlsx" class="hidden" />
            </label>
            <span class="text-xs text-gray-500">{{ $payrollFile ? $payrollFile->getClientOriginalName() : __('salesman.import.no_file') }}</span>
            @error('payrollFile')<p class="w-full text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        @if($importPreview !== [])
            @foreach(($importPreview['errors'] ?? []) as $err)
            <p class="mt-2 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ $err }}</p>
            @endforeach
            @php
                $people = $importPreview['people'] ?? [];
                $month = $importPreview['month'] ?? null;
                $candidates = $this->importCandidates;
                $byId = collect($candidates)->keyBy('id');
                $matchedIds = array_filter(array_map('intval', $importMatches));
                $overwrite = $month ? \App\Models\PayrollEntry::whereIn('salesman_id', $matchedIds)->where('month', $month)->distinct()->count('salesman_id') : 0;
                $commissionCheck = $this->importCommissionCheck;
                $commissionBad = collect($commissionCheck)->filter(fn ($c) => $c['diff'] !== 0)->count();
            @endphp
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-700">
                <span>{{ __('salesman.import.month') }} <b>{{ $month ?? '—' }}</b></span>
                <span>{{ __('salesman.import.count', ['n' => count($people)]) }}</span>
                <span>{{ __('salesman.import.matched', ['n' => count($matchedIds)]) }}</span>
                @if($overwrite > 0)<span class="text-amber-700">{{ __('salesman.import.overwrite', ['n' => $overwrite]) }}</span>@endif
                @if($commissionCheck !== [])<span class="{{ $commissionBad > 0 ? 'font-semibold text-red-700' : 'text-emerald-700' }}" data-commission-summary>{{ $commissionBad > 0 ? __('salesman.import.commission_bad', ['n' => $commissionBad]) : __('salesman.import.commission_all_ok', ['n' => count($commissionCheck)]) }}</span>@endif
            </div>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[640px] text-xs">
                    <thead><tr class="border-b border-gray-200 text-left text-gray-500">
                        <th class="py-1 pr-2">{{ __('salesman.import.col_no') }}</th>
                        <th class="py-1 pr-2">{{ __('salesman.import.col_name') }}</th>
                        <th class="py-1 pr-2">{{ __('salesman.import.col_dept') }}</th>
                        <th class="py-1 pr-2 text-right">{{ __('salesman.payroll.total') }}</th>
                        <th class="py-1 pr-2 text-right" title="{{ __('salesman.import.commission_hint') }}">{{ __('salesman.import.col_commission') }}</th>
                        <th class="py-1 pr-2">{{ __('salesman.import.col_match') }}</th>
                        <th class="py-1">{{ __('common.status') }}</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                    @foreach($people as $p)
                        @php $mid = (int) ($importMatches[$p['row']] ?? 0); $m = $mid ? $byId->get($mid) : null; @endphp
                        <tr class="{{ ! $p['ok'] ? 'bg-red-50' : ($mid ? '' : 'bg-amber-50') }}" data-import-row="{{ $p['row'] }}">
                            <td class="py-1 pr-2 text-gray-500">{{ $p['employee_no'] }}</td>
                            <td class="py-1 pr-2 font-medium text-gray-800">{{ $p['name'] }} <span class="text-gray-400">{{ $p['position'] }}</span></td>
                            <td class="py-1 pr-2 text-gray-500">{{ $p['dept'] }}</td>
                            <td class="py-1 pr-2 text-right font-mono">{{ number_format($p['total']) }}</td>
                            @php $cc = $commissionCheck[$p['row']] ?? null; @endphp
                            <td class="py-1 pr-2 text-right font-mono text-[11px]" data-import-commission="{{ $p['row'] }}">
                                @if($cc)
                                    @if($cc['diff'] === 0)<span class="text-emerald-700">{{ __('salesman.import.commission_ok') }}</span>
                                    @else<span class="font-semibold text-red-700" title="{{ __('salesman.import.commission_detail', ['sheet' => number_format($cc['sheet']), 'erp' => number_format($cc['expected'])]) }}">{{ $cc['diff'] > 0 ? '+' : '−' }}{{ number_format(abs($cc['diff'])) }}</span>
                                    <span class="block text-[10px] font-normal text-gray-500">{{ __('salesman.import.commission_detail', ['sheet' => number_format($cc['sheet']), 'erp' => number_format($cc['expected'])]) }}</span>@endif
                                    @if($cc['pension_on'])<span class="block text-[10px] font-normal text-sky-700">{{ __('salesman.import.commission_pension', ['amount' => number_format($cc['pension'])]) }}</span>@endif
                                @else<span class="text-gray-300">—</span>@endif
                            </td>
                            <td class="py-1 pr-2">
                                <select wire:model="importMatches.{{ $p['row'] }}" class="input-base h-8 py-0 text-xs text-gray-800">
                                    <option value="">{{ __('salesman.import.unmatched') }}</option>
                                    @foreach($candidates as $c)
                                    <option value="{{ $c['id'] }}">{{ $c['name'] }} ({{ __('salesman.type.'.$c['type']) }})</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="py-1 text-[11px]">
                                @if(! $p['ok'])<span class="text-red-600">{{ $p['error'] }}</span>
                                @elseif(! $mid)<span class="text-amber-700">{{ __('salesman.import.pick') }}</span>
                                @elseif(($m['type'] ?? '') === 'freelance')<span class="text-amber-700">{{ __('salesman.import.freelance_note') }}</span>
                                @else<span class="text-green-600">{{ __('salesman.import.ready') }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                <button type="button" wire:click="closeImport" class="rounded-lg border px-3 py-2 text-sm text-gray-600">{{ __('common.cancel') }}</button>
                <button type="button" wire:click="applyImport" class="btn-primary" @disabled(($importPreview['errors'] ?? []) !== [] || $matchedIds === [])>{{ __('salesman.import.apply') }}</button>
            </div>
        @endif
    </div>
</div>
@endif

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('salesman.search_ph') }}"
           class="input-filter w-64" />
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
    <div class="flex flex-wrap items-center gap-1">
        <button type="button" wire:click="setTypeFilter('')"
                class="tab-pill {{ $typeFilter === '' ? 'is-active' : '' }}">{{ __('salesman.type_filter.all') }}</button>
        @foreach(static::rosterTabs() as $tKey => $tLabel)
        <button type="button" wire:click="setTypeFilter('{{ $tKey }}')"
                class="tab-pill {{ $typeFilter === $tKey ? 'is-active' : '' }}">{{ $tLabel }}</button>
        @endforeach
    </div>
</div>

{{-- 테이블 (데스크탑) --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('salesman.col.name') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('salesman.col.type') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('salesman.col.account') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.phone') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.email') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.status') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->salesmen as $sm)
            <tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $sm->id }})">
                <td class="py-3 pr-4 font-medium text-gray-800">
                    {{ $sm->name }}
                    @php $co = $sm->unconsumed_carryover; @endphp
                    @if($co != 0)
                    <span class="badge {{ $co > 0 ? 'badge-green' : 'badge-red' }} ml-1.5 text-[10px]"
                          title="{{ __('salesman.carryover_badge') }}">{{ $co > 0 ? '+' : '−' }}₩{{ number_format(abs($co)) }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4">
                    <span class="badge {{ \App\Models\Salesman::typeBadgeClass($sm->type) }}">{{ $sm->type_label }}</span>
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->user?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->phone ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $sm->email ?? '-' }}</td>
                <td class="py-3 pr-4">
                    @if($sm->hasLeft())
                    <span class="badge badge-gray" title="{{ $sm->settlesImmediatelyOnLeave() ? __('salesman.left_freelance_hint') : __('salesman.left_employee_hint') }}">{{ __('salesman.left_badge', ['date' => $sm->left_at->format('Y-m-d')]) }}</span>
                    @else
                    <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $sm->is_active ? __('common.active') : __('common.inactive') }}
                    </span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                           onclick="event.stopPropagation()"
                           class="text-xs text-violet-600 hover:underline">{{ __('salesman.cashflow') }}</a>
                        @if(auth()->user()?->canApprove())
                        <button wire:click.stop="openHandover({{ $sm->id }})"
                                class="text-xs text-amber-600 hover:underline">{{ __('salesman.handover.button') }}</button>
                        @endif
                        @if(auth()->user()?->canApprove())
                        <button wire:click.stop="delete({{ $sm->id }})"
                                wire:confirm="{{ __('salesman.delete_confirm', ['name' => $sm->name]) }}"
                                class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">{{ __('salesman.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 카드 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->salesmen as $sm)
    <div class="card-tight">
        <div class="flex items-center justify-between">
            <div class="cursor-pointer" wire:click="openEdit({{ $sm->id }})">
                <div class="font-medium text-gray-800">
                    {{ $sm->name }}
                    @php $co = $sm->unconsumed_carryover; @endphp
                    @if($co != 0)
                    <span class="badge {{ $co > 0 ? 'badge-green' : 'badge-red' }} text-[10px]">{{ $co > 0 ? '+' : '−' }}₩{{ number_format(abs($co)) }}</span>
                    @endif
                </div>
                <div class="text-xs text-gray-500">{{ $sm->phone ?? '' }}{{ $sm->email ? ' · '.$sm->email : '' }}</div>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge {{ \App\Models\Salesman::typeBadgeClass($sm->type) }}">{{ $sm->type_label }}</span>
                @if($sm->hasLeft())
                <span class="badge badge-gray">{{ __('salesman.left_badge', ['date' => $sm->left_at->format('Y-m-d')]) }}</span>
                @else
                <span class="badge {{ $sm->is_active ? 'badge-green' : 'badge-gray' }}">{{ $sm->is_active ? __('common.active') : __('common.inactive') }}</span>
                @endif
                <a href="{{ route('erp.salesmen.cashflow', $sm->id) }}" wire:navigate
                   class="text-xs text-violet-600 hover:underline">{{ __('salesman.cashflow') }}</a>
            </div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('salesman.empty') }}</div>
    @endforelse
</div>

<div>{{ $this->salesmen->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('salesman.edit_title') : __('salesman.create_title') }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 폼 --}}
    @php $canApproveHere = (bool) auth()->user()?->canApprove(); @endphp
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        {{-- 월정산 v3 — 재무는 급여 항목만. 나머지 칸은 보이되 잠근다(서버도 버린다). --}}
        @if($editingId && ! $canApproveHere)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ __('salesman.payroll.basic_readonly') }}</div>
        @endif
        {{-- 2026-05-21 — 편집 시 사용자 마스터 안내 배너 --}}
        @if($editingId)
        <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-700">
            {!! __('salesman.master_banner', ['link' => '<a href="'.route('admin.users.index').'" wire:navigate class="font-medium underline">'.e(__('salesman.users_link')).'</a>']) !!}
        </div>
        @endif
        <div>
            <label class="label-base">{{ __('salesman.field.name') }} @if(! $editingId)<span class="text-red-500">*</span>@endif</label>
            @if($editingId)
                <div class="input-base bg-gray-50 text-gray-700">{{ $name }}</div>
            @else
                <input wire:model="name" type="text" class="input-base" placeholder="{{ __('salesman.field.name_ph') }}" />
                @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            @endif
        </div>
        {{-- 영업담당자 이니셜 (item 7) — Proforma Invoice No. 접두 {이니셜}MU{차대번호숫자} --}}
        <div>
            <label class="label-base">{{ __('salesman.field.initials') }} <span class="text-xs text-gray-400">{{ __('common.optional') }}</span></label>
            <input wire:model="initials" type="text" maxlength="10" class="input-base uppercase" placeholder="{{ __('salesman.field.initials_ph') }}" @disabled(! $canApproveHere) />
            <p class="mt-1 text-[11px] text-gray-400">{{ __('salesman.field.initials_note') }}</p>
        </div>
        @if($editingId)
        <div>
            <label class="label-base">{{ __('salesman.field.account') }}</label>
            @php $linkedUser = $user_id_str !== '' ? \App\Models\User::find((int) $user_id_str) : null; @endphp
            <div class="input-base bg-gray-50 text-gray-700">
                {{ $linkedUser ? $linkedUser->name.($linkedUser->email ? " ({$linkedUser->email})" : '') : __('salesman.field.linked_none') }}
            </div>
        </div>
        @endif
        <div class="grid grid-cols-2 gap-3">
            {{-- 2026-05-21 — Alpine phoneMask 자동 하이픈 (한국 4 패턴) --}}
            <div>
                <label class="label-base">{{ __('common.phone') }}</label>
                <input wire:model="phone" type="tel" class="input-base"
                       placeholder="01012345678"
                       maxlength="13"
                       x-on:input="$event.target.value = $store.phoneMask.apply($event.target.value); $wire.phone = $event.target.value" @disabled(! $canApproveHere) />
            </div>
            <div>
                <label class="label-base">{{ __('common.email') }}</label>
                @if($editingId)
                    <div class="input-base bg-gray-50 text-gray-700">{{ $email ?: '-' }}</div>
                @else
                    <input wire:model="email" type="email" class="input-base" />
                @endif
            </div>
        </div>
        {{-- 2026-05-21 — 정산 분류는 /admin/users 에서 관리. 여기서는 연결된 user.type 을 read-only 로 표시 --}}
        @if($editingId)
        @php
            $editingSalesman = \App\Models\Salesman::with('user')->find($editingId);
            // 월정산 v3 — 유형의 출처는 salesmen.type (user.type 의 미러 + 계정 없는 검차직원). 사용자 관리에서 바꾸면 따라온다.
            $linkedUserType  = $editingSalesman?->type;
            $typeLabel       = $linkedUserType ? $editingSalesman->type_label : null;   // staff 는 연결 계정 role(관리/수출통관/재무)
            $canEditPayroll  = (bool) auth()->user()?->canEditPayroll();
        @endphp
        <div>
            <label class="label-base">{{ __('salesman.field.settlement_type') }}</label>
            <div class="input-base bg-gray-50 text-gray-700">
                @if($typeLabel)
                    {{ $typeLabel }} {{ __('salesman.type_suffix.'.$linkedUserType) }}
                @elseif($editingSalesman?->user_id)
                    <span class="text-amber-600">{{ __('salesman.field.type_unset') }}</span>
                @else
                    <span class="text-gray-400">{{ __('salesman.field.type_no_account') }}</span>
                @endif
            </div>
            <p class="mt-1 text-[11px] text-gray-500">{!! __('salesman.type_note', ['link' => '<a href="'.route('admin.users.index').'" wire:navigate class="text-violet-600 hover:underline">'.e(__('salesman.users_link')).'</a>']) !!}</p>
        </div>
        {{-- 차등정산(tier) — 사내직원 한정. 정산 금액 직결이라 [관리] 이상만 --}}
        @if($linkedUserType === 'employee' && auth()->user()?->canApprove())
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <label class="flex items-start gap-2 cursor-pointer">
                <input wire:model="per_unit_tier_enabled" type="checkbox" class="mt-0.5 rounded" />
                <span class="text-sm text-gray-800">
                    {{ __('salesman.field.per_unit_tier') }}
                    <span class="mt-1 block text-[11px] leading-relaxed text-gray-600">
                        {{ __('salesman.field.per_unit_tier_hint') }}
                    </span>
                </span>
            </label>
        </div>
        @endif
        {{-- 🏦 퇴직연금 1/12 전환 (jin 2026-10-21) — 사람별. 켜면 (급여 + 정산금 + 헤이맨 정산금 + 조정) ÷ 12 를 퇴직연금으로 돌리고
             나머지를 송금한다. 급여대장 판매수당 대조도 이 값으로 한다. 송금액이 갈려서 [관리] 이상만. --}}
        @if(in_array($linkedUserType, ['employee', 'freelance'], true) && auth()->user()?->canApprove())
        <div class="rounded-lg border border-sky-200 bg-sky-50 p-3" data-pension-toggle>
            <label class="flex items-start gap-2 cursor-pointer">
                <input wire:model="pension_split_enabled" type="checkbox" class="mt-0.5 rounded" />
                <span class="text-sm text-gray-800">
                    {{ __('salesman.field.pension_split') }}
                    <span class="mt-1 block text-[11px] leading-relaxed text-gray-600">{{ __('salesman.field.pension_split_hint') }}</span>
                </span>
            </label>
        </div>
        @endif
        {{-- 💰 예치금(프리랜서) / 기본급(사내직원) — jin 2026-09-18.
             「사용자관리에서 프리랜서냐 사내직원이냐에 따라서 그걸 기입 할 수 있게」
             🚫 유형이 안 붙은 담당자에겐 **둘 다 안 보인다** — 엉뚱한 칸에 금액이 들어가는 것보다 낫다.
             🚫 지급액에 더해지지 않는다(표시 전용) — 배치 총액·회사이익 불변. --}}
        @if($linkedUserType === 'freelance' && auth()->user()?->canApprove())
        <div class="rounded-lg border border-violet-200 bg-violet-50 p-3">
            <label class="label-base">{{ __('salesman.field.deposit') }}</label>
            <div class="flex items-center gap-1">
                <input wire:model="deposit_krw_str" data-money type="text" inputmode="numeric"
                       class="input-base flex-1 text-right tabular-nums" placeholder="{{ __('salesman.field.money_ph') }}" />
                <span class="text-sm text-gray-600">{{ __('common.won') }}</span>
            </div>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-600">{{ __('salesman.field.deposit_hint') }}</p>
        </div>
        @elseif(in_array($linkedUserType, ['employee', ...\App\Models\Salesman::PAYROLL_ONLY_TYPES], true) && $canEditPayroll)
        {{-- 💴 급여 항목 (월정산 v3, jin 2026-10-08) — 귀속월별 18항목 + 직접 추가. 매달 빈칸. 관리 이상 + 재무.
             구 「기본급」 한 칸을 대신한다. 금액은 data-money-signed(전월소급 등 음수). --}}
        {{-- 금액칸은 deferred(wire:model) — 치는 동안 서버에 안 간다(SearchRequiresButtonTest). 합계 미리보기는 Alpine 이 더한다.
             🔧 2026-10-19 (jin «지급합계 0원», ssancarerp 굴러자 9월) — 구: 입력칸 DOM 을 읽고 @input 때만 다시 셌다.
                패널을 열거나 귀속월을 바꿔 값이 서버에서 채워지면 아무도 안 쳐서 「₩0」에 머물렀다.
                지금은 $wire 상태(payrollItems·payrollCustom)를 읽는다 — deferred 라도 치는 즉시 클라이언트 상태가 바뀌고,
                서버가 월을 바꿔 채워도 바뀌므로 둘 다 자동으로 다시 센다. --}}
        <div class="rounded-lg border border-violet-200 bg-violet-50 p-3" data-payroll
             x-data="{ total() { const vals = [...Object.values($wire.payrollItems || {}), ...Object.values($wire.payrollCustom || {}).map((r) => r ? r.amount : '')]; let t = 0; vals.forEach((v) => { const s = String(v ?? '').trim(); const n = parseInt(s.replace(/[^0-9]/g, ''), 10) || 0; t += s.startsWith('-') ? -n : n; }); return t.toLocaleString('en-US'); } }">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-gray-800">{{ __('salesman.payroll.title') }}</span>
                <select wire:model.live="payrollMonth" class="input-base w-auto py-1 text-xs text-gray-800" aria-label="{{ __('salesman.payroll.month') }}">
                    @foreach($this->payrollMonthOptions() as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <p class="mt-1 text-[11px] leading-relaxed text-gray-600">{{ __('salesman.payroll.hint') }}</p>
            @if(! $payrollEntered)
            <p class="mt-1 text-[11px] font-medium text-amber-700" data-payroll-not-entered>{{ __('salesman.payroll.not_entered') }}</p>
            @endif
            <div class="mt-2 space-y-1">
                @foreach(\App\Models\PayrollEntry::ITEMS as $i => $item)
                <div class="flex items-center gap-2">
                    <label class="w-28 shrink-0 text-xs text-gray-700">{{ $item }}</label>
                    <input wire:model="payrollItems.{{ $i }}" data-money data-money-signed data-pay-amount type="text" inputmode="numeric"
                           class="input-base h-9 sm:h-7 flex-1 text-right text-xs tabular-nums" placeholder="{{ __('salesman.payroll.amount_ph') }}" />
                </div>
                @endforeach
                @foreach($payrollCustom as $ci => $row)
                <div class="flex items-center gap-2" wire:key="payroll-custom-{{ $ci }}">
                    <input wire:model="payrollCustom.{{ $ci }}.label" type="text" maxlength="40"
                           class="input-base h-9 sm:h-7 w-28 shrink-0 text-xs" placeholder="{{ __('salesman.payroll.custom_label_ph') }}" />
                    <input wire:model="payrollCustom.{{ $ci }}.amount" data-money data-money-signed data-pay-amount type="text" inputmode="numeric"
                           class="input-base h-9 sm:h-7 flex-1 text-right text-xs tabular-nums" placeholder="{{ __('salesman.payroll.amount_ph') }}" />
                    <button type="button" wire:click="removePayrollRow({{ $ci }})" aria-label="{{ __('common.delete') }}"
                            class="h-9 w-9 sm:h-7 sm:w-7 shrink-0 rounded text-gray-400 hover:text-red-500">&times;</button>
                </div>
                @endforeach
            </div>
            <div class="mt-2 flex items-center justify-between">
                <button type="button" wire:click="addPayrollRow" class="h-9 sm:h-7 text-xs text-violet-700 hover:underline">{{ __('salesman.payroll.add_row') }}</button>
                <span class="text-sm font-semibold text-gray-800">{{ __('salesman.payroll.total') }} <span class="tabular-nums" data-payroll-total x-text="'₩' + total()">₩{{ number_format($this->payrollTotal()) }}</span></span>
            </div>
            @if($linkedUserType === 'employee')
            {{-- 💱 헤이맨 정산금 (2026-10-21) — 급여 합계 밖의 별도 칸. 월정산 카드에서 송금엔 더하고 회사이익엔 안 넣는다. --}}
            <div class="mt-2 flex items-center gap-2 border-t border-violet-200 pt-2" data-heyman-input>
                <span class="w-28 shrink-0 text-xs text-gray-700">{{ __('salesman.field.heyman_pay') }}</span>
                <input wire:model="heymanPayStr" data-money data-money-signed type="text" inputmode="numeric"
                       class="input-base h-9 sm:h-7 flex-1 text-right text-xs tabular-nums" placeholder="{{ __('salesman.payroll.amount_ph') }}" />
            </div>
            <p class="mt-1 text-[11px] text-gray-500">{{ __('salesman.field.heyman_pay_hint') }}</p>
            @endif
        </div>
        @elseif($linkedUserType === 'freelance' && $canEditPayroll)
        {{-- 💱 프리랜서도 헤이맨 정산금을 같이 받는 경우가 있다(jin «사내직원/프리랜서 다 필요») — 귀속월은 급여와 같은 선택. --}}
        <div class="rounded-lg border border-violet-200 bg-violet-50 p-3" data-heyman-input>
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-gray-800">{{ __('salesman.field.heyman_pay') }}</span>
                <select wire:model.live="payrollMonth" class="input-base w-auto py-1 text-xs text-gray-800" aria-label="{{ __('salesman.payroll.month') }}">
                    @foreach($this->payrollMonthOptions() as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <input wire:model="heymanPayStr" data-money data-money-signed type="text" inputmode="numeric"
                   class="input-base mt-2 text-right text-xs tabular-nums" placeholder="{{ __('salesman.payroll.amount_ph') }}" />
            <p class="mt-1 text-[11px] text-gray-500">{{ __('salesman.field.heyman_pay_hint') }}</p>
        </div>
        @endif
        {{-- 🚪 지급 대상 제외 (jin 2026-09-16) — 「헤이맨」처럼 사람이 아닌 계정(자매 회사)용.
             ⚠️ tier 와 달리 **사내직원 한정이 아니다** — 어떤 유형이든 지급 안 하는 계정이 있을 수 있다. --}}
        @if(auth()->user()?->canApprove())
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <label class="flex items-start gap-2 cursor-pointer">
                <input wire:model="payout_excluded" type="checkbox" class="mt-0.5 rounded" />
                <span class="text-sm text-gray-800">
                    {{ __('salesman.field.payout_excluded') }}
                    <span class="mt-1 block text-[11px] leading-relaxed text-gray-600">
                        {{ __('salesman.field.payout_excluded_hint') }}
                    </span>
                </span>
            </label>
        </div>
        @endif
        @endif
        <div>
            <label class="label-base">{{ __('common.memo') }}</label>
            <textarea wire:model="memo" class="input-base" rows="2" @disabled(! $canApproveHere)></textarea>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" @disabled(! $canApproveHere) /> {{ __('common.active') }}
            </label>
        </div>
        {{-- 🚪 퇴사일 (jin 2026-10-15) — 관리 이상. 적으면 활성이 꺼진다. 프리랜서는 정산관리 「퇴사자」에서 즉시 정산, 사내직원은 종전대로 월정산. --}}
        @if($editingId && $canApproveHere)
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3" data-left-at>
            <label class="label-base">{{ __('salesman.field.left_at') }}</label>
            <input wire:model="left_at" type="date" class="input-base" />
            @error('left_at') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            <p class="mt-1 text-[11px] leading-relaxed text-gray-500">{{ __('salesman.field.left_at_hint') }}</p>
        </div>
        @endif
        @if($editingId)
        <div class="pt-2 border-t">
            <a href="{{ route('erp.salesmen.cashflow', $editingId) }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-violet-600 hover:underline">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                {{ __('salesman.cashflow_view') }}
            </a>
        </div>
        @endif
    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        {{-- 🗑️ 폰 전용 삭제 (jin 2026-09-14) — 목록 카드가 아니라 **패널 푸터**에 둔다.
             카드 자체가 「누르면 편집 열림」이라 거기 삭제를 붙이면 오탭이 곧 사고가 된다.
             여기는 항목을 이미 연 상태라 의도가 분명하고, 확인창이 한 번 더 받는다.
             데스크탑은 표에 삭제가 있으므로 `sm:hidden` — 렌더가 안 바뀐다. --}}
        @if($editingId)
        <div class="mr-auto flex gap-2 sm:hidden">
            {{-- 승계는 퇴사 처리 흐름이라 「급할 때 폰에서」가 실제로 생긴다. 권한은 표와 같다. --}}
            @if(auth()->user()?->canApprove())
            <button wire:click="openHandover({{ $editingId }})"
                    class="rounded-lg border border-amber-300 px-3 py-2 text-sm text-amber-700 hover:bg-amber-50">
                {{ __('salesman.handover.button') }}
            </button>
            @endif
            @if($canApproveHere)
            <button wire:click="delete({{ $editingId }})"
                    wire:confirm="{{ __('salesman.delete_confirm_simple') }}"
                    class="rounded-lg border border-red-300 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                {{ __('common.delete') }}
            </button>
            @endif
        </div>
        @endif
        <button wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>
@endif


{{-- ══ 퇴사 승계 모달 (jin 2026-08-27) ═══════════════════════════════════════
     되돌리기가 없는 일괄 작업이라 **미리보기 없이 실행하지 않는다.**
     「옮길 것」과 「건너뛸 것 + 사유 + 차량번호」를 나란히 보여준다 — 카운터로 뭉개면
     정작 손봐야 할 몇 대가 숫자에 묻힌다(SKILLS §8 #67).                        --}}
@if($handoverFromId)
@php $from = \App\Models\Salesman::find($handoverFromId); $plan = $this->handoverPlan; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="closeHandover">
    {{-- ⚠️ overflow-hidden 은 모서리 둥글리기용이다 — 그대로 두면 내용이 길 때 **잘린다**.
         세로만 스크롤로 바꾼다(가로는 계속 숨긴다). --}}
    <div class="w-full max-w-2xl overflow-x-hidden rounded-xl bg-white shadow-xl max-h-[90vh] overflow-y-auto">
        <div class="border-b px-5 py-4">
            <h3 class="text-base font-semibold text-gray-800">{{ __('salesman.handover.title', ['name' => $from?->name]) }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('salesman.handover.rule') }}</p>
        </div>

        <div class="max-h-[60vh] space-y-4 overflow-y-auto px-5 py-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-base">{{ __('salesman.handover.to') }}</label>
                    <select wire:model.live="handoverToId" class="input-base">
                        <option value="">-</option>
                        @foreach($this->handoverCandidates as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->type_label }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">{{ __('salesman.handover.reason') }}</label>
                    <input wire:model="handoverReason" type="text" class="input-base" placeholder="{{ __('salesman.handover.reason_ph') }}" />
                </div>
            </div>

            @if($plan)
            {{-- 승계 표시를 켤지 — **판정을 문장으로 적는다.** 코드에만 있으면 사람이 반대로 조작한다(§8 #60) --}}
            <div class="rounded-lg border {{ $plan['marks_inherited'] ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-3 text-xs leading-relaxed text-gray-700">
                {{ $plan['marks_inherited'] ? __('salesman.handover.mark_on') : __('salesman.handover.mark_off') }}
            </div>

            {{-- 바이어 다중선택 — **나눠 넘기기**(jin 2026-08-27). 한 사람이 다 못 받으면 골라서 넘기고,
                 다시 눌러 남은 것을 다른 사람에게 넘긴다. 넘어간 바이어는 다음에 열면 목록에서 빠진다. --}}
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <p class="text-xs font-semibold text-gray-700">
                        {{ __('salesman.handover.pick') }}
                        <span class="ml-1 font-normal text-gray-500">{{ __('salesman.handover.picked', ['n' => count($handoverBuyerIds), 'total' => count($plan['candidates'])]) }}</span>
                    </p>
                    <div class="flex gap-2 text-[11px]">
                        <button wire:click="handoverSelectAll" class="text-violet-600 hover:underline">{{ __('salesman.handover.all') }}</button>
                        <button wire:click="handoverSelectNone" class="text-gray-400 hover:underline">{{ __('salesman.handover.none') }}</button>
                    </div>
                </div>
                <div class="max-h-44 space-y-0.5 overflow-y-auto rounded border border-gray-200 p-2">
                    @forelse($plan['candidates'] as $c)
                    <label wire:key="hb-{{ $c['id'] }}" class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-xs hover:bg-gray-50">
                        <input type="checkbox" wire:model.live="handoverBuyerIds" value="{{ $c['id'] }}" class="rounded" />
                        <span class="text-gray-800">{{ $c['name'] }}</span>
                        @if($c['in_progress'])
                        <span class="ml-auto text-[11px] text-gray-400">{{ __('salesman.handover.n_vehicles', ['n' => $c['in_progress']]) }}</span>
                        @endif
                    </label>
                    @empty
                    <p class="py-2 text-center text-xs text-gray-400">{{ __('salesman.handover.no_buyers') }}</p>
                    @endforelse
                </div>
                @php $rewrites = collect($plan['buyers'])->where('rewrites_history', true); @endphp
                @if($rewrites->count())
                <p class="mt-1.5 text-[11px] text-amber-700">{{ __('salesman.handover.rewrites', ['n' => $rewrites->count()]) }}</p>
                @endif
            </div>

            <div>
                <p class="mb-1.5 text-xs font-semibold text-gray-700">{{ __('salesman.handover.moving') }}</p>
                <div class="space-y-1.5 text-xs">
                    <div class="rounded border border-gray-200 p-2">
                        <span class="font-medium text-gray-800">{{ __('salesman.handover.buyers', ['n' => count($plan['buyers'])]) }}</span>
                        <span class="text-gray-400">·</span>
                        <span class="font-medium text-gray-800">{{ __('salesman.handover.vehicles', ['n' => count($plan['vehicles'])]) }}</span>
                        @if(count($plan['vehicles']))
                        <p class="mt-1 text-gray-500">{{ collect($plan['vehicles'])->pluck('vehicle_number')->take(20)->implode(' · ') }}{{ count($plan['vehicles']) > 20 ? ' …' : '' }}</p>
                        @endif
                        <p class="mt-1 text-[11px] text-gray-400">{{ __('salesman.handover.follows') }}</p>
                    </div>
                </div>
            </div>

            @if(count($plan['skipped']))
            <div>
                <p class="mb-1.5 text-xs font-semibold text-gray-700">{{ __('salesman.handover.skipping') }}</p>
                @foreach(collect($plan['skipped'])->groupBy('reason') as $reason => $rows)
                <div class="mb-1 rounded border border-gray-200 bg-gray-50 p-2 text-xs">
                    <span class="font-medium text-gray-800">{{ __('salesman.handover.skip.'.$reason, ['n' => $rows->count()]) }}</span>
                    <p class="mt-1 text-gray-500">{{ $rows->pluck('vehicle_number')->take(20)->implode(' · ') }}{{ $rows->count() > 20 ? ' …' : '' }}</p>
                </div>
                @endforeach
            </div>
            @endif
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
            <button wire:click="closeHandover" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="runHandover" class="btn-primary" @disabled(! $plan) wire:loading.attr="disabled" wire:target="runHandover">
                <span wire:loading.remove wire:target="runHandover">{{ __('salesman.handover.run') }}</span><span wire:loading wire:target="runHandover">{{ __('common.saving') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

</div>
