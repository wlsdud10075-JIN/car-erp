<?php

use App\Models\ShippingRequest;
use App\Models\SignedContract;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\Documents\SigningSessionService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    use \Livewire\WithPagination;

    /** 선적/발급 탭 상태 필터 — active(요청+진행중, 기본) / requested / in_progress / done / all. */
    #[Url]
    public string $statusFilter = 'active';

    /** 검색 — 바이어·컨사이니·차량번호·batch. */
    #[Url]
    public string $search = '';

    /** B/L 발급 인라인 폼 — 현재 발급 중인 batch_id. */
    public string $issuingBatch = '';

    public array $blForm = ['bl_number' => '', 'container_number' => '', 'vessel_name' => '', 'bl_type' => 'original'];

    /** 수출신고번호 일괄 기입 인라인 폼 — 현재 기입 중인 batch_id + 공유 신고번호. */
    public string $declBatch = '';

    public string $declNumber = '';

    /** 상단 탭 — 'shipping'(선적/발급, 기본) | 'cost'(2차 비용: 면허비 n/1). */
    public string $viewTab = 'shipping';

    /** 면허비 n/1 인라인 폼 — 기입 중인 batch_id + 총액. */
    public string $licenseBatch = '';

    public string $licenseTotal = '';

    public function setViewTab(string $t): void
    {
        $this->viewTab = $t === 'cost' ? 'cost' : 'shipping';
        $this->licenseBatch = '';
        $this->licenseTotal = '';
    }

    /** 면허비 n/1 폼 열기 (승인 권한). */
    public function openLicenseFee(string $batchId): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);
        $this->licenseBatch = $batchId;
        $this->licenseTotal = '';
    }

    public function cancelLicenseFee(): void
    {
        $this->licenseBatch = '';
        $this->licenseTotal = '';
    }

    /**
     * 면허비 묶음 n/1 — 총액을 묶음 멤버 차량 수로 나눠(첫 차량에 나머지 원) cost_license 일괄 기입.
     * 팀 스코프(canUnlockLedger, fleetWide=false): 본인 팀 차량만. 팀 밖은 skip 리포트.
     */
    public function applyLicenseFee(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);

        $total = (int) round((float) str_replace(',', '', $this->licenseTotal));
        if ($total <= 0) {
            $this->dispatch('notify', message: __('shipping.license.invalid_total'), type: 'error');

            return;
        }

        $vehicles = ShippingRequest::where('batch_id', $this->licenseBatch)
            ->with('vehicle')->get()
            ->map->vehicle->filter()->unique('id')->values();
        $n = $vehicles->count();
        if ($n === 0) {
            return;
        }

        // n/1 — 첫 차량에 나머지 원 몰아 합계 정확히 일치.
        $base = intdiv($total, $n);
        $remainder = $total - $base * $n;
        $amounts = [];
        foreach ($vehicles as $idx => $v) {
            $amounts[$v->id] = $base + ($idx === 0 ? $remainder : 0);
        }

        $reason = '면허비 2차 정산 n/1 (묶음 '.$this->licenseBatch.', '.$n.'대, 총 '.number_format($total).'원)';
        $res = app(\App\Services\BulkVehicleCostService::class)
            ->apply('cost_license', $amounts, auth()->user(), $reason, false);

        $this->licenseBatch = '';
        $this->licenseTotal = '';

        if (! empty($res['skipped'])) {
            $this->dispatch('notify', message: __('shipping.license.applied_partial', ['ok' => $res['applied'], 'skip' => count($res['skipped'])]), type: 'warning');
        } else {
            $this->dispatch('notify', message: __('shipping.license.applied', ['count' => $res['applied']]), type: 'success');
        }
    }

    /** 차량목록에서 「면허비 n/1」 딥링크로 넘어온 batch_id — 2차 비용 탭 + 해당 묶음 폼 자동 오픈. */
    #[Url]
    public string $focus = '';

    /** 성지 면허비 딥링크(차량목록 「명세서 기입」→면허비→성지) — 특정 묶음 없이 2차 비용 탭만 연다. */
    #[Url]
    public string $tab = '';

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        // 딥링크 진입 — 승인 권한자면 2차 비용 탭 열고 해당 묶음 면허비 폼 자동 오픈.
        if ($this->focus !== '' && auth()->user()?->canApprove()) {
            $this->viewTab = 'cost';
            $this->licenseBatch = $this->focus;
        } elseif ($this->tab === 'cost' && auth()->user()?->canApprove()) {
            $this->viewTab = 'cost';
        }
    }

    public function setStatus(string $s): void
    {
        $this->statusFilter = in_array($s, [
            'active',
            ShippingRequest::STATUS_REQUESTED,
            ShippingRequest::STATUS_IN_PROGRESS,
            ShippingRequest::STATUS_DONE,
            'all',
        ], true) ? $s : 'active';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * 🔒 선적 진입 락 = 묶음 총금액 aggregate 50% (jin 2026-07-20, item4 — 개별(나) 되돌림).
     *   묶은 것의 총 입금률 기준. 예) 1억(완납)+1천만(미납) → aggregate 91% > 50% 통과(큰 차가 입금되면 묶음 넘어감).
     *   관리 승인 우회(hasEntryUnpaidOverride) 차량은 집계에서 제외(escape 유지).
     *   반환 ['blocked'=>bool, 'unpaid_pct'=>?float]. denom 0(가격·환율 없음) → 미차단(개별 C5가 반입지 저장 시 처리).
     *   ⚠️ 개별 차량 C5(guardStageOrderForExport)는 그대로 개별 50% 유지 — 묶음 착수 통과해도 미달 개별차의
     *      반입지 저장은 개별로 막힘(그 차 stage=shipping 승인 우회 필요). 두 게이트는 분리(jin 확정).
     */
    private function entryAggregate($vehicles): array
    {
        $active = collect($vehicles)->filter(
            fn ($v) => $v && (int) $v->sale_price > 0 && ! $v->hasEntryUnpaidOverride()
        );
        $fin = ShippingRequest::financeForVehicles($active);
        $ratio = $fin['unpaid_ratio'];

        return [
            'blocked' => $ratio !== null && $ratio > 0.5,
            'unpaid_pct' => $ratio === null ? null : round($ratio * 100, 1),
        ];
    }

    /**
     * 🔒 (나)+(a) — 묶음 내 각 차량이 임계 미달인지 검사, 미달 차량번호 리스트 반환.
     *   B/L 발행(stage='bl', 100% 완납) 전용. 선적 진입(shipping)은 entryAggregate() 로 이관(2026-07-20).
     *   각 차 개별 판정 + 관리 승인 우회(hasUnpaidOverride('bl')) 제외. 미달 1대면 호출부가 묶음 통째 차단.
     */
    private function bundleBlockers($rows, string $stage): array
    {
        $blockers = [];
        foreach ($rows as $r) {
            $v = $r->vehicle;
            if (! $v || (int) $v->sale_price <= 0) {
                continue;   // 판매 없음 — 게이트 대상 아님
            }
            $ratio = $v->unpaid_ratio;
            if ($stage === 'shipping') {
                $violates = $ratio === null || $ratio > 0.5;
                $overridden = $v->hasEntryUnpaidOverride();
            } else {   // bl — 100% 완납
                $violates = $ratio === null || $ratio > 0;
                $overridden = $v->hasUnpaidOverride('bl');
            }
            if ($violates && ! $overridden) {
                $blockers[] = $v->vehicle_number;
            }
        }

        return $blockers;
    }

    /**
     * 배치 단위 상태 전환 — mutating endpoint 이므로 매번 재인가(SKILLS §8 #26).
     * done 전환 시 연동된 shipping_requested 알람을 resolve(벨/알림 카운트 정합).
     */
    public function changeStatus(string $batchId, string $to): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        if (! in_array($to, [ShippingRequest::STATUS_IN_PROGRESS, ShippingRequest::STATUS_DONE], true)) {
            return;
        }

        $rows = ShippingRequest::where('batch_id', $batchId)->with('vehicle')->get();
        if ($rows->isEmpty()) {
            return;
        }

        // 🔒 선적 진입 락 — 착수 시 묶음 총금액 50%+ 입금 필수(aggregate, jin 2026-07-20). 미달이면 묶음 대기.
        if ($to === ShippingRequest::STATUS_IN_PROGRESS && \App\Models\Setting::lockEnabled('shipping_entry')) {
            $agg = $this->entryAggregate($rows->map->vehicle);
            if ($agg['blocked']) {
                $this->dispatch('notify', message: __('shipping.lock.entry_blocked_aggregate', ['pct' => $agg['unpaid_pct']]), type: 'error');

                return;
            }
        }

        foreach ($rows as $r) {
            $r->status = $to;
            if ($to === ShippingRequest::STATUS_DONE) {
                $r->processed_at = now();
            }
            $r->save();
        }

        if ($to === ShippingRequest::STATUS_DONE) {
            TaskAlarm::where('type', 'shipping_requested')
                ->whereIn('vehicle_id', $rows->pluck('vehicle_id'))
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_reason' => 'shipping_done']);
        }

        $this->dispatch('notify', message: __('shipping.toast.updated'), type: 'success');
    }

    /**
     * 배치 취소 — 영업이 board에서 올린 요청을 통관/관리가 car-erp 에서 무름.
     * status='cancelled'(open 집계 제외 → 차 재요청 가능) + 연동 알람 resolve. done 은 취소 불가.
     */
    public function cancel(string $batchId): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $rows = ShippingRequest::where('batch_id', $batchId)
            ->whereIn('status', [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS])
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $r) {
            $r->status = ShippingRequest::STATUS_CANCELLED;
            $r->processed_at = now();
            $r->save();
        }

        TaskAlarm::whereIn('type', ['shipping_requested', 'bl_requested', 'shipping_change_requested'])
            ->whereIn('vehicle_id', $rows->pluck('vehicle_id'))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'shipping_cancelled']);

        $this->dispatch('notify', message: __('shipping.toast.cancelled'), type: 'success');
    }

    /** B/L 발급 폼 열기 — bl_type 은 영업 요청값 prefill. 발급 = 승인 권한(canApprove). */
    public function openIssue(string $batchId): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);

        $row = ShippingRequest::where('batch_id', $batchId)->with('vehicle')->first();
        if (! $row) {
            return;
        }
        $this->issuingBatch = $batchId;
        $this->blForm = [
            'bl_number' => '',
            'container_number' => '',
            'vessel_name' => $row->vehicle?->vessel_name ?? '',
            'bl_type' => $row->bl_type ?: ShippingRequest::BL_TYPE_ORIGINAL,
        ];
    }

    public function cancelIssue(): void
    {
        $this->issuingBatch = '';
    }

    /**
     * B/L 발급 bulk-apply — 공유 B/L 필드를 묶음 멤버 차량 전체에 트랜잭션 일괄 기입.
     * - per-vehicle update() 사용 → Vehicle::saving 훅 정상 발동(캐시 갱신·가드, bulk SQL 우회 없음).
     * - bl_document 는 미설정(차량별 업로드 + G1 100% + 이중가드 유지). bl_number/container/vessel/bl_type 만.
     * - 미완납 묶음은 발급 차단(완납 후 — G1 100% 정합).
     */
    public function applyBlIssue(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);

        $data = $this->validate([
            'blForm.bl_type' => ['required', 'in:original,surrender'],
            'blForm.bl_number' => ['nullable', 'string', 'max:100'],
            'blForm.container_number' => ['nullable', 'string', 'max:100'],
            'blForm.vessel_name' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = ShippingRequest::where('batch_id', $this->issuingBatch)->with('vehicle')->get();
        if ($rows->isEmpty()) {
            return;
        }

        // 🔒 (나)+(a) B/L 발행 락 — 묶음 내 각 차량 100% 완납 필수. 미달 1대면 묶음 통째 차단(관리 'bl' 승인 우회).
        if (\App\Models\Setting::lockEnabled('bl_issue')) {
            $blockers = $this->bundleBlockers($rows, 'bl');
            if (! empty($blockers)) {
                $this->dispatch('notify', message: __('shipping.bl.blocked_vehicles', ['vehicles' => implode(', ', $blockers)]), type: 'error');

                return;
            }
        }

        DB::transaction(function () use ($rows) {
            $payload = ['bl_type' => $this->blForm['bl_type']];
            foreach (['bl_number', 'container_number', 'vessel_name'] as $k) {
                if (($this->blForm[$k] ?? '') !== '') {
                    $payload[$k] = $this->blForm[$k];
                }
            }
            foreach ($rows as $r) {
                $r->vehicle?->update($payload);                       // 멤버 차량 일괄(saving 훅 발동)
                $r->update(['bl_status' => ShippingRequest::BL_STATUS_ISSUED]);
            }
        });

        $this->issuingBatch = '';
        $this->dispatch('notify', message: __('shipping.toast.bl_issued'), type: 'success');
    }

    /** 수출신고번호 일괄 기입 폼 열기 — 통관 데이터라 canAccessClearance. 첫 차량 기존값 prefill. */
    public function openDeclNumber(string $batchId): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $this->declBatch = $batchId;
        $existing = ShippingRequest::where('batch_id', $batchId)->with('vehicle')->get()
            ->map->vehicle->filter()->pluck('export_declaration_number')->filter()->first();
        $this->declNumber = $existing ?? '';
    }

    public function cancelDeclNumber(): void
    {
        $this->declBatch = '';
        $this->declNumber = '';
    }

    /**
     * 수출신고번호 bulk-apply — 공유 신고번호를 묶음 멤버 차량 전체에 일괄 기입 (B/L 일괄 기입과 동일 패턴).
     * - 통관 단계 데이터 필드(export_declaration_number)만 채움 — 진행상태 cascade(export_declaration_document·
     *   is_export_cleared)엔 영향 없음. 순수 데이터 입력이라 완납 게이트 등 무관.
     * - per-vehicle update() → Vehicle::saving 훅 정상 발동(캐시 갱신), bulk SQL 우회 없음.
     */
    public function applyDeclNumber(): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $number = trim($this->declNumber);
        if ($number === '') {
            $this->dispatch('notify', message: __('shipping.decl.invalid'), type: 'error');

            return;
        }

        $rows = ShippingRequest::where('batch_id', $this->declBatch)->with('vehicle')->get();
        if ($rows->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($rows, $number) {
            foreach ($rows as $r) {
                $r->vehicle?->update(['export_declaration_number' => $number]);   // 멤버 차량 일괄(saving 훅 발동)
            }
        });

        $count = $rows->map->vehicle->filter()->unique('id')->count();
        $this->cancelDeclNumber();
        $this->dispatch('notify', message: __('shipping.toast.decl_applied', ['count' => $count]), type: 'success');
    }

    /** 변경요청 수락 = 묶음 행 해제(취소) → 영업 재구성 가능 + 연동 알람 resolve. */
    public function acceptChange(int $id): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $r = ShippingRequest::find($id);
        if (! $r || $r->change_requested_at === null) {
            return;
        }
        $r->update([
            'status' => ShippingRequest::STATUS_CANCELLED,
            'processed_at' => now(),
            'change_requested_at' => null,
            'change_request_meta' => null,
        ]);
        TaskAlarm::whereIn('type', ['shipping_requested', 'bl_requested', 'shipping_change_requested'])
            ->where('vehicle_id', $r->vehicle_id)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'change_accepted']);

        $this->dispatch('notify', message: __('shipping.toast.change_accepted'), type: 'success');
    }

    /** 변경요청 반려 = 플래그만 클리어(관리가 계속 진행). */
    public function rejectChange(int $id): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        $r = ShippingRequest::find($id);
        if (! $r) {
            return;
        }
        $r->update(['change_requested_at' => null, 'change_request_meta' => null]);
        TaskAlarm::where('type', 'shipping_change_requested')->where('vehicle_id', $r->vehicle_id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_reason' => 'change_rejected']);

        $this->dispatch('notify', message: __('shipping.toast.change_rejected'), type: 'success');
    }

    // ── 전자서명 (묶음서류 행 칩) ──────────────────────────────
    public bool $showSignModal = false;

    public ?string $signUrl = null;

    public ?string $signContractNo = null;

    /** batch 차량으로 서명 세션 발급 → 링크 모달. 묶음=단일 바이어·통화라 그대로 발급(서비스가 재검증). */
    public function requestSignatureForBatch(string $batchId): void
    {
        $ids = ShippingRequest::where('batch_id', $batchId)->pluck('vehicle_id')->all();
        $byId = Vehicle::whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = collect($ids)->map(fn ($id) => $byId->get($id))->filter()->values();

        $user = auth()->user();
        if ($vehicles->isEmpty() || ! $vehicles->every(fn ($v) => $user->canScopeVehicle($v))) {
            $this->dispatch('notify', message: __('signed_contract.notify.scope_denied'), type: 'error');

            return;
        }

        try {
            $result = app(SigningSessionService::class)->issue($vehicles, null, $user->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('notify', message: $e->validator->errors()->first(), type: 'warning');

            return;
        }

        $this->openSignModal($result['contract']->contract_no, $result['url']);
        $this->dispatch('notify', message: __('signed_contract.notify.issued'), type: 'success');
    }

    /** 활성 세션 링크 재표시(복사) — 발급 URL 미저장이라 토큰+만료로 재생성. */
    public function showSignLink(int $contractId): void
    {
        $c = SignedContract::find($contractId);
        if (! $c || $c->isSigned()) {
            return;
        }
        $user = auth()->user();
        $vehicles = Vehicle::whereIn('id', $c->vehicle_ids ?? [])->get();
        if ($vehicles->isEmpty() || ! $vehicles->every(fn ($v) => $user->canScopeVehicle($v))) {
            $this->dispatch('notify', message: __('signed_contract.notify.scope_denied'), type: 'error');

            return;
        }
        $this->openSignModal($c->contract_no, $c->signingUrl());
    }

    private function openSignModal(string $contractNo, string $url): void
    {
        $this->signContractNo = $contractNo;
        $this->signUrl = $url;
        $this->showSignModal = true;
    }

    public function with(): array
    {
        // ── 선적/발급 탭 — batch 단위 페이지네이션 + 검색 (탭 진입 시만) ──
        //   기본 필터 = active(요청+진행중, 할 일). 완료는 누적되므로 필터/검색/페이지로 접근.
        $search = trim($this->search);
        $applyFilters = function ($q) use ($search) {
            $q->where('status', '!=', ShippingRequest::STATUS_CANCELLED);
            if ($this->statusFilter === 'active') {
                $q->whereIn('status', [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS]);
            } elseif (in_array($this->statusFilter, [ShippingRequest::STATUS_REQUESTED, ShippingRequest::STATUS_IN_PROGRESS, ShippingRequest::STATUS_DONE], true)) {
                $q->where('status', $this->statusFilter);
            }   // 'all' → 상태 무조건
            if ($search !== '') {
                $q->where(function ($q2) use ($search) {
                    $q2->whereHas('buyer', fn ($b) => $b->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('consignee', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vehicle', fn ($v) => $v->where('vehicle_number', 'like', "%{$search}%"))
                        ->orWhere('batch_id', 'like', "%{$search}%");
                });
            }
        };

        // batch 단위로 페이지네이션 (최신 요청 desc) → 그 페이지의 batch 만 전체 로드.
        $batchPage = ShippingRequest::query()
            ->tap($applyFilters)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, MAX(requested_at) as latest_req')
            ->orderByDesc('latest_req')
            ->paginate(20);

        $batchIds = collect($batchPage->items())->pluck('batch_id')->all();
        $order = array_flip($batchIds);

        $grouped = ShippingRequest::query()
            ->with(['vehicle', 'buyer', 'consignee'])
            ->whereIn('batch_id', $batchIds)
            ->get()
            ->groupBy('batch_id');

        // 전자서명 상태 — 가시 batch 차량들의 바이어 세션만 프리로드(N+1 회피), pickForSet 로 batch 차량 set 매칭.
        //   ⚠️ ShippingRequest.buyer_id 는 NULL 일 수 있음(board/묶기 미기입) → 세션 buyer_id 의 출처인
        //      차량의 buyer_id 로 프리로드해야 매칭됨.
        $signBuyerIds = $grouped->flatMap(fn ($items) => $items->map(fn ($r) => $r->vehicle?->buyer_id))
            ->filter()->unique()->values()->all();
        $signSessions = \App\Models\SignedContract::whereIn('buyer_id', $signBuyerIds)
            ->whereIn('status', [
                \App\Models\SignedContract::STATUS_SIGNED,
                \App\Models\SignedContract::STATUS_VIEWED,
                \App\Models\SignedContract::STATUS_PENDING,
            ])->latest('id')->get();

        $batches = $grouped->map(function ($items) use ($signSessions) {
                $f = $items->first();
                $memberVehicles = $items->map->vehicle->filter();
                $fin = ShippingRequest::financeForVehicles($memberVehicles);

                // 🔒 선적 진입 락 — 묶음 총금액 aggregate 50% 로 착수 판정(jin 2026-07-20, item4).
                $entryLockOn = \App\Models\Setting::lockEnabled('shipping_entry');
                $entryAgg = $this->entryAggregate($memberVehicles);
                $entryBundleBlocked = $entryLockOn && $entryAgg['blocked'];
                // 개별 차량 경고(빨간 칩) — 개별 C5 는 그대로 개별 50% 유지. 묶음 착수 통과해도 이 차들은
                //   반입지 저장 시 개별로 막힘(그 차 승인 우회 필요). aggregate 차단과 별개의 안내 표시.
                $isEntryUnder = fn ($v) => $entryLockOn && $v && (int) $v->sale_price > 0
                    && ($v->unpaid_ratio === null || $v->unpaid_ratio > 0.5)
                    && ! $v->hasEntryUnpaidOverride();

                $signContract = \App\Models\SignedContract::pickForSet($signSessions, $items->pluck('vehicle_id')->all());

                // 판매계약서 사전검증 — 컨트롤러 가드(showMulti HOMOGENEOUS_TYPES)와 동일: 단일 바이어·단일 통화만 발급.
                //   혼합 묶음이면 링크를 비활성해 raw 422 노출을 막는다(차량목록 액션바와 정합).
                $salesContractOk = $memberVehicles->pluck('buyer_id')->unique()->count() <= 1
                    && $memberVehicles->pluck('currency')->unique()->count() <= 1;

                return array_merge([
                    'batch_id' => (string) $f->batch_id,
                    'buyer' => $f->buyer?->name,
                    'buyer_id' => $f->buyer_id,
                    'consignee' => $f->consignee?->name,
                    'sign' => $signContract
                        ? ['status' => $signContract->status, 'id' => $signContract->id]
                        : ['status' => 'none'],
                    'shipping_method' => $f->shipping_method,
                    'bl_type' => $f->bl_type,
                    'bl_status' => $f->bl_status ?? ShippingRequest::BL_STATUS_NONE,
                    'requested_by' => $f->requested_by_email,
                    'requested_at' => $f->requested_at,
                    'status' => $f->status,
                    'vehicles' => $items->map(fn ($r) => [
                        'id' => $r->vehicle_id,
                        'number' => $r->vehicle?->vehicle_number ?? ('#'.$r->vehicle_id),
                        'has_dereg' => filled($r->vehicle?->deregistration_document),
                        'unpaid_pct' => $r->vehicle?->unpaid_ratio === null ? null : round($r->vehicle->unpaid_ratio * 100, 1),
                        'entry_blocked' => $isEntryUnder($r->vehicle),   // 개별 C5 경고(빨간 칩) — 묶음 착수와 별개
                    ])->values()->all(),
                    'count' => $items->count(),
                    'sales_contract_ok' => $salesContractOk,
                    'entry_bundle_blocked' => $entryBundleBlocked,       // 묶음 aggregate 착수 차단(jin 2026-07-20)
                    'entry_unpaid_pct' => $entryAgg['unpaid_pct'],
                    'surrender_unpaid_warning' => $f->bl_type === ShippingRequest::BL_TYPE_SURRENDER && ! $fin['fully_paid'],
                    'changes' => $items->filter(fn ($r) => $r->change_requested_at !== null)
                        ->map(fn ($r) => [
                            'id' => $r->id,
                            'number' => $r->vehicle?->vehicle_number ?? ('#'.$r->vehicle_id),
                            'note' => $r->change_request_meta['note'] ?? null,
                        ])->values()->all(),
                ], $fin);
            })->sortBy(fn ($b) => $order[$b['batch_id']] ?? 999)->values();

        // 필터 칩 카운트 (batch 단위, 취소 제외). 상태는 batch 내 균일(changeStatus 가 batch 전체 갱신).
        $statusCounts = ShippingRequest::query()
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)
            ->selectRaw('status, COUNT(DISTINCT batch_id) as c')
            ->groupBy('status')->pluck('c', 'status');
        $counts = [
            'active' => (int) (($statusCounts[ShippingRequest::STATUS_REQUESTED] ?? 0) + ($statusCounts[ShippingRequest::STATUS_IN_PROGRESS] ?? 0)),
            'requested' => (int) ($statusCounts[ShippingRequest::STATUS_REQUESTED] ?? 0),
            'in_progress' => (int) ($statusCounts[ShippingRequest::STATUS_IN_PROGRESS] ?? 0),
            'done' => (int) ($statusCounts[ShippingRequest::STATUS_DONE] ?? 0),
            'all' => (int) ShippingRequest::where('status', '!=', ShippingRequest::STATUS_CANCELLED)->distinct()->count('batch_id'),
        ];

        // 2차 비용(면허비) 탭 — 2차 정산 pending 인 묶음만, paid월 그룹, 팀 스코프. (탭 진입 시만 계산)
        $costBatches = collect();
        if ($this->viewTab === 'cost') {
            $costBatches = $this->buildCostBatches();
        }

        return [
            'batches' => $batches,
            'batchPage' => $batchPage,
            'counts' => $counts,
            'costBatches' => $costBatches,
            'canApprove' => (bool) auth()->user()?->canApprove(),
            'canAccessClearance' => (bool) auth()->user()?->canAccessClearance(),
        ];
    }

    /**
     * 2차 비용 탭 데이터 — 멤버 차량이 2차 정산 pending(paid 후 한 달 창)인 묶음.
     *   - 팀 스코프: 관리는 본인 팀 차량 묶음만 / admin·super 전체.
     *   - 귀속월(정산 created_at) 기준 그룹 — 정산 화면과 동일 축(월급 귀속월, "5월분→6/10 지급").
     *     지급월(paid_at)이 아니라 귀속월이라야 2차 정산 배치와 맞물림(jin 2026-07-01).
     *   - 최신월 먼저. 면허비 미기입(전부 기본값 11,000) 뱃지.
     */
    private function buildCostBatches()
    {
        $user = auth()->user();
        $defaultLicense = (int) (Vehicle::DEFAULT_PURCHASE_COSTS['cost_license'] ?? 11000);

        $rows = ShippingRequest::query()
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)
            ->with(['vehicle.settlements', 'buyer'])
            ->get()
            ->filter(function ($r) use ($user) {
                $v = $r->vehicle;

                return $v
                    && $v->settlements->contains(fn ($s) => $s->secondary_status === 'pending')
                    && ($user->canAccessAdmin() || $user->canScopeVehicle($v));
            });

        return $rows->groupBy('batch_id')->map(function ($items) use ($defaultLicense) {
            $f = $items->first();
            $vehicles = $items->map->vehicle->filter()->unique('id')->values();

            // 귀속월 = 2차 pending 정산의 created_at (정산 화면 monthFilter 와 동일 기준).
            $attribMonth = null;
            foreach ($vehicles as $v) {
                $s = $v->settlements->first(fn ($s) => $s->secondary_status === 'pending' && $s->created_at);
                if ($s) {
                    $attribMonth = $s->created_at;
                    break;
                }
            }

            return [
                'batch_id' => (string) $f->batch_id,
                'buyer' => $f->buyer?->name,
                'count' => $vehicles->count(),
                'month' => $attribMonth ? $attribMonth->format('Y-m') : '—',
                'not_entered' => $vehicles->every(fn ($v) => (int) $v->cost_license === $defaultLicense),
                'vehicles' => $vehicles->map(fn ($v) => [
                    'number' => $v->vehicle_number,
                    'license' => (int) $v->cost_license,
                ])->all(),
            ];
        })->values()->groupBy('month')->sortKeysDesc();
    }
}; ?>

