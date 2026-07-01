<?php

use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
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

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        // 딥링크 진입 — 승인 권한자면 2차 비용 탭 열고 해당 묶음 면허비 폼 자동 오픈.
        if ($this->focus !== '' && auth()->user()?->canApprove()) {
            $this->viewTab = 'cost';
            $this->licenseBatch = $this->focus;
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
     * 배치 단위 상태 전환 — mutating endpoint 이므로 매번 재인가(SKILLS §8 #26).
     * done 전환 시 연동된 shipping_requested 알람을 resolve(벨/알림 카운트 정합).
     */
    public function changeStatus(string $batchId, string $to): void
    {
        abort_unless((bool) auth()->user()?->canAccessClearance(), 403);

        if (! in_array($to, [ShippingRequest::STATUS_IN_PROGRESS, ShippingRequest::STATUS_DONE], true)) {
            return;
        }

        $rows = ShippingRequest::where('batch_id', $batchId)->get();
        if ($rows->isEmpty()) {
            return;
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

        // 완납 가드 — 미완납(환율 미입력 포함) 묶음 발급 차단
        $fin = ShippingRequest::financeForVehicles($rows->map->vehicle->filter());
        if (! $fin['fully_paid']) {
            $this->dispatch('notify', message: __('shipping.bl.not_fully_paid'), type: 'error');

            return;
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

        $batches = ShippingRequest::query()
            ->with(['vehicle', 'buyer', 'consignee'])
            ->whereIn('batch_id', $batchIds)
            ->get()
            ->groupBy('batch_id')->map(function ($items) {
                $f = $items->first();
                $memberVehicles = $items->map->vehicle->filter();
                $fin = ShippingRequest::financeForVehicles($memberVehicles);

                return array_merge([
                    'batch_id' => (string) $f->batch_id,
                    'buyer' => $f->buyer?->name,
                    'consignee' => $f->consignee?->name,
                    'shipping_method' => $f->shipping_method,
                    'bl_type' => $f->bl_type,
                    'bl_status' => $f->bl_status ?? ShippingRequest::BL_STATUS_NONE,
                    'requested_by' => $f->requested_by_email,
                    'requested_at' => $f->requested_at,
                    'status' => $f->status,
                    'vehicles' => $items->map(fn ($r) => [
                        'id' => $r->vehicle_id,
                        'number' => $r->vehicle?->vehicle_number ?? ('#'.$r->vehicle_id),
                    ])->values()->all(),
                    'count' => $items->count(),
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
                            <a href="{{ route('erp.vehicles.index', ['ids' => $idsCsv]) }}" wire:navigate
                               class="rounded-md border border-primary bg-primary-light px-2.5 py-1 text-[11px] font-semibold text-primary-text hover:opacity-90">
                                {{ __('shipping.action.open_in_vehicles', ['count' => $b['count']]) }}
                            </a>
                            @if ($b['status'] === 'requested')
                                <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'in_progress')"
                                        class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-100">
                                    {{ __('shipping.action.start') }}
                                </button>
                            @endif
                            {{-- B/L 발급 (승인 권한 + 아직 미발급) --}}
                            @if ($canApprove && $b['bl_status'] !== 'issued')
                                <button type="button" wire:click="openIssue('{{ $b['batch_id'] }}')"
                                        class="rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-700 hover:bg-violet-100">
                                    {{ __('shipping.bl.issue') }}
                                </button>
                            @endif
                            @if (in_array($b['status'], ['requested', 'in_progress'], true))
                                <button type="button" wire:click="changeStatus('{{ $b['batch_id'] }}', 'done')"
                                        class="rounded-md border border-gray-300 bg-gray-50 px-2.5 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-100">
                                    {{ __('shipping.action.done') }}
                                </button>
                                <button type="button" wire:click="cancel('{{ $b['batch_id'] }}')"
                                        wire:confirm="{{ __('shipping.confirm.cancel', ['n' => $b['count']]) }}"
                                        class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-100">
                                    {{ __('shipping.action.cancel') }}
                                </button>
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
                               class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-700 hover:border-primary hover:text-primary-text">
                                {{ $veh['number'] }}
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
</div>
