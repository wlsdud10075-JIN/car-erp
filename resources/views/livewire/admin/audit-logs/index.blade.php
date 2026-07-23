<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ColumnLabel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url] public string $search = '';

    #[Url] public string $userFilter = '';

    #[Url] public string $actionFilter = '';

    #[Url] public string $columnFilter = '';

    #[Url] public string $dateFrom = '';

    #[Url] public string $dateTo = '';

    #[Url] public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::query()
            ->with(['user', 'approvalRequest'])
            ->when($this->search !== '', fn ($q) => $this->applySearch($q))
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', $this->userFilter))
            ->when($this->actionFilter !== '', fn ($q) => $q->where('action', $this->actionFilter))
            ->when($this->columnFilter !== '', fn ($q) => $q->where('column_name', $this->columnFilter))
            // 성능(jin 2026-07-23): whereDate 는 DATE(created_at) 로 index 무효 → 범위조건으로 created_at 인덱스 유지.
            //   audit_logs 는 무한 증가 테이블이라 풀스캔 방지 중요. (ssancar 504 교훈 패턴②)
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', $this->dateFrom.' 00:00:00'))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', $this->dateTo.' 23:59:59'))
            ->latest('created_at')
            ->paginate($this->perPage);
    }

    /**
     * 검색 = 차량번호(차량·정산·판매잔금·매입잔금 로그 전부) 또는 처리자 이름.
     * 차량번호로 매칭된 차량 → 그 차량의 정산/잔금 로그까지 함께 걸린다.
     */
    private function applySearch($q)
    {
        $term = '%'.$this->search.'%';
        $vehicleIds = \App\Models\Vehicle::where('vehicle_number', 'like', $term)->pluck('id')->all();
        $userIds = User::where('name', 'like', $term)->pluck('id')->all();

        $settlementIds = $vehicleIds ? \App\Models\Settlement::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];
        $fpIds = $vehicleIds ? \App\Models\FinalPayment::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];
        $pbpIds = $vehicleIds ? \App\Models\PurchaseBalancePayment::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];

        return $q->where(function ($q2) use ($vehicleIds, $settlementIds, $fpIds, $pbpIds, $userIds) {
            $matched = false;
            if ($userIds) {
                $q2->orWhereIn('user_id', $userIds);
                $matched = true;
            }
            foreach ([
                [\App\Models\Vehicle::class, $vehicleIds],
                [\App\Models\Settlement::class, $settlementIds],
                [\App\Models\FinalPayment::class, $fpIds],
                [\App\Models\PurchaseBalancePayment::class, $pbpIds],
            ] as [$cls, $ids]) {
                if ($ids) {
                    $q2->orWhere(fn ($s) => $s->where('auditable_type', $cls)->whereIn('auditable_id', $ids));
                    $matched = true;
                }
            }
            if (! $matched) {
                $q2->whereRaw('1 = 0');   // 매칭 없으면 0건 (전체 노출 방지)
            }
        });
    }

    /**
     * 현재 페이지 로그의 차량번호 해석 [logId => 차량번호].
     * 차량 직접 로그 + 정산/판매잔금/매입잔금(→ vehicle_id) 로그를 배치 조회 (N+1 회피).
     */
    #[Computed]
    public function vehicleNumbers(): array
    {
        $byType = ['Vehicle' => [], 'Settlement' => [], 'FinalPayment' => [], 'PurchaseBalancePayment' => []];
        foreach ($this->logs as $log) {
            $short = class_basename($log->auditable_type);
            if (isset($byType[$short]) && $log->auditable_id) {
                $byType[$short][$log->auditable_id][] = $log->id;
            }
        }

        $map = [];

        if ($byType['Vehicle']) {
            $nums = \App\Models\Vehicle::whereIn('id', array_keys($byType['Vehicle']))->pluck('vehicle_number', 'id');
            foreach ($byType['Vehicle'] as $vid => $logIds) {
                foreach ($logIds as $lid) {
                    $map[$lid] = $nums[$vid] ?? null;
                }
            }
        }

        foreach ([
            'Settlement' => \App\Models\Settlement::class,
            'FinalPayment' => \App\Models\FinalPayment::class,
            'PurchaseBalancePayment' => \App\Models\PurchaseBalancePayment::class,
        ] as $short => $cls) {
            if (! $byType[$short]) {
                continue;
            }
            $vehByRecord = $cls::whereIn('id', array_keys($byType[$short]))->pluck('vehicle_id', 'id');
            $vehIds = array_values(array_filter(array_unique($vehByRecord->all())));
            $nums = $vehIds ? \App\Models\Vehicle::whereIn('id', $vehIds)->pluck('vehicle_number', 'id') : collect();
            foreach ($byType[$short] as $rid => $logIds) {
                $vid = $vehByRecord[$rid] ?? null;
                $num = $vid ? ($nums[$vid] ?? null) : null;
                foreach ($logIds as $lid) {
                    $map[$lid] = $num;
                }
            }
        }

        return $map;
    }

    #[Computed]
    public function users()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function distinctActions(): array
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')->toArray();
    }

    #[Computed]
    public function distinctColumns(): array
    {
        return AuditLog::query()->whereNotNull('column_name')->distinct()->orderBy('column_name')->pluck('column_name')->toArray();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'userFilter', 'actionFilter', 'columnFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }
}; ?>

