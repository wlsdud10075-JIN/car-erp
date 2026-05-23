<?php

use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    #[Url] public string $userFilter = '';

    #[Url] public string $actionFilter = '';

    #[Url] public string $columnFilter = '';

    #[Url] public string $dateFrom = '';

    #[Url] public string $dateTo = '';

    #[Url] public int $perPage = 25;

    #[Computed]
    public function logs()
    {
        return AuditLog::query()
            ->with(['user', 'approvalRequest'])
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', $this->userFilter))
            ->when($this->actionFilter !== '', fn ($q) => $q->where('action', $this->actionFilter))
            ->when($this->columnFilter !== '', fn ($q) => $q->where('column_name', $this->columnFilter))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest('created_at')
            ->paginate($this->perPage);
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
        $this->reset(['userFilter', 'actionFilter', 'columnFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }
}; ?>

<div wire:poll.30s class="flex h-full flex-col gap-4 p-3 md:p-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">감사 로그</h2>
            <p class="mt-0.5 text-xs text-gray-500">변경 추적 (큐 11-4 도입 이후 — 그 이전 액션은 미기록).</p>
        </div>
        <span class="text-xs text-gray-400">총 {{ number_format($this->logs->total()) }} 건</span>
    </div>

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <select wire:model.live="userFilter" class="input-filter">
            <option value="">사용자 전체</option>
            @foreach($this->users as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="actionFilter" class="input-filter">
            <option value="">액션 전체</option>
            @foreach($this->distinctActions as $a)
                <option value="{{ $a }}">{{ $a }}</option>
            @endforeach
        </select>
        <select wire:model.live="columnFilter" class="input-filter">
            <option value="">컬럼 전체</option>
            @foreach($this->distinctColumns as $c)
                <option value="{{ $c }}">{{ $c }}</option>
            @endforeach
        </select>
        <input wire:model.live.debounce.400ms="dateFrom" type="date" class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model.live.debounce.400ms="dateTo" type="date" class="input-filter" />
        <button wire:click="resetFilters" class="text-xs text-violet-600 hover:underline">필터 초기화</button>
        <select wire:model.live="perPage" class="input-filter ml-auto">
            <option value="25">25개</option>
            <option value="50">50개</option>
            <option value="100">100개</option>
        </select>
    </div>

    {{-- 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">시각</th>
                    <th class="pb-2 pr-4 font-medium">사용자</th>
                    <th class="pb-2 pr-4 font-medium">대상</th>
                    <th class="pb-2 pr-4 font-medium">액션</th>
                    <th class="pb-2 pr-4 font-medium">컬럼</th>
                    <th class="pb-2 pr-4 font-medium">이전 → 이후</th>
                    <th class="pb-2 pr-4 font-medium">IP</th>
                    <th class="pb-2 pr-4 font-medium">승인</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td class="py-2 pr-4 text-gray-700">{{ $log->user?->name ?? '시스템' }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        <span class="font-mono">{{ class_basename($log->auditable_type) }}</span>
                        <span class="text-gray-400">#{{ $log->auditable_id }}</span>
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
                        <span class="badge {{ $actionBadge }} text-[10px]">{{ $log->action }}</span>
                    </td>
                    <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ $log->column_name ?? '-' }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600 max-w-md">
                        @if($log->old_value !== null || $log->new_value !== null)
                            <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($log->old_value ?? '(null)', 40) }}</span>
                            <span class="mx-1 text-gray-400">→</span>
                            <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($log->new_value ?? '(null)', 40) }}</span>
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
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">조회 조건에 일치하는 감사 로그가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <span class="font-mono text-[10px] text-gray-400">{{ $log->created_at->format('Y-m-d H:i') }}</span>
                <span class="badge badge-blue text-[10px]">{{ $log->action }}</span>
            </div>
            <div class="mt-1 text-sm font-medium text-gray-800">{{ $log->user?->name ?? '시스템' }}</div>
            <div class="text-xs text-gray-500">
                {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                @if($log->column_name) · <span class="font-mono">{{ $log->column_name }}</span> @endif
            </div>
            @if($log->old_value !== null || $log->new_value !== null)
            <div class="mt-1 text-xs">
                <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($log->old_value ?? '(null)', 30) }}</span>
                →
                <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($log->new_value ?? '(null)', 30) }}</span>
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">감사 로그가 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>
</div>
