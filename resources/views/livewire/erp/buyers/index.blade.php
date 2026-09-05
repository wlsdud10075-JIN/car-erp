<?php

use App\Models\Buyer;
use App\Models\BuyerCashReceipt;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\SavingsStatus;
use App\Models\Setting;
use App\Support\SearchTerm;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    #[Url] public int $perPage = 10;
    // 회의확장씬 #2 Phase 3-1 (a) (2026-05-23) — 영업담당자 필터 + 영업담당자별 정렬.
    /** 담당자 미지정 필터 토큰 — 숫자 id 와 안 섞이게 문자열로. */
    public const SALESMAN_NONE = '__none';

    #[Url] public string $salesmanFilter = '';

    // ── 패널 ─────────────────────────────────────────────────────
    public bool   $showPanel  = false;
    public ?int   $editingId  = null;

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $name          = '';
    public string $country_id_str = '';
    // 회의확장씬 #5-1 (2026-05-22) — 영업담당자 직접 지정.
    public string $salesman_id_str = '';
    public string $contact_name  = '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public string $passport_id   = '';
    public string $address       = '';
    public string $memo          = '';
    public bool   $is_active     = true;
    // 2026-08-04 jin — 퇴사자 승계 바이어. 사내직원 정산 시 건당 5만원 고정(영구).
    //   정산 금액 직결이라 저장 시 canApprove() 재인가 — [관리] 이상(role 관리·업무관리자·최고관리자·시스템관리자).
    public bool   $is_inherited  = false;
    public string $inherited_from_salesman_id_str = '';
    public string $inherited_at  = '';
    // 2026-08-10 jin — 무담보 한도. 담보(선적 전 국내 차량)가 없어도 이 금액까지는 매입해준다.
    //   빈 값·0 = 미설정 → 기존 미수율 게이트 그대로. > 0 = 한도 소진 시 매입 차단.
    //   매입 락을 좌우하므로 저장 시 canApprove() 재인가 + 감사 기록 (is_inherited 와 같은 취급).
    public string $unsecured_limit_krw_str = '';

    //   🔒 바이어별 락 필요입금률(%) — **super 전용**. 빈 문자열 = 미설정(전역값 사용).
    //   ⚠️ '0' 은 유효값이다(= 필요입금 0% = 락 없음). 빈칸과 구분해야 한다 —
    //      0 을 미설정으로 취급하면 일부러 풀어준 바이어가 조용히 전역으로 돌아간다.
    public string $lock_shipping_entry_pct_str = '';

    public string $lock_purchase_registration_pct_str = '';

    // ── 컨사이니 탭 ────────────────────────────────────────────────
    public array  $consigneeList      = [];
    public bool   $showConsigneeForm  = false;
    public ?int   $editConsigneeId    = null;
    public string $cons_name          = '';
    public string $cons_country_id_str = '';
    public string $cons_contact_name  = '';
    public string $cons_contact_email = '';
    public string $cons_contact_phone = '';
    public string $cons_address       = '';
    public string $cons_memo          = '';
    public bool   $cons_is_active     = true;
    // 회의확장씬 #4 (2026-05-22) — Consignee ID 2컬럼 입력
    // ⚠️ cons_id_type 은 입력칸을 없앤 뒤에도 남겨둔다(jin 2026-08-06) — openConsignee 가 기존 값을
    //    읽어 담고 save 가 그대로 되쓰기 때문에, 지우면 기존에 저장된 ID 종류가 null 로 덮인다.
    public string $cons_id_type       = '';
    public string $cons_id_value      = '';
    // deep-interview 2026-05-28 Q1 — EORI/TAX 식별번호.
    public string $cons_eori_number   = '';
    public string $cons_tax_number    = '';

    // ── 적립금 탭 ──────────────────────────────────────────────────
    public array  $balances = [];   // ['USD' => 500.00, ...]
    public array  $txnList  = [];   // recent transactions (array)
    // 통화별 원화 환산 (FIFO 잔여 lot × 적립 시점 환율) — ['USD' => ['krw'=>..,'unrated'=>..], ...]
    public array  $balancesKrw = [];
    // 통화별 잔여 적립분 lot (선입선출 소진 반영) — ['USD' => [[at, remaining, exchange_rate, krw], ...]]
    public array  $lots = [];
    public string $txn_currency = 'USD';
    public string $txn_type     = 'EARNED';
    public string $txn_amount   = '';
    public string $txn_rate     = '';   // 적립 시점 환율 (수기 거래용, 선택)
    public string $txn_note     = '';

    // ── 현금 탭 (jin 2026-09-04) — 기획 docs/design/buyer-cash-ledger.md ────────────
    //   🚫 적립금과 다른 원장이다. 적립금 = 회사가 준 크레딧 / 현금 = 실제로 들어온 돈.
    //   🚨 여기엔 **환율이 없다**. 원화 환산은 판매잔금 행이 계속 담당한다 —
    //      두 곳에서 원화를 만들면 정산 환율이 갈린다.
    /** ['EUR' => ['received'=>, 'allocated'=>, 'remaining'=>], ...] */
    public array  $cashBalances    = [];
    /** 입금 목록(최근 순) + 각 입금의 배분 내역. */
    public array  $cashReceiptList = [];
    public string $cash_currency   = 'USD';
    public string $cash_date       = '';
    public string $cash_amount     = '';
    public string $cash_note       = '';

    // ─────────────────────────────────────────────────────────────

    /**
     * 회의확장씬 #2 개발예정 Phase 3-1 (c) (2026-05-23) — 바이어 미수금 게이지.
     * 분모: Σ(sale_total_amount × exchange_rate) — SKILLS §13 단일 출처.
     * 분자: Σ(sale_unpaid_amount_krw_cache) — Vehicle saving 훅 자동 갱신.
     * KRW 차량 또는 환율 미입력 차량은 분모·분자 모두 제외 (의미 없는 비율 방지).
     */
    #[Computed]
    public function creditScore(): ?array
    {
        // 🚨 **제안 전용**이다. 여기서 아무것도 저장하지 않는다 — [적용]을 눌러야 입력칸이 채워지고,
        //    그것도 [저장]을 눌러야 DB 로 간다. 자동 반영하면 점수가 조용히 내려가 선적이 막히는데
        //    영업은 이유를 모른다(BuyerCreditScore 주석 참조).
        // ⚠️ 목록에서 부르지 말 것 — 지급 행태가 차량별 집계라 N+1 이다. 편집 패널에서만.
        // 🔓 **열람은 관리 이상**(jin 2026-08-24). 실무자가 「이 바이어가 왜 막혔나」를 못 보면
        //    문의가 전부 시스템관리자에게 몰린다. 고치는 것은 여전히 super 뿐이다.
        if (! $this->editingId || ! auth()->user()?->canViewOperationLogs()) {
            return null;
        }
        $buyer = Buyer::find($this->editingId);

        return $buyer ? \App\Services\BuyerCreditScore::for($buyer) : null;
    }

    /** 권장 락 %를 **입력칸에만** 채운다. 저장은 사람이 [저장]을 눌러야 일어난다. */
    public function applyRecommendedLocks(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);   // 화면 노출은 편의일 뿐 (SKILLS §8 #26)
        $credit = $this->creditScore();
        if (! $credit || ! $credit['available']) {
            return;
        }
        $this->lock_shipping_entry_pct_str = (string) $credit['recommended']['shipping_entry'];
        $this->lock_purchase_registration_pct_str = (string) $credit['recommended']['purchase_registration'];
        $this->dispatch('notify', message: __('buyer.field.lock_applied'), type: 'success');
    }

    #[Computed]
    public function buyerReceivable(): ?array
    {
        if (! $this->editingId) {
            return null;
        }
        $buyer = Buyer::find($this->editingId);
        if (! $buyer) {
            return null;
        }

        // 단일 출처 — Buyer::computeReceivableGauge (매입 게이트와 동일 로직·임계, 숫자 일치 보장).
        //   ⚠️ 무담보 한도를 안 넘기면 화면 숫자와 게이트 판정이 갈린다(게이트는 receivableGauge() 경유).
        return Buyer::computeReceivableGauge(
            $buyer->vehicles()->with('purchaseBalancePayments')->get(),
            null,
            $buyer->effectiveUnsecuredLimit(),
        );
    }

    /**
     * 2026-07-08 — 바이어 목록 행 배경 게이지(차량관리 동일 방식). 현재 페이지 바이어만 배치 로딩(N+1 방지).
     * 반환: [buyer_id => Buyer::computeReceivableGauge()] (미수 데이터 있는 바이어만).
     */
    #[Computed]
    public function receivableGauges(): array
    {
        // 배치 로더는 Buyer::computeReceivableGaugesFor 단일 출처 — board 읽기 API 도 같은 걸 쓴다.
        //   컬럼 목록·무담보 한도 주입이 두 벌이 되면 화면과 board 숫자가 조용히 갈린다.
        return Buyer::computeReceivableGaugesFor(
            collect($this->buyers->items())->pluck('id')->all()
        );
    }

    /**
     * 2026-05-28 — 바이어별 누적 셀러부담액 (영업 협상 카드 — "우리가 그동안 이만큼 떠안았다").
     * 2026-06-16 — 송금수수료 + 손실(write_off) 합산. 표시는 통합 한 줄, 내역은 건수로만 구분.
     *   · 수수료 = FinalPayment type='fee' confirmed 의 amount_krw 합 (이미 KRW 환산됨)
     *   · 손실   = ReceivableHistory method='write_off' 의 amount × 환율 (KRW 차량은 amount 그대로)
     * 환율 미입력 외화는 KRW 산정 불가 → 제외 (수수료의 amount_krw null 제외와 동일 정책).
     */
    #[Computed]
    public function buyerFees(): ?array
    {
        if (! $this->editingId) {
            return null;
        }

        // 수수료 — 이미 KRW 환산된 amount_krw 합산
        $feeRows = FinalPayment::query()
            ->whereHas('vehicle', fn ($q) => $q->where('buyer_id', $this->editingId))
            ->where('type', 'fee')
            ->whereNotNull('confirmed_at')
            ->get(['amount_krw']);
        $feeKrw = (int) $feeRows->sum('amount_krw');
        $feeCount = $feeRows->count();

        // 손실(셀러부담) — 차량 통화 × 환율로 KRW 환산
        $woRows = ReceivableHistory::query()
            ->where('method', 'write_off')
            ->whereHas('vehicle', fn ($q) => $q->where('buyer_id', $this->editingId))
            ->with('vehicle:id,currency,exchange_rate')
            ->get();
        $woKrw = 0;
        $woCount = 0;
        foreach ($woRows as $r) {
            $v = $r->vehicle;
            if (! $v) {
                continue;
            }
            $krw = $v->currency === 'KRW'
                ? (float) $r->amount
                : (float) $r->amount * (float) ($v->exchange_rate ?? 0);
            if ($krw <= 0) {
                continue;   // 환율 미입력 외화 → 제외
            }
            $woKrw += $krw;
            $woCount++;
        }

        $totalKrw = $feeKrw + (int) $woKrw;
        if ($totalKrw <= 0) {
            return null;
        }

        return [
            'total_krw' => $totalKrw,
            'fee_count' => $feeCount,
            'wo_count' => $woCount,
            'count' => $feeCount + $woCount,
        ];
    }

    public function searchNow(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    #[Computed]
    public function buyers()
    {
        // 회의확장씬 #11 + #2 (2026-05-22) — 영업/[관리] 본인 바이어 솔팅.
        // E-2 보강 (2026-05-22) — buyers.salesman_id 직접 관계 우선 + vehicles 간접 fallback (운영 호환).
        //   - 직접: buyer.salesman_id IN ids
        //   - 간접 fallback: vehicle.salesman_id IN ids (기존 운영 데이터 — buyer.salesman_id NULL row 호환)
        //   - 운영자가 UI 에서 buyer.salesman_id 일괄 입력하면 자연스럽게 직접 관계로 수렴
        // admin/super: 전체 (분기 X)
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Buyer::query()
            ->with(['country', 'salesman'])
            ->when($restrictToOwnSalesman, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('salesman_id', $user->salesman->id)
                ->orWhereHas('vehicles', fn ($q3) => $q3->where('salesman_id', $user->salesman->id))
            ))
            ->when($restrictToManagerScope, fn ($q) => $q->where(fn ($q2) => $q2
                ->whereIn('salesman_id', $managerScopeSalesmanIds)
                ->orWhereHas('vehicles', fn ($q3) => $q3->whereIn('salesman_id', $managerScopeSalesmanIds))
            ))
            // 담당자 필터 — `__none` 은 **미지정만**(jin 2026-08-27). 숫자 id 와 섞이지 않는 토큰이라
            //   누가 나중에 바이어를 그 id 로 넣어도 충돌하지 않는다.
            //   퍼사 승계 뒤에 「주인 없는 바이어」를 찾는 자리다 — 안 보이면 영영 남는다.
            ->when($this->salesmanFilter === self::SALESMAN_NONE, fn ($q) => $q->whereNull('salesman_id'))
            ->when($this->salesmanFilter !== '' && $this->salesmanFilter !== self::SALESMAN_NONE,
                fn ($q) => $q->where('salesman_id', $this->salesmanFilter))
            ->when(SearchTerm::of($this->search), fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', SearchTerm::like($this->search))
                ->orWhere('contact_email', 'like', SearchTerm::like($this->search))
                ->orWhere('contact_name', 'like', SearchTerm::like($this->search))
            ))
            // 회의확장씬 #2 (2026-05-23) — 영업담당자별 그룹 정렬 (담당자별 묶음 + 이름순).
            ->orderByRaw('salesman_id IS NULL ASC')   // NULL 마지막
            ->orderBy('salesman_id')
            ->orderBy('name')
            ->paginate($this->perPage);
    }

    // 회의확장씬 #5-1 (2026-05-22) — 바이어 폼 영업담당자 select 옵션.
    // 영업 role 본인은 자동 채움. [관리] 본인 담당 영업만 노출 (vehicles/index salesmen() 패턴 차용).
    #[Computed]
    public function salesmen()
    {
        $q = \App\Models\Salesman::where('is_active', true)->orderBy('name');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get(['id', 'name']);
    }

    #[Computed]
    public function countries()
    {
        return Country::orderBy('name')->get();
    }

    // ── 패널 열기/닫기 ────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $buyer = Buyer::findOrFail($id);
        $this->editingId     = $id;
        $this->name          = $buyer->name;
        $this->country_id_str = $buyer->country_id ? (string)$buyer->country_id : '';
        $this->salesman_id_str = $buyer->salesman_id ? (string) $buyer->salesman_id : '';
        $this->contact_name  = $buyer->contact_name  ?? '';
        $this->contact_email = $buyer->contact_email ?? '';
        $this->contact_phone = $buyer->contact_phone ?? '';
        $this->passport_id   = $buyer->passport_id   ?? '';
        $this->address       = $buyer->address       ?? '';
        $this->memo          = $buyer->memo          ?? '';
        $this->is_active     = $buyer->is_active;
        $this->is_inherited  = (bool) $buyer->is_inherited;
        $this->inherited_from_salesman_id_str = $buyer->inherited_from_salesman_id ? (string) $buyer->inherited_from_salesman_id : '';
        $this->inherited_at  = $buyer->inherited_at?->format('Y-m-d') ?? '';
        $this->unsecured_limit_krw_str = ($buyer->unsecured_limit_krw ?? 0) > 0
            ? number_format((int) $buyer->unsecured_limit_krw) : '';
        // NULL 만 빈칸. 0 은 '0' 으로 실어야 "락 없음"이 화면에서 살아난다.
        $this->lock_shipping_entry_pct_str = $buyer->lock_shipping_entry_pct !== null
            ? (string) $buyer->lock_shipping_entry_pct : '';
        $this->lock_purchase_registration_pct_str = $buyer->lock_purchase_registration_pct !== null
            ? (string) $buyer->lock_purchase_registration_pct : '';

        $this->loadConsignees($id);
        $this->loadSavings($id);
        $this->loadCash($id);
        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
        $this->showConsigneeForm = false;
    }

    // ── 바이어 저장 ───────────────────────────────────────────────
    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            // 회의확장씬 #5-1 (2026-05-22) — 영업담당자 nullable + exists 검증.
            'salesman_id_str' => 'nullable|integer|exists:salesmen,id',
            'inherited_from_salesman_id_str' => 'nullable|integer|exists:salesmen,id',
            'inherited_at' => 'nullable|date',
        ]);

        // 영업 role 신규 등록 시 본인 salesman 자동 채움 (vehicles/index L1240~1241 패턴 대칭).
        $user = auth()->user();
        if ($this->salesman_id_str === '' && ! $this->editingId
            && $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman) {
            $this->salesman_id_str = (string) $user->salesman->id;
        }

        $data = [
            'name'          => $this->name,
            'country_id'    => $this->country_id_str !== '' ? (int) $this->country_id_str : null,
            'salesman_id'   => $this->salesman_id_str !== '' ? (int) $this->salesman_id_str : null,
            'contact_name'  => $this->contact_name  ?: null,
            'contact_email' => $this->contact_email ?: null,
            'contact_phone' => $this->contact_phone ?: null,
            'passport_id'   => $this->passport_id   ?: null,
            'address'       => $this->address       ?: null,
            'memo'          => $this->memo           ?: null,
            'is_active'     => $this->is_active,
        ];

        // 승계 표시는 정산액(건당 5만)을 바꾸므로 화면 노출과 별개로 저장 시점 재인가 (SKILLS §8 #26).
        if ($user?->canApprove()) {
            $data['is_inherited'] = $this->is_inherited;
            $data['inherited_from_salesman_id'] = $this->is_inherited && $this->inherited_from_salesman_id_str !== ''
                ? (int) $this->inherited_from_salesman_id_str : null;
            // 해제 시 부속 정보도 함께 비운다 — "승계 ON 일 때만 원담당자·승계일 존재" 불변식.
            $data['inherited_at'] = $this->is_inherited && $this->inherited_at !== '' ? $this->inherited_at : null;
        }

        // 🔒 락 기준선 — **super 전용** (jin 2026-08-21). 화면 노출은 편의일 뿐이라 저장 시 재인가(SKILLS §8 #26).
        //   실무자가 자기가 막히면 자기가 푸는 걸 막는 게 이 권한 분리의 목적이다.
        if ($user?->isSuperAdmin()) {
            // 무담보 한도 — 매입 판정을 미수율에서 금액으로 바꾸므로 락 % 와 같은 무게다(구: canApprove).
            //   빈 값은 null(미설정). 0 을 넣어도 hasUnsecuredLimit() 이 false 라 기존 동작.
            $limit = (int) preg_replace('/[^0-9]/', '', $this->unsecured_limit_krw_str);
            $data['unsecured_limit_krw'] = $limit > 0 ? $limit : null;

            // 🚨 빈칸만 null(미설정). '0' 은 유효값(필요입금 0% = 락 없음)이라 그대로 저장한다.
            foreach ([
                'lock_shipping_entry_pct' => $this->lock_shipping_entry_pct_str,
                'lock_purchase_registration_pct' => $this->lock_purchase_registration_pct_str,
            ] as $column => $raw) {
                $raw = trim($raw);
                $data[$column] = $raw === '' ? null : max(0, min(100, (int) $raw));
            }
        }

        if ($this->editingId) {
            $buyer = Buyer::findOrFail($this->editingId);
            $wasInherited = (bool) $buyer->is_inherited;
            $wasLimit = (int) ($buyer->unsecured_limit_krw ?? 0);
            $wasLocks = [
                'lock_shipping_entry_pct' => $buyer->lock_shipping_entry_pct,
                'lock_purchase_registration_pct' => $buyer->lock_purchase_registration_pct,
            ];
            $buyer->update($data);
            // 승계 표시는 정산액(건당 5만)을 바꾸므로 변경 이력을 남긴다 (Buyer 엔 감사 훅이 없어 여기서 직접).
            if (array_key_exists('is_inherited', $data) && $wasInherited !== $this->is_inherited) {
                \App\Models\AuditLog::recordChange($buyer, 'is_inherited', $wasInherited, $this->is_inherited);
            }
            // 무담보 한도는 매입 락 기준선이라 변경 이력 필수 — 누가 언제 얼마로 올렸는지가 감사 핵심.
            if (array_key_exists('unsecured_limit_krw', $data) && $wasLimit !== (int) ($data['unsecured_limit_krw'] ?? 0)) {
                \App\Models\AuditLog::recordChange($buyer, 'unsecured_limit_krw', $wasLimit, (int) ($data['unsecured_limit_krw'] ?? 0));
            }
            // 🔒 락 기준선 변경은 **유일한 견제 수단**이다 — super 전용이라 사유 입력은 두지 않고 기록만 남긴다.
            //   Buyer 엔 감사 훅이 없어(AUDITED_COLUMNS 대상 아님) 여기서 직접 남겨야 한다.
            //   ⚠️ 0 ↔ null 은 뜻이 완전히 다르다(락 없음 vs 전역값) → 느슨한 비교로 뭉개지 말 것.
            foreach (['lock_shipping_entry_pct', 'lock_purchase_registration_pct'] as $column) {
                if (array_key_exists($column, $data) && $wasLocks[$column] !== $data[$column]) {
                    \App\Models\AuditLog::recordChange($buyer, $column, $wasLocks[$column], $data[$column]);
                }
            }
        } else {
            $buyer = Buyer::create($data);
            $this->editingId = $buyer->id;
        }

        unset($this->buyers);
        // 2026-07-09 jin — 등록/저장 시 시각 피드백 + 패널 자동 닫기 (사용자등록 패턴 대칭, session flash 불안정 제거).
        $this->close();
        $this->dispatch('notify', message: __('buyer.saved'), type: 'success');
    }

    public function delete(int $id): void
    {
        Buyer::findOrFail($id)->delete();
        unset($this->buyers);
        $this->dispatch('notify', message: __('buyer.deleted'), type: 'success');
    }

    // ── 컨사이니 ──────────────────────────────────────────────────
    private function loadConsignees(int $buyerId): void
    {
        $this->consigneeList = Consignee::where('buyer_id', $buyerId)
            ->with('country')
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'country_name'  => $c->country?->name ?? '-',
                'contact_name'  => $c->contact_name ?? '',
                'contact_email' => $c->contact_email ?? '',
                'is_active'     => $c->is_active,
            ])
            ->toArray();
    }

    public function openConsigneeForm(?int $id = null): void
    {
        $this->editConsigneeId = $id;
        if ($id) {
            $c = Consignee::findOrFail($id);
            $this->cons_name           = $c->name;
            $this->cons_country_id_str = $c->country_id ? (string)$c->country_id : '';
            $this->cons_id_type        = $c->id_type      ?? '';
            $this->cons_id_value       = $c->id_value     ?? '';
            $this->cons_eori_number    = $c->eori_number  ?? '';
            $this->cons_tax_number     = $c->tax_number   ?? '';
            $this->cons_contact_name   = $c->contact_name  ?? '';
            $this->cons_contact_email  = $c->contact_email ?? '';
            $this->cons_contact_phone  = $c->contact_phone ?? '';
            $this->cons_address        = $c->address       ?? '';
            $this->cons_memo           = $c->memo          ?? '';
            $this->cons_is_active      = $c->is_active;
        } else {
            $this->cons_name = $this->cons_country_id_str = $this->cons_contact_name
                = $this->cons_contact_email = $this->cons_contact_phone
                = $this->cons_address = $this->cons_memo
                = $this->cons_id_type = $this->cons_id_value
                = $this->cons_eori_number = $this->cons_tax_number = '';
            $this->cons_is_active = true;
        }
        $this->showConsigneeForm = true;
    }

    public function closeConsigneeForm(): void
    {
        $this->showConsigneeForm = false;
        $this->editConsigneeId = null;
        // 현금 탭 — 신규 등록 패널을 열 때 직전 바이어의 입금이 남아 보이면 안 된다.
        $this->cashBalances = $this->cashReceiptList = [];
        $this->cash_amount = $this->cash_note = '';
        $this->cash_currency = 'USD';
        $this->cash_date = now()->toDateString();
    }

    public function saveConsignee(): void
    {
        // ID 종류 선택은 화면에서 제거(jin 2026-08-06) — 검증도 id_value 만 본다.
        // attribute 라벨은 lang/{ko,en}/validation.php attributes 전역 맵에서 해석 (양쪽 언어 대응).
        $this->validate([
            'cons_name' => 'required|string|max:100',
            'cons_id_value' => 'nullable|string|max:50',
        ]);

        if (! $this->editingId) {
            $this->addError('cons_name', '바이어를 먼저 저장해주세요.');

            return;
        }

        $data = [
            'buyer_id'      => $this->editingId,
            'name'          => $this->cons_name,
            'country_id'    => $this->cons_country_id_str !== '' ? (int) $this->cons_country_id_str : null,
            'id_type'       => $this->cons_id_type  ?: null,
            'id_value'      => $this->cons_id_value ?: null,
            'eori_number'   => $this->cons_eori_number ?: null,
            'tax_number'    => $this->cons_tax_number  ?: null,
            'contact_name'  => $this->cons_contact_name  ?: null,
            'contact_email' => $this->cons_contact_email ?: null,
            'contact_phone' => $this->cons_contact_phone ?: null,
            'address'       => $this->cons_address       ?: null,
            'memo'          => $this->cons_memo          ?: null,
            'is_active'     => $this->cons_is_active,
        ];

        if ($this->editConsigneeId) {
            Consignee::findOrFail($this->editConsigneeId)->update($data);
        } else {
            Consignee::create($data);
        }

        $this->loadConsignees($this->editingId);
        $this->closeConsigneeForm();
        $this->dispatch('notify', message: __('buyer.cons.saved'), type: 'success');
    }

    public function deleteConsignee(int $id): void
    {
        Consignee::findOrFail($id)->delete();
        if ($this->editingId) $this->loadConsignees($this->editingId);
        $this->dispatch('notify', message: __('buyer.cons.deleted'), type: 'success');
    }

    // ── 적립금 ────────────────────────────────────────────────────
    private function loadSavings(int $buyerId): void
    {
        // 통화별 최신 잔액
        $this->balances = SavingsStatus::where('buyer_id', $buyerId)
            ->orderByDesc('id')
            ->get()
            ->unique('currency')
            ->pluck('balance', 'currency')
            ->toArray();

        // 선입선출 원장 (jin 2026-07-29) — 환율은 적립 시점 값. SavingsLedger 단일 출처.
        $ledger = app(\App\Services\SavingsLedger::class);
        $this->balancesKrw = [];
        $this->lots = [];
        $details = [];
        foreach (array_keys($this->balances) as $cur) {
            $this->balancesKrw[$cur] = $ledger->balanceKrw($buyerId, $cur);
            $this->lots[$cur] = $ledger->lots($buyerId, $cur)
                ->reject(fn ($l) => $l['consumed'])          // 다 쓴 적립분은 다음 차감 안내에서 제외
                ->values()
                ->all();
            $details += $ledger->rowDetails($buyerId, $cur);   // row_id 는 전역 유일이라 통화별 병합 안전
        }

        // 최근 50건 거래내역 — 각 행에 "어느 적립분을 얼마 썼고 그 적립분 잔여는 얼마"를 붙인다.
        $this->txnList = SavingsStatus::where('buyer_id', $buyerId)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'currency'         => $t->currency,
                'transaction_type' => $t->transaction_type,
                'savings'          => $t->savings,
                'balance'          => $t->balance,
                'exchange_rate'    => $t->exchange_rate !== null ? (float) $t->exchange_rate : null,
                'note'             => $t->note ?? '',
                'created_at'       => $t->created_at->format('Y-m-d H:i'),
                'fifo'             => $details[$t->id] ?? null,
            ])
            ->toArray();
    }

    public function addSavingsTransaction(): void
    {
        $this->validate([
            'txn_amount' => 'required|numeric|min:0.01',
            'txn_rate'   => 'nullable|numeric|min:0',
        ]);

        if (!$this->editingId) return;

        $amount = (float) $this->txn_amount;
        // 적립 시점 환율 — KRW 는 자명하게 1. 미입력이면 null(화면에 "환율 미입력"으로 표시).
        $rate = $this->txn_currency === 'KRW'
            ? 1.0
            : (($this->txn_rate !== '' && (float) $this->txn_rate > 0) ? (float) $this->txn_rate : null);
        $ok = false;

        DB::transaction(function () use ($amount, $rate, &$ok) {
            $latest = SavingsStatus::where('buyer_id', $this->editingId)
                ->where('currency', $this->txn_currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $currentBalance = $latest?->balance ?? 0.0;
            $savings = ($this->txn_type === 'USED') ? -$amount : $amount;
            $newBalance = $currentBalance + $savings;

            if ($newBalance < 0) {
                $this->addError('txn_amount', "잔액 부족 (현재: {$currentBalance} {$this->txn_currency})");
                return;
            }

            SavingsStatus::create([
                'buyer_id'         => $this->editingId,
                'currency'         => $this->txn_currency,
                'exchange_rate'    => $rate,
                'transaction_type' => $this->txn_type,
                'savings'          => $savings,
                'balance'          => $newBalance,
                'note'             => $this->txn_note ?: null,
            ]);
            $ok = true;
        });

        if (! $ok) {
            return;
        }

        $this->txn_amount = '';
        $this->txn_rate   = '';
        $this->txn_note   = '';
        $this->loadSavings($this->editingId);
        $this->dispatch('notify', message: __('buyer.savings.added'), type: 'success');
    }

    public function cancelSavingsTransaction(int $id): void
    {
        if (!$this->editingId) return;

        $original = SavingsStatus::where('buyer_id', $this->editingId)->findOrFail($id);
        $ok = false;

        DB::transaction(function () use ($original, &$ok) {
            $latest = SavingsStatus::where('buyer_id', $this->editingId)
                ->where('currency', $original->currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $cancelledSavings = -$original->savings;
            $newBalance = ($latest?->balance ?? 0) + $cancelledSavings;

            if ($newBalance < 0) {
                $this->dispatch('notify', message: __('buyer.savings.neg_balance'), type: 'error');
                return;
            }

            // 사용(−)을 취소하면 이 행이 양수 = 다시 유입 lot 이 된다 → 원거래의 적립 시점 환율을 승계.
            SavingsStatus::create([
                'buyer_id'                => $this->editingId,
                'currency'               => $original->currency,
                'exchange_rate'          => $original->exchange_rate,
                'transaction_type'       => 'CANCELLED',
                'savings'                => $cancelledSavings,
                'balance'                => $newBalance,
                'original_transaction_id' => $original->id,
                'note'                   => '취소: ' . ($original->note ?? "#{$original->id}"),
            ]);
            $ok = true;
        });

        if (! $ok) {
            return;
        }

        $this->loadSavings($this->editingId);
        $this->dispatch('notify', message: __('buyer.savings.cancelled'), type: 'success');
    }

    // ── 현금 탭 (jin 2026-09-04) — 기획 docs/design/buyer-cash-ledger.md ──────────

    /**
     * 이 회사가 현금 원장을 쓰는가. **화면 노출과 저장이 같은 출처를 본다** —
     * 갈리면 「탭은 보이는데 저장이 안 되는」 형태가 된다(SKILLS §8 #60).
     */
    #[Computed]
    public function cashEnabled(): bool
    {
        return Setting::buyerCashEnabled();
    }

    /** 기재 권한 = 재무·관리·업무관리자(+admin·super) — 판매잔금 재무확정과 같은 권한(jin). */
    #[Computed]
    public function canManageCash(): bool
    {
        return (bool) auth()->user()?->canConfirmFinance();
    }

    private function loadCash(?int $buyerId): void
    {
        $this->cashBalances = [];
        $this->cashReceiptList = [];
        if (! $buyerId || ! Setting::buyerCashEnabled()) {
            return;
        }
        // 수령일 기본값 = 오늘. openEdit 은 resetForm 을 안 타므로 여기서 채운다.
        $this->cash_date = $this->cash_date ?: now()->toDateString();

        $receipts = BuyerCashReceipt::where('buyer_id', $buyerId)
            ->with(['allocations.vehicle:id,vehicle_number', 'creator:id,name'])
            ->fifo()
            ->get();

        // 통화별 합계 — 게이트가 쓰는 BuyerCashReceipt::balanceFor 와 **같은 뺄셈**이라 숫자가 안 갈린다.
        foreach ($receipts as $r) {
            $cur = $r->currency;
            $this->cashBalances[$cur] ??= ['received' => 0.0, 'allocated' => 0.0, 'remaining' => 0.0];
            $this->cashBalances[$cur]['received'] += (float) $r->amount;
            $this->cashBalances[$cur]['allocated'] += $r->allocated_amount;
            $this->cashBalances[$cur]['remaining'] += $r->remaining_amount;
        }

        // 「다음에 여기서 나갑니다」 — FIFO 상 남은 게 있는 첫 건(통화별).
        //   적립금 탭의 next_out 칩과 같은 취지: 소진 순서가 화면에 보여야 사람이 예측할 수 있다.
        $nextIds = [];
        foreach ($receipts as $r) {
            if ($r->has_remaining && ! isset($nextIds[$r->currency])) {
                $nextIds[$r->currency] = $r->id;
            }
        }

        // 목록은 최근 순(사람은 방금 들어온 돈을 먼저 본다). 소진 순서는 위 칩이 알려준다.
        $this->cashReceiptList = $receipts
            ->sortByDesc(fn (BuyerCashReceipt $r) => [$r->received_date->format('Y-m-d'), $r->id])
            ->values()
            ->map(fn (BuyerCashReceipt $r) => [
                'id' => $r->id,
                'received_date' => $r->received_date->format('Y-m-d'),
                'currency' => $r->currency,
                'amount' => (float) $r->amount,
                'allocated' => $r->allocated_amount,
                'remaining' => $r->remaining_amount,
                'note' => $r->note ?? '',
                'by' => $r->creator?->name ?? '',
                'is_next' => ($nextIds[$r->currency] ?? null) === $r->id,
                'uses' => $r->allocations
                    ->map(fn ($a) => [
                        'vehicle_number' => $a->vehicle?->vehicle_number ?? '-',
                        'amount' => (float) $a->amount,
                    ])->all(),
                // 좁은 패널에서 여러 줄이 잘려도 호버로 전문이 보이게(적립금 탭과 같은 방식).
                'uses_title' => $r->allocations
                    ->map(fn ($a) => ($a->vehicle?->vehicle_number ?? '-').' '.number_format((float) $a->amount, 2))
                    ->implode(' / '),
            ])->all();
    }

    public function addCashReceipt(): void
    {
        // 화면 노출은 편의일 뿐 — 변경 액션은 매번 재인가한다(SKILLS §8 #26).
        abort_unless(auth()->user()?->canConfirmFinance(), 403);
        if (! $this->editingId || ! Setting::buyerCashEnabled()) {
            return;
        }

        $this->validate([
            'cash_date' => 'required|date',
            'cash_currency' => 'required|in:USD,JPY,EUR,GBP,CNY,KRW',
            'cash_amount' => 'required|numeric|min:0.01',
        ]);

        $receipt = BuyerCashReceipt::create([
            'buyer_id' => $this->editingId,
            'currency' => $this->cash_currency,
            'received_date' => $this->cash_date,
            'amount' => (float) $this->cash_amount,
            'note' => $this->cash_note ?: null,
            'created_by' => auth()->id(),
        ]);
        \App\Models\AuditLog::recordEvent($receipt, 'buyer_cash_receipt_added');

        $this->cash_amount = '';
        $this->cash_note = '';
        $this->loadCash($this->editingId);
        $this->dispatch('notify', message: __('buyer.cash.added'), type: 'success');
    }

    public function deleteCashReceipt(int $id): void
    {
        abort_unless(auth()->user()?->canConfirmFinance(), 403);
        if (! $this->editingId) {
            return;
        }
        // 바이어 스코프 재확인 — public 프로퍼티로 오는 id 를 그대로 믿지 않는다.
        $receipt = BuyerCashReceipt::where('buyer_id', $this->editingId)->findOrFail($id);

        // 🚨 이미 배분된 입금은 못 지운다. 지우면 cascade 로 **배분 행만** 사라지고 판매잔금은
        //    그대로 남아 미수와 현금이 어긋난다. 되돌리기는 「그 판매잔금 행을 지우는 것」이다.
        if ($receipt->allocations()->exists()) {
            $this->dispatch('notify', message: __('buyer.cash.delete_blocked'), type: 'error');

            return;
        }

        \App\Models\AuditLog::recordEvent($receipt, 'buyer_cash_receipt_deleted');
        $receipt->delete();
        $this->loadCash($this->editingId);
        $this->dispatch('notify', message: __('buyer.cash.deleted'), type: 'success');
    }

    private function resetForm(): void
    {
        $this->name = $this->country_id_str = $this->salesman_id_str = $this->contact_name
            = $this->contact_email = $this->contact_phone = $this->passport_id
            = $this->address = $this->memo = '';
        $this->is_active = true;
        $this->consigneeList = $this->balances = $this->txnList = [];
        $this->txn_amount = $this->txn_note = '';
        $this->txn_currency = 'USD';
        $this->txn_type = 'EARNED';
        $this->showConsigneeForm = false;
        $this->editConsigneeId = null;
    }
}; ?>

<div wire:poll.30s>
{{-- 토스트 = 전역 @notify.window 리스너 (sidebar.blade.php) 사용 — dispatch('notify') --}}

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('buyer.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('common.total', ['count' => $this->buyers->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('buyer.create_btn') }}
        </button>
    </div>
</div>

{{-- 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('buyer.search_ph') }}"
           class="input-filter w-64" />
    {{-- 회의확장씬 #2 Phase 3-1 (a) (2026-05-23) — 영업담당자 select 필터 --}}
    <select wire:model.live="salesmanFilter" class="input-filter">
        <option value="">{{ __('buyer.all_salesmen') }}</option>
        <option value="{{ self::SALESMAN_NONE }}">{{ __('buyer.no_salesman_filter') }}</option>
        @foreach($this->salesmen as $sm)
            <option value="{{ $sm->id }}">{{ $sm->name }}</option>
        @endforeach
    </select>
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
</div>

{{-- 테이블 --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">{{ __('buyer.col_name') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.contact') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.country') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.email') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.phone') }}</th>
                <th class="pb-2 pr-4 font-medium">{{ __('common.status') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @php $gauges = $this->receivableGauges; @endphp
            @forelse($this->buyers as $b)
            @php $bg = $gauges[$b->id] ?? null; @endphp
            {{-- 2026-07-08 — 바이어별 미수금 행 배경 게이지 (차량관리와 동일: app.js tr[data-ratio]). --}}
            <tr class="cursor-pointer {{ $bg ? '' : 'hover:bg-gray-50' }}" wire:click="openEdit({{ $b->id }})"
                @if($bg)
                    data-ratio="{{ number_format($bg['ratio'], 6, '.', '') }}"
                    data-unpaid="{{ $bg['unpaid_krw'] }}"
                    data-total="{{ $bg['total_krw'] }}"
                    data-currency="원"
                @endif
            >
                <td class="py-3 pr-4 font-medium text-gray-800">{{ $b->name }}</td>
                <td class="py-3 pr-4 text-gray-500">
                    @if($b->salesman)
                        <span class="badge badge-blue">{{ $b->salesman->name }}</span>
                    @else
                        <span class="text-gray-300">{{ __('buyer.unassigned') }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->country?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_email ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $b->contact_phone ?? '-' }}</td>
                <td class="py-3 pr-4">
                    <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">
                        {{ $b->is_active ? __('common.active') : __('common.inactive') }}
                    </span>
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $b->id }})"
                            wire:confirm="{{ __('buyer.delete_confirm', ['name' => $b->name]) }}"
                            class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="py-12 text-center text-sm text-gray-400">{{ __('buyer.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- 모바일 --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->buyers as $b)
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $b->id }})">
        <div>
            <div class="font-medium text-gray-800">{{ $b->name }}</div>
            <div class="text-xs text-gray-500">{{ $b->country?->name ?? '' }} {{ $b->contact_email ? '· '.$b->contact_email : '' }}</div>
        </div>
        <span class="badge {{ $b->is_active ? 'badge-green' : 'badge-gray' }}">{{ $b->is_active ? __('common.active') : __('common.inactive') }}</span>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('buyer.empty') }}</div>
    @endforelse
</div>

<div>{{ $this->buyers->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
{{-- 큐 18: close confirm — 기존 tab Alpine과 dirty 추적 병합 --}}
<div x-data="{
    tab: 'basic',
    dirty: false,
    confirmOpen: false,
    attemptClose() {
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" @keyup.escape.window="attemptClose()">
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[580px]"
     @input="dirty = true" @change="dirty = true">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('buyer.edit_title') : __('buyer.create_title') }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 검증 에러 요약 (2026-07-09 jin — 저장 눌러도 반응 없어 보이던 문제: 실은 검증 실패라 상단에 노출) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <p class="text-xs font-semibold text-red-700">{{ __('common.error_box_title') }}</p>
        <ul class="mt-1 space-y-0.5 text-xs text-red-600">
            @foreach($errors->all() as $msg)
            <li>· {{ $msg }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- 탭 --}}
    <div class="flex border-b px-5">
        {{-- 현금 탭은 토글 ON 인 회사에만 뜬다 — 목록 자체를 갈아서 「보이는데 안 되는」 상태를 안 만든다. --}}
        @foreach($this->cashEnabled ? ['basic','consignees','savings','cash'] : ['basic','consignees','savings'] as $k)
        <button @click="tab='{{ $k }}'"
                :class="tab==='{{ $k }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="px-4 py-3 text-sm font-medium transition flex-shrink-0">{{ __('buyer.tab.'.$k) }}</button>
        @endforeach
    </div>

    {{-- 탭 컨텐츠 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5">

        {{-- ── 기본정보 --}}
        <div x-show="tab==='basic'" x-cloak>

            {{-- 회의확장씬 Phase 3-1 (c) (2026-05-23) — 바이어 미수금 게이지 (기존 차량 있는 경우만) --}}
            @if($this->buyerReceivable)
            @php $br = $this->buyerReceivable; @endphp
            <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1.5">
                    <span class="font-semibold text-gray-700">{{ __('buyer.receivable.title') }}</span>
                    <span>{{ __('buyer.receivable.summary', ['count' => $br['vehicle_count'], 'amount' => number_format($br['total_krw'])]) }}</span>
                </div>
                {{-- 2026-07-08 jin — 목록/차량관리 행 게이지와 동일하게 통일: '입금률' 채움(반대방향)은 헷갈려서
                     미수 비율만큼 채우고 app.js ratioToColor 와 같은 신호색(초록→노랑→빨강)으로 표시. --}}
                @php
                    $r = max(0, min(1, $br['ratio']));
                    $hue = $r <= 0.5 ? 120 - ($r / 0.5) * 70 : 50 - (($r - 0.5) / 0.5) * 50;   // app.js ratioToColor 동일
                @endphp
                <div class="h-3 bg-white rounded-full overflow-hidden border border-gray-200">
                    @if($br['unpaid_krw'] <= 0)
                    <div class="h-full" style="width:100%; background: hsla(120,65%,50%,.45)"></div>
                    @else
                    <div class="h-full" style="width: {{ number_format($r * 100, 2, '.', '') }}%; background: hsla({{ round($hue) }},65%,50%,.6)"></div>
                    @endif
                </div>
                <div class="mt-1.5 flex items-center justify-between text-xs">
                    @if($br['unpaid_krw'] <= 0)
                    <span class="font-medium text-emerald-700">{{ __('buyer.receivable.fully_paid') }}</span>
                    @else
                    <span class="font-medium text-gray-800">{{ __('buyer.receivable.unpaid', ['amount' => number_format($br['unpaid_krw'])]) }}</span>
                    <span class="font-medium" style="color: hsl({{ round($hue) }},70%,38%)">{{ __('buyer.receivable.unpaid_ratio', ['pct' => number_format($r * 100, 1)]) }}</span>
                    @endif
                </div>
                {{-- 매입 가능 금액 (jin 2026-07-29) — 입금액 × 50% 중 매입 지급으로 쓰고 남은 액수.
                     순수 표시용이라 아무것도 차단하지 않는다(매입등록 락은 ratio 로 별도 판정). --}}
                @if(($br['limit_krw'] ?? 0) > 0)
                @php $depAvail = $br['available_krw']; @endphp
                {{-- 계산 과정을 그대로 보여준다 — 한 줄에 숫자를 몰아넣으면 '사용'이 뭘 뜻하는지 안 보인다(jin 2026-07-29). --}}
                <div class="mt-2 rounded-md border border-violet-200 bg-violet-50/70 px-2.5 py-2">
                    <div class="space-y-1 text-[11px]">
                        <div class="flex items-center justify-between text-violet-700">
                            <span>{{ __('buyer.receivable.room_received', ['pct' => $br['deposit_pct']]) }}</span>
                            <span class="font-mono">₩{{ number_format($br['limit_krw']) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-violet-700">
                            <span>{{ __('buyer.receivable.room_spent') }}</span>
                            <span class="font-mono">− ₩{{ number_format($br['used_krw']) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-violet-200 pt-1">
                            <span class="text-xs font-semibold text-violet-800">{{ __('buyer.receivable.deposit_title') }}</span>
                            <span class="font-mono text-sm font-bold {{ $depAvail > 0 ? 'text-violet-900' : 'text-red-600' }}">₩{{ number_format($depAvail) }}</span>
                        </div>
                    </div>
                    <div class="mt-1.5 text-[11px] text-violet-400">
                        {{ __('buyer.receivable.room_basis', ['paid' => number_format($br['paid_krw']), 'count' => $br['vehicle_count']]) }}
                    </div>
                    <div class="mt-1 text-[11px] {{ $depAvail > 0 ? 'text-violet-600' : 'font-medium text-red-600' }}">
                        {{ $depAvail > 0 ? __('buyer.receivable.deposit_note') : __('buyer.receivable.deposit_note_full') }}
                    </div>
                </div>
                @endif
                {{-- 거래완료 분리 (jin 2026-07-18) — 위 미수/총액은 '진행중'만. 거래완료는 별도 표기(미수율 희석 방지). --}}
                @if(($br['completed_count'] ?? 0) > 0)
                <div class="mt-1.5 flex items-center justify-between border-t border-gray-200 pt-1.5 text-xs text-gray-400">
                    <span>{{ __('buyer.receivable.completed_label') }}</span>
                    <span>{{ __('buyer.receivable.completed_value', ['count' => $br['completed_count'], 'amount' => number_format($br['completed_krw'])]) }}</span>
                </div>
                @endif
                {{-- 선적 후(출고) 분리 (jin 2026-07-20) — 매입 락은 선적 전(국내) 총액 기준. 선적 후는 별도 표기. --}}
                @if(($br['shipped_count'] ?? 0) > 0)
                <div class="mt-1 flex items-center justify-between text-xs text-gray-400">
                    <span>{{ __('buyer.receivable.shipped_label') }}</span>
                    <span>{{ __('buyer.receivable.shipped_value', ['count' => $br['shipped_count'], 'amount' => number_format($br['shipped_krw'])]) }}</span>
                </div>
                @endif
            </div>
            @endif

            {{-- 2026-05-28 — 누적 송금 수수료 (셀러 부담) — 영업 협상 카드용 --}}
            @if($this->buyerFees)
            @php $bf = $this->buyerFees; @endphp
            <div class="mb-4 rounded-lg border border-violet-200 bg-violet-50 p-3">
                <div class="flex items-center justify-between">
                    <div class="text-xs">
                        <div class="font-semibold text-violet-700">{{ __('buyer.fees.title') }}</div>
                        <div class="mt-0.5 text-violet-600">{{ __('buyer.fees.desc') }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-base font-bold text-violet-700">₩{{ number_format($bf['total_krw']) }}</div>
                        @if($bf['wo_count'] > 0)
                            <div class="text-xs text-violet-500">{{ __('buyer.fees.breakdown', ['fee' => $bf['fee_count'], 'wo' => $bf['wo_count']]) }}</div>
                        @else
                            <div class="text-xs text-violet-500">{{ __('buyer.fees.count', ['count' => $bf['count']]) }}</div>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            <div class="space-y-3">
                <div>
                    <label class="label-base">{{ __('buyer.field.name') }} <span class="text-red-500">*</span></label>
                    <input wire:model="name" type="text" class="input-base" placeholder="{{ __('buyer.field.name_ph') }}" />
                    @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label-base">{{ __('common.country') }}</label>
                    <x-country-picker name="country_id_str" :value="$country_id_str" />
                </div>
                {{-- 회의확장씬 #5-1 (2026-05-22) — 영업담당자 직접 지정 ([관리] 솔팅 직접 관계) --}}
                <div>
                    <label class="label-base">{{ __('buyer.field.salesman') }}</label>
                    <select wire:model="salesman_id_str" class="input-base">
                        <option value="">{{ __('buyer.field.salesman_unassigned') }}</option>
                        @foreach($this->salesmen as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">{{ __('buyer.field.salesman_note') }}</p>
                    @error('salesman_id_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- 퇴사자 승계 바이어 (jin 2026-08-04) — 사내직원 정산 건당 5만원 고정. [관리] 이상만 --}}
                @if(auth()->user()?->canApprove())
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input wire:model.live="is_inherited" type="checkbox" class="mt-0.5 rounded" />
                        <span class="text-sm text-gray-800">
                            {{ __('buyer.field.inherited') }}
                            <span class="mt-1 block text-[11px] leading-relaxed text-gray-600">
                                {{ __('buyer.field.inherited_hint') }}
                            </span>
                        </span>
                    </label>
                    @if($is_inherited)
                    <p class="mt-3 text-[11px] leading-relaxed text-gray-500">{{ __('buyer.field.inherited_optional_note') }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-3">
                        <div>
                            <label class="label-base">{{ __('buyer.field.inherited_from') }}</label>
                            <select wire:model="inherited_from_salesman_id_str" class="input-base">
                                <option value="">-</option>
                                @foreach($this->salesmen as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                            @error('inherited_from_salesman_id_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="label-base">{{ __('buyer.field.inherited_at') }}</label>
                            <input wire:model="inherited_at" type="text" data-date class="input-base" placeholder="2026-08-04" />
                            @error('inherited_at')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    @endif
                </div>

                @endif
                {{-- 🔒 락 기준선 — **보기는 관리 이상, 고치기는 시스템관리자**(jin 2026-08-24 분리).
                     실무자가 자기가 막히면 자기가 풀 수 있으면 락이 통제로서 의미가 없다(그래서 편집은 super).
                     그렇다고 숨겨 두면 「이 바이어가 왜 막혔나」를 아무도 못 봐서 문의가 전부 super 에게 몰린다.
                     ⇒ 값·근거는 보여주고 입력만 잠근다. 저장 시 재인가는 그대로다(SKILLS §8 #26).
                     ⚠️ 무담보를 올리면 매입 판정이 미수율 → 금액으로 통째로 바뀌므로 락 % 와 같은 무게다. --}}
                @php $canEditLocks = (bool) auth()->user()?->isSuperAdmin(); @endphp
                @if(auth()->user()?->canViewOperationLogs())
                {{-- 바이어별 락 필요입금률 — 전역 설정보다 먼저 적용된다. 비우면 전역값. --}}
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                    <div class="text-sm font-semibold text-gray-800">{{ __('buyer.field.lock_section') }}</div>
                    <p class="mt-1 text-[11px] leading-relaxed text-gray-600">{{ __('buyer.field.lock_hint') }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        @foreach(['shipping_entry' => 'lock_shipping_entry_pct_str', 'purchase_registration' => 'lock_purchase_registration_pct_str'] as $lockKey => $prop)
                        <div>
                            <label class="label-base">{{ __('buyer.field.lock_'.$lockKey) }}</label>
                            <div class="flex items-center gap-1">
                                <input wire:model="{{ $prop }}" type="number" min="0" max="100" inputmode="numeric"
                                       @disabled(! $canEditLocks)
                                       class="input-base disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500"
                                       placeholder="{{ \App\Models\Setting::lockRequiredPaidPct($lockKey) }}" />
                                <span class="text-sm text-gray-500">%</span>
                            </div>
                            <p class="mt-1 text-[11px] text-gray-500">
                                {{ __('buyer.field.lock_global', ['pct' => \App\Models\Setting::lockRequiredPaidPct($lockKey)]) }}
                            </p>
                            @error($prop)<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] leading-relaxed text-amber-700">{{ __('buyer.field.lock_zero_note') }}</p>
                    @unless($canEditLocks)
                    <p class="mt-1 text-[11px] leading-relaxed text-gray-500">{{ __('buyer.field.lock_readonly') }}</p>
                    @endunless

                    {{-- 신용도 제안 (jin 2026-08-21) — **보여주기만 한다.** [적용]은 위 입력칸을 채울 뿐이고,
                         DB 로 가려면 [저장]을 눌러야 한다. 총점만 던지지 않고 축마다 왜 그 점수인지 찍는다 —
                         지금 손실 이력은 운영에 사실상 0건이라 전원이 만점이다. 그 사실이 화면에 안 보이면
                         "72점 B등급"이 실제보다 근거 있어 보인다. --}}
                    @php $credit = $this->creditScore; @endphp
                    @if($editingId && $credit)
                    <div class="mt-3 border-t border-rose-200 pt-3">
                        @if(! $credit['available'])
                        <p class="text-[11px] leading-relaxed text-gray-500">{{ __('buyer.field.credit_unavailable') }}</p>
                        @else
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-800">
                                {{ __('buyer.field.credit_score', ['score' => $credit['score']]) }}
                                <span class="ml-1 rounded px-1.5 py-0.5 text-xs font-bold
                                    {{ in_array($credit['grade'], ['A','B']) ? 'bg-emerald-100 text-emerald-700' : ($credit['grade'] === 'C' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700') }}">
                                    {{ $credit['grade'] }}
                                </span>
                                @if($credit['capped_by_loss'])
                                <span class="ml-1 text-[11px] text-red-600">{{ __('buyer.field.credit_loss_capped') }}</span>
                                @endif
                            </div>
                            @if($canEditLocks)
                            <button type="button" wire:click="applyRecommendedLocks"
                                    class="rounded border border-rose-300 bg-white px-2 py-1 text-xs text-rose-700 hover:bg-rose-100">
                                {{ __('buyer.field.credit_apply', [
                                    'entry' => $credit['recommended']['shipping_entry'],
                                    'purchase' => $credit['recommended']['purchase_registration'],
                                ]) }}
                            </button>
                            @endif
                        </div>
                        <dl class="mt-2 space-y-0.5">
                            @foreach($credit['axes'] as $axis)
                            <div class="flex items-baseline gap-2 text-[11px]">
                                <dt class="w-14 shrink-0 text-gray-500">{{ $axis['label'] }}</dt>
                                <dd class="w-12 shrink-0 tabular-nums text-gray-700">{{ $axis['score'] }}/{{ $axis['max'] }}</dd>
                                <dd class="text-gray-500">{{ $axis['why'] }}</dd>
                            </div>
                            @endforeach
                        </dl>
                        {{-- 어긋남 표시 — 한 번 넣은 값은 고정이라 점수가 내려가도 안 따라온다.
                             이게 없으면 넣어놓고 잊어버린다. --}}
                        @php
                            $mismatch = [];
                            foreach (['shipping_entry' => $lock_shipping_entry_pct_str, 'purchase_registration' => $lock_purchase_registration_pct_str] as $k => $cur) {
                                if (trim((string) $cur) !== '' && (int) $cur !== $credit['recommended'][$k]) {
                                    $mismatch[] = __('buyer.field.lock_'.$k).' '.(int) $cur.'% → '.$credit['recommended'][$k].'%';
                                }
                            }
                        @endphp
                        @if($mismatch)
                        <p class="mt-2 text-[11px] leading-relaxed text-amber-700">
                            {{ __('buyer.field.credit_mismatch', ['list' => implode(' · ', $mismatch)]) }}
                        </p>
                        @endif
                        @endif
                    </div>
                    @endif
                </div>

                {{-- 무담보 한도 (jin 2026-08-10) — 담보(선적 전 국내 차량)가 없어도 이 금액까지 매입 허용. --}}
                @php $ug = $this->buyerReceivable; @endphp
                <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                    <label class="label-base">{{ __('buyer.field.unsecured_limit') }}</label>
                    <input wire:model="unsecured_limit_krw_str" type="text" data-money inputmode="numeric"
                           @disabled(! $canEditLocks)
                           class="input-base disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500"
                           placeholder="{{ __('buyer.field.unsecured_limit_ph') }}" />
                    <p class="mt-1 text-[11px] leading-relaxed text-gray-600">{{ __('buyer.field.unsecured_limit_hint') }}</p>

                    @if($editingId && $ug && ($ug['unsecured_limit_krw'] ?? 0) > 0)
                    {{-- 무담보분 잔액만 보여준다 (jin 2026-08-10) — 담보 한도·총액·전체 사용액까지 같이 늘어놓으니
                         복잡하고, 무엇보다 담보 한도를 이미 넘긴 바이어는 「남은 여력 0」으로 보여
                         "한도를 줬는데 못 쓴다"는 오해를 부른다. 여기 숫자는 무담보분만이다. --}}
                    <div class="mt-3 flex items-center justify-between border-t border-indigo-200 pt-2 text-sm">
                        <span class="text-gray-600">{{ __('buyer.field.unsecured_available') }}</span>
                        <span class="font-bold {{ $ug['unsecured_available_krw'] > 0 ? 'text-emerald-700' : 'text-red-600' }}">
                            ₩{{ number_format($ug['unsecured_available_krw']) }}
                            @if($ug['unsecured_used_krw'] > 0)
                                <span class="ml-1 text-[11px] font-normal text-gray-500">({{ __('buyer.field.unsecured_used', ['amount' => number_format($ug['unsecured_used_krw'])]) }})</span>
                            @endif
                        </span>
                    </div>
                    @if($ug['unsecured_used_krw'] <= 0)
                    <p class="mt-1 text-[11px] text-gray-500">{{ __('buyer.field.unsecured_untouched') }}</p>
                    @endif
                    @if(($ug['shipped_unpaid_krw'] ?? 0) > 0)
                    {{-- 선적 후 미수는 한도 계산에 **안 들어간다**(jin 2026-08-10 — 이번 범위 밖).
                         한도를 얼마로 줄지 판단할 때 보라고 표시만 한다. --}}
                    <p class="mt-1.5 text-[11px] text-amber-700">
                        {{ __('buyer.field.shipped_unpaid_note', ['amount' => number_format($ug['shipped_unpaid_krw'])]) }}
                    </p>
                    @endif
                    @endif
                </div>
                @endif
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('buyer.field.contact_name') }}</label>
                        <input wire:model="contact_name" type="text" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.phone') }}</label>
                        <input wire:model="contact_phone" type="text" class="input-base" />
                    </div>
                </div>
                <div>
                    <label class="label-base">{{ __('common.email') }}</label>
                    <input wire:model="contact_email" type="email" class="input-base" />
                </div>
                <div>
                    <label class="label-base">{{ __('buyer.field.passport_id') }}</label>
                    <input wire:model="passport_id" type="text" class="input-base" maxlength="50" placeholder="{{ __('buyer.field.passport_id_ph') }}" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('buyer.field.passport_id_hint') }}</p>
                </div>
                <div>
                    <label class="label-base">{{ __('common.address') }}</label>
                    <input wire:model="address" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">{{ __('common.memo') }}</label>
                    <textarea wire:model="memo" class="input-base" rows="2"></textarea>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input wire:model="is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
                    </label>
                </div>
            </div>
        </div>

        {{-- ── 컨사이니 --}}
        <div x-show="tab==='consignees'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">{{ __('buyer.cons.save_first') }}</p>
            @else
            <div class="space-y-2">
                @forelse($consigneeList as $c)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2.5">
                    <div>
                        <div class="text-sm font-medium text-gray-800">{{ $c['name'] }}</div>
                        <div class="text-xs text-gray-400">{{ $c['country_name'] }}{{ $c['contact_email'] ? ' · '.$c['contact_email'] : '' }}</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $c['is_active'] ? 'badge-green' : 'badge-gray' }} text-[10px]">{{ $c['is_active'] ? __('common.active') : __('common.inactive') }}</span>
                        <button wire:click="openConsigneeForm({{ $c['id'] }})" class="text-xs text-violet-600 hover:underline">{{ __('common.edit') }}</button>
                        <button wire:click="deleteConsignee({{ $c['id'] }})"
                                wire:confirm="{{ __('buyer.cons.delete_confirm') }}"
                                class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-400">{{ __('buyer.cons.empty') }}</p>
                @endforelse

                {{-- 추가 버튼 --}}
                @if(!$showConsigneeForm)
                <button wire:click="openConsigneeForm()" class="mt-2 text-sm text-violet-600 hover:underline">{{ __('buyer.cons.add') }}</button>
                @endif
            </div>

            {{-- 인라인 폼 --}}
            @if($showConsigneeForm)
            <div class="mt-4 rounded-xl border border-violet-200 bg-violet-50 p-4 space-y-3">
                <h3 class="text-sm font-semibold text-violet-700">{{ $editConsigneeId ? __('buyer.cons.form_edit') : __('buyer.cons.form_add') }}</h3>
                <div>
                    <label class="label-base">{{ __('buyer.cons.name') }} <span class="text-red-500">*</span></label>
                    <input wire:model="cons_name" type="text" class="input-base" />
                    @error('cons_name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- ID 종류 선택은 제거(jin 2026-08-06) — 실무에서 구분할 일이 없었고, 서류도
                     id_value 만 쓴다(DocValue::consigneeIdValue). 컬럼·기존 데이터는 그대로 둔다. --}}
                <div>
                    <label class="label-base">{{ __('buyer.cons.id_value') }}</label>
                    <input wire:model="cons_id_value" type="text" class="input-base" maxlength="50" />
                    @error('cons_id_value')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label-base">{{ __('common.country') }}</label>
                    <x-country-picker name="cons_country_id_str" :value="$cons_country_id_str" />
                </div>
                {{-- deep-interview 2026-05-28 Q1 — EORI/TAX --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">EORI Number</label>
                        <input wire:model="cons_eori_number" type="text" class="input-base" placeholder="{{ __('buyer.field.eori_ph') }}" />
                    </div>
                    <div>
                        <label class="label-base">TAX Number</label>
                        <input wire:model="cons_tax_number" type="text" class="input-base" placeholder="{{ __('buyer.field.tax_ph') }}" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label-base">{{ __('common.contact') }}</label>
                        <input wire:model="cons_contact_name" type="text" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.email') }}</label>
                        <input wire:model="cons_contact_email" type="email" class="input-base" />
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.phone') }}</label>
                        <input wire:model="cons_contact_phone" type="text" class="input-base" />
                    </div>
                </div>
                <div>
                    <label class="label-base">{{ __('common.address') }}</label>
                    <input wire:model="cons_address" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">{{ __('common.memo') }}</label>
                    <textarea wire:model="cons_memo" class="input-base" rows="1"></textarea>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="cons_is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
                    </label>
                </div>
                <div class="flex gap-2 pt-1">
                    <button wire:click="saveConsignee" class="btn-primary text-xs py-1.5 px-3">{{ __('common.save') }}</button>
                    <button wire:click="closeConsigneeForm" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600">{{ __('common.cancel') }}</button>
                </div>
            </div>
            @endif
            @endif
        </div>

        {{-- ── 적립금 --}}
        <div x-show="tab==='savings'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">{{ __('buyer.savings.save_first') }}</p>
            @else

            {{-- 회의확장씬 #12 Phase 3-1 (b) (2026-05-23) — 카드 → 통화별 게이지 변환 --}}
            @if(count($balances))
            @php
                // 게이지 정규화 기준 — 모든 통화 잔액 절대값 중 최대
                $maxBal = max(array_map(fn ($v) => abs((float) $v), $balances));
                $maxBal = $maxBal > 0 ? $maxBal : 1;   // div by 0 방지
            @endphp
            <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-2">
                <div class="text-[10px] font-semibold uppercase text-gray-400">{{ __('buyer.savings.balances_title') }}</div>
                @foreach($balances as $cur => $bal)
                @php
                    $bal = (float) $bal;
                    $pct = $maxBal > 0 ? max(2, min(100, abs($bal) / $maxBal * 100)) : 0;
                    $isNeg = $bal < 0;
                    $barColor = $isNeg ? 'bg-red-400' : ($bal > 0 ? 'bg-emerald-400' : 'bg-gray-300');
                    $textColor = $isNeg ? 'text-red-600' : ($bal > 0 ? 'text-emerald-700' : 'text-gray-500');
                @endphp
                @php
                    $k    = $balancesKrw[$cur] ?? null;
                    $next = $lots[$cur][0] ?? null;   // 선입선출로 다음에 나갈 적립분
                @endphp
                <div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-10 shrink-0 font-mono font-semibold text-gray-600">{{ $cur }}</span>
                        <div class="h-3 flex-1 overflow-hidden rounded-full border border-gray-200 bg-white">
                            <div class="h-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                        </div>
                        <span class="w-28 shrink-0 text-right font-medium tabular-nums {{ $textColor }}">{{ number_format($bal, 2) }}</span>
                        {{-- 원화 병기 (jin 2026-07-29) — 적립 시점 환율 × 선입선출 잔여분 --}}
                        <span class="w-32 shrink-0 text-right text-[11px] tabular-nums text-gray-500">
                            @if($k && ($k['krw'] > 0 || $k['unrated'] <= 0))
                                ≈ {{ number_format($k['krw']) }}{{ __('buyer.savings.won') }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    {{-- 다음에 나갈 적립분 — 금액까지 보여야 쓸 때마다 줄어드는 게 보인다(jin 2026-07-29).
                         잘 안 보인다는 피드백으로 10px 회색 → 칩 형태로 키움. --}}
                    @if($next)
                    <div class="mt-1 pl-12">
                        <span class="inline-flex items-center gap-1.5 rounded-md border border-primary-light bg-primary-light/50 px-2 py-1 text-xs font-medium text-primary-text">
                            <span class="text-[13px] leading-none">↓</span>
                            {{ __('buyer.savings.next_out_line', [
                                'date'   => $next['at'],
                                'amount' => number_format($next['remaining'], 2),
                                'rate'   => $next['exchange_rate'] ? __('buyer.savings.rate_label', ['rate' => number_format($next['exchange_rate'], 2)]) : __('buyer.savings.rate_none'),
                            ]) }}
                        </span>
                    </div>
                    @endif
                    @if(!empty($k['unrated']))
                    <div class="pl-12 text-[10px] text-amber-600">
                        {{ __('buyer.savings.unrated_note', ['amount' => number_format($k['unrated'], 2), 'currency' => $cur]) }}
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            <p class="mb-4 text-xs text-gray-400">{{ __('buyer.savings.no_balance') }}</p>
            @endif

            {{-- 거래 추가 폼 --}}
            <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer.savings.add_txn') }}</h3>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <label class="label-base">{{ __('buyer.savings.currency') }}</label>
                        <select wire:model="txn_currency" class="input-base">
                            @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                            <option value="{{ $cur }}">{{ $cur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.savings.type') }}</label>
                        <select wire:model="txn_type" class="input-base">
                            <option value="EARNED">{{ __('buyer.savings.type_earned') }}</option>
                            <option value="USED">{{ __('buyer.savings.type_used') }}</option>
                            <option value="REFUND">{{ __('buyer.savings.type_refund') }}</option>
                            <option value="ADJUSTMENT">{{ __('buyer.savings.type_adjustment') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.savings.amount') }}</label>
                        <input wire:model="txn_amount" type="text" class="input-base" placeholder="0.00" />
                        @error('txn_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        {{-- 적립 시점 환율 (jin 2026-07-29) — 원화 병기의 근거값. 차량 판매탭發 적립은 자동. --}}
                        <label class="label-base">
                            {{ __('buyer.savings.rate') }}
                            <span class="text-[10px] text-gray-400">{{ __('buyer.savings.rate_note') }}</span>
                        </label>
                        <input wire:model="txn_rate" type="text" class="input-base" placeholder="0"
                               @if($txn_currency === 'KRW') disabled @endif />
                        @error('txn_rate')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.memo') }}</label>
                        <input wire:model="txn_note" type="text" class="input-base" />
                    </div>
                </div>
                <button wire:click="addSavingsTransaction" class="btn-primary mt-3 text-xs py-1.5">{{ __('buyer.savings.add_btn') }}</button>
            </div>

            {{-- 거래 내역 --}}
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b text-left text-gray-400">
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.col_date') }}</th>
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.currency') }}</th>
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.type') }}</th>
                            <th class="pb-1.5 pr-3 text-right">{{ __('buyer.savings.col_amount') }}</th>
                            <th class="pb-1.5 pr-3 text-right">{{ __('buyer.savings.col_balance') }}</th>
                            <th class="pb-1.5 pr-3">{{ __('buyer.savings.col_fifo') }}</th>
                            <th class="pb-1.5">{{ __('common.memo') }}</th>
                            <th class="pb-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($txnList as $t)
                        @php
                            $typeLabel = match($t['transaction_type']) {
                                'EARNED' => __('buyer.savings.t_earned'), 'USED' => __('buyer.savings.t_used'),
                                'REFUND' => __('buyer.savings.t_refund'), 'ADJUSTMENT' => __('buyer.savings.t_adjustment'), 'CANCELLED' => __('buyer.savings.t_cancelled'),
                                default => $t['transaction_type'],
                            };
                            $typeColor = match($t['transaction_type']) {
                                'EARNED','REFUND' => 'text-green-600',
                                'USED' => 'text-red-500',
                                'CANCELLED' => 'text-gray-400',
                                default => 'text-amber-600',
                            };
                        @endphp
                        <tr>
                            <td class="py-1.5 pr-3 text-gray-400 whitespace-nowrap">{{ $t['created_at'] }}</td>
                            <td class="py-1.5 pr-3 font-mono">{{ $t['currency'] }}</td>
                            <td class="py-1.5 pr-3 {{ $typeColor }} font-medium">{{ $typeLabel }}</td>
                            <td class="py-1.5 pr-3 text-right font-mono {{ $t['savings'] >= 0 ? 'text-green-600' : 'text-red-500' }}">
                                {{ $t['savings'] >= 0 ? '+' : '' }}{{ number_format($t['savings'], 2) }}
                            </td>
                            <td class="py-1.5 pr-3 text-right font-mono text-gray-700">{{ number_format($t['balance'], 2) }}</td>
                            {{-- 선입선출 — 적립분은 "환율·잔여", 사용분은 "어느 적립분에서 얼마 나갔고 그 적립분 잔여" (jin 2026-07-29) --}}
                            <td class="py-1.5 pr-3 whitespace-nowrap text-[11px]">
                                @php $f = $t['fifo'] ?? null; @endphp
                                @if(!$f)
                                    <span class="text-gray-300">—</span>
                                @elseif($f['kind'] === 'in')
                                    <span class="text-gray-500">
                                        @if(!empty($f['exchange_rate'])){{ __('buyer.savings.rate_label', ['rate' => number_format($f['exchange_rate'], 2)]) }}@else<span class="text-amber-600">{{ __('buyer.savings.rate_none') }}</span>@endif
                                    </span>
                                    <span class="ml-1 {{ ($f['remaining'] ?? 0) > 0.004 ? 'text-emerald-700' : 'text-gray-400' }}">
                                        @if(($f['remaining'] ?? 0) > 0.004)
                                            · {{ __('buyer.savings.lot_left', ['amount' => number_format($f['remaining'], 2)]) }}
                                        @else
                                            · {{ __('buyer.savings.lot_spent') }}
                                        @endif
                                    </span>
                                @elseif($f['kind'] === 'out')
                                    @forelse($f['takes'] as $tk)
                                    <div class="text-gray-600">
                                        {{ __('buyer.savings.take_line', [
                                            'date'   => $tk['at'],
                                            'amount' => number_format($tk['take'], 2),
                                            'left'   => number_format($tk['lot_left'], 2),
                                        ]) }}
                                    </div>
                                    @empty
                                    <span class="text-gray-300">—</span>
                                    @endforelse
                                @elseif($f['kind'] === 'back')
                                    @foreach($f['backs'] as $bk)
                                    <div class="text-blue-600">
                                        {{ __('buyer.savings.back_line', ['date' => $bk['at'], 'amount' => number_format($bk['amount'], 2)]) }}
                                    </div>
                                    @endforeach
                                    @if(empty($f['backs']))<span class="text-gray-300">—</span>@endif
                                @endif
                            </td>
                            {{-- 메모가 길면 잘리는데 호버해도 안 보였다(jin 2026-08-20).
                                 title 은 브라우저가 그려서 **슬라이드 패널 밖으로도 안 잘린다** —
                                 CSS 툴팁(absolute)을 쓰면 패널 overflow 에 갇힌다. --}}
                            <td class="py-1.5 text-gray-400 max-w-[120px] truncate" title="{{ $t['note'] }}">{{ $t['note'] }}</td>
                            <td class="py-1.5 pl-2">
                                @if($t['transaction_type'] !== 'CANCELLED')
                                <button wire:click="cancelSavingsTransaction({{ $t['id'] }})"
                                        wire:confirm="{{ __('buyer.savings.cancel_confirm') }}"
                                        class="text-gray-300 hover:text-red-400" title="{{ __('buyer.savings.cancel_title') }}">×</button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="py-6 text-center text-gray-400">{{ __('buyer.savings.no_history') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        {{-- ── 현금 (jin 2026-09-04) — 기획 docs/design/buyer-cash-ledger.md
             🚫 적립금과 다른 원장이다. 적립금 = 회사가 준 크레딧 / 현금 = 실제로 들어온 돈.
             🚨 여기엔 환율 칸이 없다 — 원화 환산은 판매잔금 행이 담당한다. --}}
        @if($this->cashEnabled)
        <div x-show="tab==='cash'" x-cloak>
            @if(!$editingId)
            <p class="text-sm text-gray-400">{{ __('buyer.cash.save_first') }}</p>
            @else

            {{-- 통화별 현금 --}}
            @if(count($cashBalances))
            <div class="mb-4 space-y-2">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer.cash.balances_title') }}</h3>
                @foreach($cashBalances as $cur => $b)
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-sm font-semibold text-gray-700">{{ $cur }}</span>
                        <span class="font-mono text-base font-bold {{ $b['remaining'] > 0.005 ? 'text-emerald-700' : 'text-gray-400' }}">
                            {{ number_format($b['remaining'], 2) }}
                        </span>
                    </div>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-[11px] text-gray-500">
                        <span>{{ __('buyer.cash.received') }} <span class="font-mono">{{ number_format($b['received'], 2) }}</span></span>
                        <span>·</span>
                        <span>{{ __('buyer.cash.allocated') }} <span class="font-mono">{{ number_format($b['allocated'], 2) }}</span></span>
                        <span>·</span>
                        <span class="font-medium text-gray-700">{{ __('buyer.cash.remaining') }}</span>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <p class="mb-4 text-xs text-gray-400">{{ __('buyer.cash.no_receipt') }}</p>
            @endif

            {{-- 입금 기재 — 재무·관리만. 권한 없으면 폼 대신 이유를 보여준다(숨기면 문의가 몰린다). --}}
            @if($this->canManageCash)
            <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer.cash.add_title') }}</h3>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                        <label class="label-base">{{ __('buyer.cash.received_date') }}</label>
                        <input wire:model="cash_date" type="date" class="input-base" />
                        @error('cash_date')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.cash.currency') }}</label>
                        <select wire:model="cash_currency" class="input-base">
                            @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                            <option value="{{ $cur }}">{{ $cur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label-base">{{ __('buyer.cash.amount') }}</label>
                        <input wire:model="cash_amount" type="text" class="input-base" placeholder="0.00" />
                        @error('cash_amount')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="label-base">{{ __('common.memo') }}</label>
                        <input wire:model="cash_note" type="text" class="input-base" />
                    </div>
                </div>
                <p class="mt-2 text-[11px] text-gray-400">{{ __('buyer.cash.rate_note') }}</p>
                <button wire:click="addCashReceipt" class="btn-primary mt-3 text-xs py-1.5">{{ __('buyer.cash.add_btn') }}</button>
            </div>
            @else
            <p class="mb-4 text-xs text-gray-400">{{ __('buyer.cash.no_permission') }}</p>
            @endif

            {{-- 입금 내역 + 배분(「10,000 이 어디로 갔나」)
                 ⚠️ 패널이 580px 라 열을 늘리면 **가로 스크롤 밖으로 밀려 안 보인다**
                    (jin 2026-09-05 «메모는 안나오네?» — 8열이라 메모가 화면 밖에 있었다).
                    그래서 메모·기재자는 열을 따로 두지 않고 수령일 아래에 붙이고,
                    잘리는 것은 전부 title(호버)로 전문을 보여준다. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b text-left text-gray-400">
                            <th class="pb-1.5 pr-2">{{ __('buyer.cash.col_date') }}</th>
                            <th class="pb-1.5 pr-2 text-right">{{ __('buyer.cash.col_amount') }}</th>
                            <th class="pb-1.5 pr-2 text-right">{{ __('buyer.cash.col_remaining') }}</th>
                            <th class="pb-1.5 pr-2">{{ __('buyer.cash.col_used') }}</th>
                            <th class="pb-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($cashReceiptList as $r)
                        <tr class="align-top">
                            <td class="py-1.5 pr-2">
                                <div class="whitespace-nowrap text-gray-500" title="{{ __('buyer.cash.col_by') }}: {{ $r['by'] ?: '-' }}">{{ $r['received_date'] }}</div>
                                @if($r['note'] !== '')
                                {{-- 메모는 열이 아니라 여기 붙인다. 잘려도 호버로 전문이 보인다. --}}
                                <div class="max-w-[130px] truncate text-[10px] text-gray-400" title="{{ $r['note'] }}">{{ $r['note'] }}</div>
                                @endif
                            </td>
                            <td class="py-1.5 pr-2 whitespace-nowrap text-right font-mono text-gray-700">
                                {{ number_format($r['amount'], 2) }}
                                <span class="text-[10px] text-gray-400">{{ $r['currency'] }}</span>
                            </td>
                            <td class="py-1.5 pr-2 whitespace-nowrap text-right font-mono {{ $r['remaining'] > 0.005 ? 'text-emerald-700' : 'text-gray-400' }}">
                                {{ number_format($r['remaining'], 2) }}
                                @if($r['is_next'])
                                <span class="mt-0.5 block text-[10px] font-medium text-primary-text">↓ {{ __('buyer.cash.next_out') }}</span>
                                @endif
                            </td>
                            <td class="py-1.5 pr-2 text-[11px]" title="{{ $r['uses_title'] }}">
                                @forelse($r['uses'] as $u)
                                <div class="whitespace-nowrap text-gray-600">
                                    {{ $u['vehicle_number'] }} <span class="font-mono">{{ number_format($u['amount'], 2) }}</span>
                                </div>
                                @empty
                                <span class="text-gray-300">{{ __('buyer.cash.not_used') }}</span>
                                @endforelse
                            </td>
                            <td class="py-1.5">
                                {{-- 배분된 입금은 삭제 버튼을 아예 안 보여준다 — 되돌리기는 그 판매잔금 행을 지우는 것이다. --}}
                                @if($this->canManageCash && empty($r['uses']))
                                <button wire:click="deleteCashReceipt({{ $r['id'] }})"
                                        wire:confirm="{{ __('buyer.cash.delete_confirm') }}"
                                        class="text-gray-300 hover:text-red-400">×</button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-400">{{ __('buyer.cash.no_receipt') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endif

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <div x-show="tab==='basic'" class="flex gap-2">
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
            </button>
        </div>
        <div x-show="tab!=='basic'">
            <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
        </div>
    </div>

</div>

{{-- 큐 18: close confirm 모달 (.card) --}}
<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">{{ __('common.unsaved_title') }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ __('common.unsaved_body') }}</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('common.close') }}</button>
        </div>
    </div>
</div>

</div>
@endif

</div>