<div wire:poll.30s class="flex h-full flex-col gap-4 p-3 md:p-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('log.audit_title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.audit_subtitle') }}</p>
        </div>
        <span class="text-xs text-gray-400">{{ __('log.total', ['count' => number_format($this->logs->total())]) }}</span>
    </div>

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="{{ __('log.audit_search') }}"
               class="input-filter w-full sm:w-64" />
        <select wire:model.live="userFilter" class="input-filter">
            <option value="">{{ __('log.all_users') }}</option>
            @foreach($this->users as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="actionFilter" class="input-filter">
            <option value="">{{ __('log.all_actions') }}</option>
            @foreach($this->distinctActions as $a)
                <option value="{{ $a }}">{{ \App\Support\ColumnLabel::action($a) }}</option>
            @endforeach
        </select>
        <select wire:model.live="columnFilter" class="input-filter">
            <option value="">{{ __('log.all_columns') }}</option>
            @foreach($this->distinctColumns as $c)
                <option value="{{ $c }}" title="{{ $c }}">{{ \App\Support\ColumnLabel::columnAny($c) }}</option>
            @endforeach
        </select>
        <input wire:model.live.debounce.400ms="dateFrom" type="date" class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model.live.debounce.400ms="dateTo" type="date" class="input-filter" />
        <button wire:click="resetFilters" class="text-xs text-violet-600 hover:underline">{{ __('log.reset_filters') }}</button>
        <select wire:model.live="perPage" class="input-filter ml-auto">
            <option value="25">{{ __('common.per_page', ['count' => 25]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
    </div>

    {{-- 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.time') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.user') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.target') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.action') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.column') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.change') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.ip') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.approval') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                @php
                    $modelLabel = \App\Support\ColumnLabel::model($log->auditable_type);
                    $columnLabel = \App\Support\ColumnLabel::column($log->auditable_type, $log->column_name);
                    $actionLabel = \App\Support\ColumnLabel::action($log->action);
                    $vnum = $this->vehicleNumbers[$log->id] ?? null;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td class="py-2 pr-4 text-gray-700">{{ $log->user?->name ?? __('log.system') }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        <span>{{ $modelLabel }}</span>
                        @if($vnum)
                            <span class="ml-1 font-medium text-gray-800">{{ $vnum }}</span>
                        @else
                            <span class="text-gray-400">#{{ $log->auditable_id }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">
                        @php
                            $actionBadge = match($log->action) {
                                'created' => 'badge-green',
                                'updated' => 'badge-blue',
                                'deleted' => 'badge-red',
                                'restored' => 'badge-amber',
                                'force_deleted' => 'badge-red',
                                default => 'badge-gray',
                            };
                        @endphp
                        <span class="badge {{ $actionBadge }} text-[10px]" title="{{ $log->action }}">{{ $actionLabel }}</span>
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-700"
                        @if($log->column_name) title="{{ $log->column_name }}" @endif>
                        {{ $columnLabel }}
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-600 max-w-md">
                        @php
                            $oldV = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->old_value);
                            $newV = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->new_value);
                        @endphp
                        @if($log->old_value !== null || $log->new_value !== null)
                            <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($oldV ?? '(null)', 40) }}</span>
                            <span class="mx-1 text-gray-400">→</span>
                            <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($newV ?? '(null)', 40) }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4 font-mono text-[10px] text-gray-400">{{ $log->ip_address ?? '-' }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        @if($log->approval_request_id)
                            <span class="font-mono text-violet-600">#{{ $log->approval_request_id }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('log.audit_empty_filtered') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        @php
            $modelLabelM = \App\Support\ColumnLabel::model($log->auditable_type);
            $columnLabelM = \App\Support\ColumnLabel::column($log->auditable_type, $log->column_name);
            $actionLabelM = \App\Support\ColumnLabel::action($log->action);
            $vnumM = $this->vehicleNumbers[$log->id] ?? null;
        @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <span class="font-mono text-[10px] text-gray-400">{{ $log->created_at->format('Y-m-d H:i') }}</span>
                <span class="badge badge-blue text-[10px]" title="{{ $log->action }}">{{ $actionLabelM }}</span>
            </div>
            <div class="mt-1 text-sm font-medium text-gray-800">{{ $log->user?->name ?? __('log.system') }}</div>
            <div class="text-xs text-gray-500">
                {{ $modelLabelM }} {{ $vnumM ? $vnumM : '#'.$log->auditable_id }}
                @if($log->column_name) · <span title="{{ $log->column_name }}">{{ $columnLabelM }}</span> @endif
            </div>
            @if($log->old_value !== null || $log->new_value !== null)
            @php
                $oldVM = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->old_value);
                $newVM = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->new_value);
            @endphp
            <div class="mt-1 text-xs">
                <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($oldVM ?? '(null)', 30) }}</span>
                →
                <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($newVM ?? '(null)', 30) }}</span>
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.audit_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>
</div>