<div class="p-3 md:p-6">
    {{-- 헤더 --}}
    <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('shipping.title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('shipping.subtitle') }}</p>
        </div>
    </div>

    {{-- 차량관리에서 「선적요청으로 묶기」로 방금 생성된 묶음 안내 (jin 2026-07-08) --}}
    @if(session()->has('bundle_created'))
        @php $bc = session('bundle_created'); @endphp
        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-800">
            @if(!empty($bc['skipped']))
                {{ __('vehicle.accum.bundle_created_skipped', ['count' => $bc['count'], 'skip' => count($bc['skipped'])]) }}
            @else
                {{ __('vehicle.accum.bundle_created', ['count' => $bc['count']]) }}
            @endif
        </div>
    @endif

    {{-- 상단 탭: 선적/발급 ↔ 2차 비용(면허비 n/1). 2차 비용은 승인 권한자만. --}}
    <div class="mb-4 flex gap-2 border-b border-gray-200">
        <button type="button" wire:click="setViewTab('shipping')"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $viewTab === 'shipping' ? 'border-primary text-primary-text' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            {{ __('shipping.tab.shipping') }}
        </button>
        @if($canApprove)
        <button type="button" wire:click="setViewTab('cost')"
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $viewTab === 'cost' ? 'border-primary text-primary-text' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            {{ __('shipping.tab.cost') }}
        </button>
        @endif
    </div>

    @if($viewTab === 'shipping')
    {{-- 상태 필터 칩(기본 '할 일'=요청+진행중) + 검색 --}}
    @php
        $statusMeta = [
            'active' => ['label' => __('shipping.filter.active'), 'count' => $counts['active']],
            ShippingRequest::STATUS_REQUESTED => ['label' => __('shipping.filter.requested'), 'count' => $counts['requested']],
            ShippingRequest::STATUS_IN_PROGRESS => ['label' => __('shipping.filter.in_progress'), 'count' => $counts['in_progress']],
            ShippingRequest::STATUS_DONE => ['label' => __('shipping.filter.done'), 'count' => $counts['done']],
            'all' => ['label' => __('shipping.filter.all'), 'count' => $counts['all']],
        ];
    @endphp
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($statusMeta as $key => $m)
            <button type="button" wire:click="setStatus('{{ $key }}')"
                    class="tab-pill {{ $statusFilter === $key ? 'is-active' : '' }}">
                {{ $m['label'] }}
                <span class="pill-count">{{ $m['count'] }}</span>
            </button>
        @endforeach
        <input wire:model.live.debounce.400ms="search" type="text"
               placeholder="{{ __('shipping.search_ph') }}" class="input-filter ml-auto w-56" />
    </div>

    {{-- 배치 카드 (batch 단위 페이지네이션 20개) --}}
    @if ($batches->isEmpty())
        <div class="card text-center text-sm text-gray-400">{{ $search !== '' ? __('shipping.empty_search') : __('shipping.empty') }}</div>
    @else
        <div class="space-y-3">
            @foreach ($batches as $b)
                @php
                    $statusBadge = match ($b['status']) {
                        'requested' => 'badge-blue',
                        'in_progress' => 'badge-amber',
                        'done' => 'badge-gray',
                        default => 'badge-gray',
                    };
                    $ratioPct = $b['unpaid_ratio'] !== null ? round($b['unpaid_ratio'] * 100, 1) : null;
                @endphp
                <div class="card">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge {{ $statusBadge }}">{{ __('shipping.status.'.$b['status']) }}</span>
                                <span class="badge badge-teal">{{ $b['shipping_method'] }}</span>
                                @if ($b['bl_type'])
                                    <span class="badge badge-purple">{{ __('shipping.bl.type.'.$b['bl_type']) }}</span>
                                @endif
                                @if ($b['bl_status'] === 'requested')
                                    <span class="badge badge-amber">{{ __('shipping.bl.status_requested') }}</span>
                                @elseif ($b['bl_status'] === 'issued')
                                    <span class="badge badge-green">{{ __('shipping.bl.status_issued') }}</span>
                                @endif
                                <span class="text-sm font-bold text-gray-800">{{ $b['buyer'] ?? '—' }}</span>
                                @if ($b['consignee'])
                                    <span class="text-xs text-gray-400">→ {{ $b['consignee'] }}</span>
                                @endif
                                <span class="pill-count">{{ __('shipping.vehicles_n', ['n' => $b['count']]) }}</span>
                            </div>
                            <div class="mt-1 text-[11px] text-gray-400">
                                {{ __('shipping.requested_by') }}: {{ $b['requested_by'] }}
                                @if ($b['requested_at']) · {{ $b['requested_at']->format('Y-m-d H:i') }} @endif
                            </div>
                        </div>

                        {{-- 상태 전환 액션 --}}
                        @php $idsCsv = implode(',', array_column($b['vehicles'], 'id')); @endphp
                        <div class="flex shrink-0 flex-wrap gap-1.5">
                            @if ($b['status'] === 'requested' && $b['entry_bundle_blocked'])
                                {{-- 🔒 착수 불가 — 묶음 총 입금률 50% 미만(aggregate, jin 2026-07-20). 착수불가 + 차량관리에서 보기 + 취소만. --}}
                                <span title="{{ __('shipping.action.entry_locked_tip_aggregate', ['pct' => $b['entry_unpaid_pct']]) }}"
                                      class="cursor-not-allowed rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700">
                                    🔒 {{ __('shipping.action.entry_locked') }}
                                </span>
                                {{-- 왜 착수 불가인지(미달 차) 확인하러 차량관리로 --}}
                                <a href="{{ route('erp.vehicles.index', ['ids' => $idsCsv]) }}" wire:navigate
                                   class="rounded-md border border-primary bg-primary-light px-2.5 py-1 text-[11px] font-semibold text-primary-text hover:opacity-90">
                                    {{ __('shipping.action.open_in_vehicles', ['count' => $b['count']]) }}
                                </a>
                                <button type="button" wire:click="cancel('{{ $b['batch_id'] }}')"
                                        wire:confirm="{{ __('shipping.confirm.cancel', ['n' => $b['count']]) }}"
                                        class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-100">
                                    {{ __('shipping.action.cancel') }}
                                </button>
                            @else
                                {{-- 기본 인라인 — 수출신고번호·B/L번호 기입 + 차량 N대 보기 (진행/완료는 ⋯더보기) --}}
                                @if ($canAccessClearance)
                                    <button type="button" wire:click="openDeclNumber('{{ $b['batch_id'] }}')"
                                            class="rounded-md border border-green-200 bg-green-50 px-2.5 py-1 text-[11px] font-semibold text-green-700 hover:bg-green-100">
                                        {{ __('shipping.decl.enter') }}
                                    </button>
                                @endif
                                @if ($canApprove && $b['bl_status'] !== 'issued')
                                    <button type="button" wire:click="openIssue('{{ $b['batch_id'] }}')"
                                            class="rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                        {{ __('shipping.bl.issue') }}
                                    </button>
                                @endif
                                {{-- 차량관리 N대 보기 — 항상 인라인(jin: 무조건 보이게) --}}
                                <a href="{{ route('erp.vehicles.index', ['ids' => $idsCsv]) }}" wire:navigate
                                   class="rounded-md border border-primary bg-primary-light px-2.5 py-1 text-[11px] font-semibold text-primary-text hover:opacity-90">
                                    {{ __('shipping.action.open_in_vehicles', ['count' => $b['count']]) }}
                                </a>
                                {{-- 보조 액션(말소신청서 다운로드·취소) → ⋯ 더보기 --}}
                                @php $deregUrls = collect($b['vehicles'])->where('has_dereg', true)->map(fn ($v) => route('erp.vehicles.deregistration-file', ['id' => $v['id']]))->values()->all(); @endphp
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = ! open" title="{{ __('shipping.action.more') }}"
                                            class="rounded-md border border-gray-300 bg-white px-2 py-1 text-[13px] font-bold leading-none text-gray-500 hover:bg-gray-50"
                                            :class="open && 'bg-gray-100'">⋯</button>
                                    <div x-show="open" x-cloak x-transition
                                         class="absolute right-0 z-30 mt-1 w-52 rounded-lg border border-gray-200 bg-white p-1 shadow-lg ring-1 ring-black/5">
                                        {{-- 상태 전환 — 진행중으로·완료처리 --}}
                                        @if ($b['status'] === 'requested')
                                            <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'in_progress')"
                                                    class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[12px] font-medium text-amber-700 hover:bg-amber-50">
                                                <span class="w-4 shrink-0 text-center text-sm">▶</span>
                                                <span class="flex-1">{{ __('shipping.action.start') }}</span>
                                            </button>
                                        @endif
                                        @if (in_array($b['status'], ['requested', 'in_progress'], true))
                                            <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'done')"
                                                    class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[12px] font-medium text-gray-700 hover:bg-gray-50">
                                                <span class="w-4 shrink-0 text-center text-sm text-emerald-500">✓</span>
                                                <span class="flex-1">{{ __('shipping.action.done') }}</span>
                                            </button>
                                            <div class="my-1 h-px bg-gray-100"></div>
                                        @endif
                                        @if (count($deregUrls) > 0)
                                            <button type="button"
                                                    @click="@js($deregUrls).forEach((u, i) => setTimeout(() => { const a = document.createElement('a'); a.href = u; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }, i * 400)); open = false"
                                                    class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[12px] font-medium text-gray-700 hover:bg-gray-50">
                                                <span class="w-4 shrink-0 text-center text-sm text-blue-500">⬇</span>
                                                <span class="flex-1">{{ __('shipping.action.download_dereg', ['count' => count($deregUrls)]) }}</span>
                                            </button>
                                        @else
                                            <div class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-[12px] font-medium text-gray-300">
                                                <span class="w-4 shrink-0 text-center text-sm">⬇</span>
                                                <span class="flex-1">{{ __('shipping.action.download_dereg', ['count' => 0]) }}</span>
                                            </div>
                                        @endif
                                        @if (in_array($b['status'], ['requested', 'in_progress'], true))
                                            <div class="my-1 h-px bg-gray-100"></div>
                                            <button type="button" wire:click="cancel('{{ $b['batch_id'] }}')"
                                                    wire:confirm="{{ __('shipping.confirm.cancel', ['n' => $b['count']]) }}"
                                                    class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[12px] font-medium text-red-600 hover:bg-red-50">
                                                <span class="w-4 shrink-0 text-center text-sm">✕</span>
                                                <span class="flex-1">{{ __('shipping.action.cancel') }}</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- 묶음 미수 게이지 + 완납/경고 --}}
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        @if ($ratioPct !== null)
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-28 overflow-hidden rounded-full bg-gray-100">
                                    <div class="h-full rounded-full {{ $ratioPct > 50 ? 'bg-red-500' : ($ratioPct > 0 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                         style="width: {{ min(100, $ratioPct) }}%"></div>
                                </div>
                                <span class="text-[11px] text-gray-500">
                                    {{ __('shipping.fin.unpaid') }} {{ number_format($b['unpaid_total_krw']) }}원 ({{ $ratioPct }}%)
                                </span>
                            </div>
                        @endif
                        @if ($b['fully_paid'])
                            <span class="badge badge-green">{{ __('shipping.fin.fully_paid') }}</span>
                        @endif
                        @if ($b['fx_missing_count'] > 0)
                            <span class="badge badge-red">{{ __('shipping.fin.fx_missing', ['n' => $b['fx_missing_count']]) }}</span>
                        @endif
                        @if ($b['surrender_unpaid_warning'])
                            <span class="badge badge-amber">{{ __('shipping.fin.surrender_warning') }}</span>
                        @endif
                    </div>

                    {{-- 변경요청 (영업이 in_progress 묶음에 보낸 명시 요청) --}}
                    @foreach ($b['changes'] as $chg)
                        <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5">
                            <span class="badge badge-amber">{{ __('shipping.change.flag') }}</span>
                            <span class="text-[11px] font-semibold text-amber-800">{{ $chg['number'] }}</span>
                            @if ($chg['note'])
                                <span class="text-[11px] text-amber-700">“{{ $chg['note'] }}”</span>
                            @endif
                            <span class="grow"></span>
                            <button type="button" wire:click="acceptChange({{ $chg['id'] }})"
                                    wire:confirm="{{ __('shipping.change.confirm_accept') }}"
                                    class="rounded border border-red-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-red-600 hover:bg-red-50">
                                {{ __('shipping.change.accept') }}
                            </button>
                            <button type="button" wire:click="rejectChange({{ $chg['id'] }})"
                                    class="rounded border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-gray-600 hover:bg-gray-50">
                                {{ __('shipping.change.reject') }}
                            </button>
                        </div>
                    @endforeach

                    {{-- 묶인 차량 칩 (클릭 → 차량 편집 패널) --}}
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($b['vehicles'] as $veh)
                            <a href="{{ route('erp.vehicles.index', ['openVehicle' => $veh['id']]) }}" wire:navigate
                               class="rounded-md border px-2 py-0.5 text-[11px] font-medium hover:border-primary hover:text-primary-text {{ $veh['entry_blocked'] ? 'border-rose-300 bg-rose-50 text-rose-700' : 'border-gray-200 bg-gray-50 text-gray-700' }}">
                                {{ $veh['number'] }}@if ($veh['entry_blocked'] && $veh['unpaid_pct'] !== null) <span class="ml-0.5 font-semibold">· {{ $veh['unpaid_pct'] }}%</span>@endif
                            </a>
                        @endforeach
                    </div>

                    {{-- B/L 발급 인라인 폼 --}}
                    @if ($issuingBatch === $b['batch_id'])
                        <div class="mt-3 rounded-md border border-violet-200 bg-violet-50/60 p-3">
                            <div class="mb-2 text-xs font-bold text-violet-800">{{ __('shipping.bl.issue_title', ['n' => $b['count']]) }}</div>
                            @unless ($b['fully_paid'])
                                <div class="mb-2 rounded border border-red-200 bg-red-50 px-2 py-1 text-[11px] text-red-700">{{ __('shipping.bl.not_fully_paid') }}</div>
                            @endunless
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div>
                                    <label class="label-base">{{ __('shipping.bl.field_type') }}
                                        @if ($b['bl_type']) <span class="text-[10px] text-gray-400">({{ __('shipping.bl.requested_hint') }}: {{ __('shipping.bl.type.'.$b['bl_type']) }})</span> @endif
                                    </label>
                                    <select wire:model="blForm.bl_type" class="input-base">
                                        <option value="original">{{ __('shipping.bl.type.original') }}</option>
                                        <option value="surrender">{{ __('shipping.bl.type.surrender') }}</option>
                                    </select>
                                </div>
                                <div><label class="label-base">{{ __('shipping.bl.field_number') }}</label><input wire:model="blForm.bl_number" type="text" class="input-base" /></div>
                                @if ($b['shipping_method'] === 'CONTAINER')
                                    <div><label class="label-base">{{ __('shipping.bl.field_container') }}</label><input wire:model="blForm.container_number" type="text" class="input-base" /></div>
                                @endif
                                <div><label class="label-base">{{ __('shipping.bl.field_vessel') }}</label><input wire:model="blForm.vessel_name" type="text" class="input-base" /></div>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="applyBlIssue"
                                        class="btn-primary text-[11px]">{{ __('shipping.bl.apply') }}</button>
                                <button type="button" wire:click="cancelIssue"
                                        class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600 hover:bg-gray-50">{{ __('shipping.bl.cancel') }}</button>
                            </div>
                        </div>
                    @endif

                    {{-- 수출신고번호 일괄 기입 인라인 폼 (묶음 공유 1개 → 전체) --}}
                    @if ($declBatch === $b['batch_id'])
                        <div class="mt-3 rounded-md border border-green-200 bg-green-50/60 p-3">
                            <div class="mb-2 text-xs font-bold text-green-800">{{ __('shipping.decl.title', ['n' => $b['count']]) }}</div>
                            <div class="flex flex-wrap items-end gap-2">
                                <div>
                                    <label class="label-base">{{ __('shipping.decl.field_number') }}</label>
                                    <input wire:model="declNumber" type="text" class="input-base w-56" placeholder="{{ __('shipping.decl.placeholder') }}" />
                                </div>
                                <div class="text-[11px] text-green-700">{{ __('shipping.decl.hint') }}</div>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="applyDeclNumber"
                                        class="btn-primary text-[11px]">{{ __('shipping.decl.apply') }}</button>
                                <button type="button" wire:click="cancelDeclNumber"
                                        class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600 hover:bg-gray-50">{{ __('shipping.decl.cancel') }}</button>
                            </div>
                        </div>
                    @endif

                    {{-- 묶음 서류 — 배치 N대를 1서류로 (기존 다중차량 선적서류 재사용). 방식별 2종. --}}
                    @php
                        $idsCsv = implode(',', array_column($b['vehicles'], 'id'));
                        $docTypes = $b['shipping_method'] === 'CONTAINER'
                            ? ['container_invoice_packing' => __('shipping.doc.invoice_packing'), 'container_contract' => __('shipping.doc.contract')]
                            : ['roro_invoice_packing' => __('shipping.doc.invoice_packing'), 'roro_contract' => __('shipping.doc.contract')];
                    @endphp
                    <div class="mt-2 flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-2">
                        <span class="text-[11px] font-semibold text-gray-400">{{ __('shipping.doc.label') }}</span>
                        @foreach ($docTypes as $type => $label)
                            <a href="{{ route('erp.vehicles.documents.multi', ['type' => $type, 'ids' => $idsCsv]) }}" target="_blank" rel="noopener"
                               class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100">
                                ⬇ {{ $b['shipping_method'] }} {{ $label }}
                            </a>
                        @endforeach
                        {{-- 판매계약서 다운로드 + 전자서명 칩 — 단일 바이어·통화 묶음만(혼합이면 컨트롤러 422라 비활성) --}}
                        @if ($b['sales_contract_ok'])
                            <a href="{{ route('erp.vehicles.documents.multi', ['type' => 'sales_contract', 'ids' => $idsCsv]) }}" target="_blank" rel="noopener"
                               class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100">
                                ⬇ {{ __('vehicle.shipdoc.sales_contract') }}
                            </a>
                            <x-erp.esign-chip
                                :status="$b['sign']['status']"
                                :contract-id="$b['sign']['id'] ?? null"
                                request-click="requestSignatureForBatch('{{ $b['batch_id'] }}')"
                                link-click="showSignLink({{ $b['sign']['id'] ?? 0 }})" />
                        @else
                            <span title="{{ __('shipping.doc.sc_mixed') }}"
                                  class="cursor-not-allowed rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-semibold text-gray-400">
                                ⬇ {{ __('vehicle.shipdoc.sales_contract') }} ⚠
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $batchPage->links() }}</div>
    @endif
    @endif

    {{-- ══ 2차 비용 탭 — 면허비 묶음 n/1 (2차 정산 pending 묶음, paid월 접기, 미기입 뱃지) ══ --}}
    @if($viewTab === 'cost')
    <p class="mb-3 text-xs text-gray-500">{{ __('shipping.license.tab_hint') }}</p>
    @if($costBatches->isEmpty())
        <div class="card text-center text-sm text-gray-400">{{ __('shipping.license.empty') }}</div>
    @else
        <div class="space-y-4">
            @foreach($costBatches as $month => $monthBatches)
            <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="rounded-lg border border-gray-200">
                {{-- 월 헤더 (접기/펼치기, 최신월 기본 펼침) --}}
                <button type="button" @click="open = ! open"
                        class="flex w-full items-center gap-2 bg-gray-50 px-4 py-2 text-left">
                    <span class="text-sm font-bold text-gray-700">{{ $month }}</span>
                    @if($month !== '—')
                    @php $payDate = \Carbon\Carbon::parse($month.'-01')->addMonthNoOverflow()->format('Y-m').'-10'; @endphp
                    <span class="text-[11px] text-gray-400">→ {{ $payDate }} {{ __('shipping.license.pay_label') }}</span>
                    @endif
                    <span class="pill-count">{{ __('shipping.license.batch_n', ['n' => $monthBatches->count()]) }}</span>
                    @php $notEntered = $monthBatches->where('not_entered', true)->count(); @endphp
                    @if($notEntered > 0)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">{{ __('shipping.license.not_entered_n', ['n' => $notEntered]) }}</span>
                    @endif
                    <svg class="ml-auto h-4 w-4 flex-shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>

                <div x-show="open" x-cloak class="divide-y divide-gray-100 p-2">
                    @foreach($monthBatches as $b)
                    @php $preview = null; @endphp
                    <div class="p-2">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($b['not_entered'])
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">{{ __('shipping.license.badge_not_entered') }}</span>
                            @else
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">{{ __('shipping.license.badge_entered') }}</span>
                            @endif
                            <span class="text-sm font-bold text-gray-800">{{ $b['buyer'] ?? '—' }}</span>
                            <span class="pill-count">{{ __('shipping.vehicles_n', ['n' => $b['count']]) }}</span>
                            <button type="button" wire:click="openLicenseFee('{{ $b['batch_id'] }}')"
                                    class="ml-auto rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                {{ __('shipping.license.enter_btn') }}
                            </button>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach($b['vehicles'] as $veh)
                            <span class="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] text-gray-600">
                                {{ $veh['number'] }} <span class="text-gray-400">{{ number_format($veh['license']) }}</span>
                            </span>
                            @endforeach
                        </div>

                        {{-- 면허비 n/1 인라인 폼 --}}
                        @if($licenseBatch === $b['batch_id'])
                        @php
                            $totalNum = (int) round((float) str_replace(',', '', $licenseTotal ?: '0'));
                            $n = max($b['count'], 1);
                            $base = intdiv($totalNum, $n);
                            $rem = $totalNum - $base * $n;
                        @endphp
                        <div class="mt-2 rounded-md border border-violet-200 bg-violet-50/60 p-3">
                            <div class="mb-2 text-xs font-bold text-violet-800">{{ __('shipping.license.form_title', ['n' => $b['count']]) }}</div>
                            <div class="flex flex-wrap items-end gap-2">
                                <div>
                                    <label class="label-base">{{ __('shipping.license.total_label') }}</label>
                                    <input wire:model.live="licenseTotal" type="text" class="input-base w-40" placeholder="200,000" />
                                </div>
                                <div class="text-[11px] text-violet-700">
                                    @if($totalNum > 0)
                                        {{ __('shipping.license.preview', ['n' => $n, 'each' => number_format($base + $rem)]) }}
                                        @if($n > 1) + {{ number_format($base) }} × {{ $n - 1 }} @endif
                                        = <b>{{ number_format($totalNum) }}</b>
                                    @else
                                        {{ __('shipping.license.preview_hint') }}
                                    @endif
                                </div>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="applyLicenseFee" class="btn-primary text-[11px]">{{ __('shipping.license.apply_btn') }}</button>
                                <button type="button" wire:click="cancelLicenseFee"
                                        class="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600 hover:bg-gray-50">{{ __('shipping.bl.cancel') }}</button>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
    @endif
    @endif

    {{-- 전자서명 링크 발급 모달 — 발급/재표시된 signed URL 복사(바이어에게 전달) --}}
    @if($showSignModal)
    <div class="fixed inset-0 z-[120] flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showSignModal', false)">
        <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl" x-data="{ copied: false }">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-base font-bold text-gray-800">✍ {{ __('signed_contract.request_btn') }} · {{ $signContractNo }}</h3>
                <button type="button" wire:click="$set('showSignModal', false)" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <p class="mb-2 text-sm text-gray-600">{{ __('signed_contract.modal.hint') }}</p>
            <div class="flex gap-2">
                <input type="text" readonly value="{{ $signUrl }}" x-ref="signUrl"
                       class="w-full rounded border border-gray-300 bg-gray-50 px-2 py-1.5 text-xs text-gray-700" />
                <button type="button"
                        @click="$refs.signUrl.select(); navigator.clipboard.writeText($refs.signUrl.value); copied = true; setTimeout(() => copied = false, 1500)"
                        class="shrink-0 rounded bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700">
                    <span x-show="!copied">{{ __('signed_contract.modal.copy') }}</span>
                    <span x-show="copied" x-cloak>✓</span>
                </button>
            </div>
            <p class="mt-3 text-xs text-gray-400">{{ __('signed_contract.modal.expire') }}</p>
        </div>
    </div>
    @endif
</div>
