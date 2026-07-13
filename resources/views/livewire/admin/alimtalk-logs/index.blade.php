<?php

use App\Models\AlimtalkLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $reportFilter = '';
    public bool $onlyAttention = false;
    #[Url] public int $perPage = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessAdmin(), 403);
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 30;
        }
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedReportFilter(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyAttention(): void
    {
        $this->resetPage();
    }

    /** 미도달/실패 1건 확인 처리 — 확인 전까지 사이드바 배지에 남는다. */
    public function acknowledge(int $id): void
    {
        abort_unless(auth()->user()?->canAccessAdmin(), 403);

        $log = AlimtalkLog::whereKey($id)->needsAttention()->first();
        if ($log) {
            $log->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => auth()->id()])->save();
        }
        unset($this->logs);
    }

    /** 주의 필요(미도달·실패) 전체 확인 처리. */
    public function acknowledgeAll(): void
    {
        abort_unless(auth()->user()?->canAccessAdmin(), 403);

        AlimtalkLog::query()->needsAttention()->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(),
        ]);
        unset($this->logs);
    }

    #[Computed]
    public function attentionCount(): int
    {
        return AlimtalkLog::query()->needsAttention()->count();
    }

    #[Computed]
    public function logs()
    {
        return AlimtalkLog::query()
            ->with(['vehicle:id,vehicle_number', 'user:id,name'])
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('phone', 'like', $term)
                        ->orWhere('template_code', 'like', $term)
                        ->orWhereHas('vehicle', fn ($v) => $v->where('vehicle_number', 'like', $term));
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->reportFilter === 'delivered', fn ($q) => $q->where('report_status', 'delivered'))
            ->when($this->reportFilter === 'undelivered', fn ($q) => $q->where('report_status', 'undelivered'))
            ->when($this->reportFilter === 'pending', fn ($q) => $q->where('status', 'sent')->whereNull('report_status'))
            ->when($this->onlyAttention, fn ($q) => $q->needsAttention())
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('log.at_title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.at_subtitle', ['count' => $this->logs->total()]) }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if($this->attentionCount > 0)
            <button type="button" wire:click="acknowledgeAll"
                    wire:confirm="{{ __('log.at_ack_all') }}?"
                    class="rounded-md bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">
                {{ __('log.at_ack_all') }} ({{ $this->attentionCount }})
            </button>
            @endif
            <select wire:model.live="perPage" class="input-base w-auto">
                <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
                <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
                <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
                <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
            </select>
        </div>
    </div>

    <div class="card-tight flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="{{ __('log.at_search') }}"
               class="input-base w-full sm:w-72" />
        <select wire:model.live="statusFilter" class="input-base w-full sm:w-auto">
            <option value="">{{ __('log.at_all_status') }}</option>
            <option value="sent">{{ __('log.at_status.sent') }}</option>
            <option value="failed">{{ __('log.at_status.failed') }}</option>
            <option value="skipped">{{ __('log.at_status.skipped') }}</option>
        </select>
        <select wire:model.live="reportFilter" class="input-base w-full sm:w-auto">
            <option value="">{{ __('log.at_all_report') }}</option>
            <option value="delivered">{{ __('log.at_report.delivered') }}</option>
            <option value="undelivered">{{ __('log.at_report.undelivered') }}</option>
            <option value="pending">{{ __('log.at_report.pending') }}</option>
        </select>
        <label class="flex items-center gap-1.5 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="onlyAttention" class="rounded border-gray-300" />
            {{ __('log.at_only_attention') }}
        </label>
    </div>

    {{-- 테이블 (데스크탑) --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.time') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.template') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.phone') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.status') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.report') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.vehicle') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.at_col.detail') }}</th>
                    <th class="pb-2 pr-4 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 text-gray-600 text-xs whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="py-2 pr-4 text-gray-700 text-xs whitespace-nowrap">{{ $log->template_code }}</td>
                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $log->phone }}</td>
                    <td class="py-2 pr-4">
                        <span class="badge {{ ['sent' => 'badge-blue', 'failed' => 'badge-red', 'skipped' => 'badge-gray'][$log->status] ?? 'badge-gray' }}">
                            {{ __('log.at_status.'.$log->status) }}
                        </span>
                    </td>
                    <td class="py-2 pr-4">
                        @if($log->report_status === 'delivered')
                            <span class="badge badge-green">{{ __('log.at_report.delivered') }}</span>
                        @elseif($log->report_status === 'undelivered')
                            <span class="badge badge-red">{{ __('log.at_report.undelivered') }}</span>
                        @elseif($log->status === 'sent')
                            <span class="badge badge-amber">{{ __('log.at_report.pending') }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $log->vehicle?->vehicle_number ?? '-' }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500 max-w-xs truncate" title="{{ $log->error ?? $log->message }}">
                        {{ $log->error ?? \Illuminate\Support\Str::limit($log->message, 40) }}
                    </td>
                    <td class="py-2 pr-4 whitespace-nowrap">
                        @if(is_null($log->acknowledged_at) && ($log->status === 'failed' || $log->report_status === 'undelivered'))
                            <button type="button" wire:click="acknowledge({{ $log->id }})"
                                    class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-100">
                                {{ __('log.at_ack') }}
                            </button>
                        @elseif(! is_null($log->acknowledged_at))
                            <span class="text-[11px] text-gray-400">{{ __('log.at_acked') }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('log.at_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        <div class="card-tight space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-800 text-sm">{{ $log->phone }}</span>
                <div class="flex items-center gap-1">
                    <span class="badge {{ ['sent' => 'badge-blue', 'failed' => 'badge-red', 'skipped' => 'badge-gray'][$log->status] ?? 'badge-gray' }} text-[10px]">
                        {{ __('log.at_status.'.$log->status) }}
                    </span>
                    @if($log->report_status === 'delivered')
                        <span class="badge badge-green text-[10px]">{{ __('log.at_report.delivered') }}</span>
                    @elseif($log->report_status === 'undelivered')
                        <span class="badge badge-red text-[10px]">{{ __('log.at_report.undelivered') }}</span>
                    @elseif($log->status === 'sent')
                        <span class="badge badge-amber text-[10px]">{{ __('log.at_report.pending') }}</span>
                    @endif
                </div>
            </div>
            <div class="text-xs text-gray-500">{{ $log->template_code }} @if($log->vehicle) · {{ $log->vehicle->vehicle_number }} @endif</div>
            <div class="text-[11px] text-gray-400">{{ $log->created_at?->format('Y-m-d H:i') }}</div>
            @if($log->error)<div class="text-[11px] text-red-500">{{ $log->error }}</div>@endif
            @if(is_null($log->acknowledged_at) && ($log->status === 'failed' || $log->report_status === 'undelivered'))
                <button type="button" wire:click="acknowledge({{ $log->id }})"
                        class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600">{{ __('log.at_ack') }}</button>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.at_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>

</div>
</div>
