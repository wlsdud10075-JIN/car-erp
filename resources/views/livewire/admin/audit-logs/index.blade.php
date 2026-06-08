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
            <h2 class="text-xl font-bold text-gray-800">{{ __('log.audit_title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.audit_subtitle') }}</p>
        </div>
        <span class="text-xs text-gray-400">{{ __('log.total', ['count' => number_format($this->logs->total())]) }}</span>
    </div>

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
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
                <option value="{{ $c }}" title="{{ $c }}">{{ config('column_labels.vehicles.'.$c, $c) }}</option>
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
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td class="py-2 pr-4 text-gray-700">{{ $log->user?->name ?? __('log.system') }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        <span>{{ $modelLabel }}</span>
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
                        <span class="badge {{ $actionBadge }} text-[10px]" title="{{ $log->action }}">{{ $actionLabel }}</span>
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-700"
                        @if($log->column_name) title="{{ $log->column_name }}" @endif>
                        {{ $columnLabel }}
                    </td>
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
        @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <span class="font-mono text-[10px] text-gray-400">{{ $log->created_at->format('Y-m-d H:i') }}</span>
                <span class="badge badge-blue text-[10px]" title="{{ $log->action }}">{{ $actionLabelM }}</span>
            </div>
            <div class="mt-1 text-sm font-medium text-gray-800">{{ $log->user?->name ?? __('log.system') }}</div>
            <div class="text-xs text-gray-500">
                {{ $modelLabelM }} #{{ $log->auditable_id }}
                @if($log->column_name) · <span title="{{ $log->column_name }}">{{ $columnLabelM }}</span> @endif
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
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.audit_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>
</div>
