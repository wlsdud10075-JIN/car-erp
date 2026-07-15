<?php

use App\Models\MailDeliveryLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $channelFilter = '';
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

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return MailDeliveryLog::query()
            ->with(['vehicle:id,vehicle_number', 'user:id,name'])
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($q2) use ($term) {
                    $q2->where('to_email', 'like', $term)
                        ->orWhere('subject', 'like', $term)
                        ->orWhereHas('vehicle', fn ($v) => $v->where('vehicle_number', 'like', $term))
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term));
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->channelFilter, fn ($q) => $q->where('channel', $this->channelFilter))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }
}; ?>

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('log.md_title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.md_subtitle', ['count' => $this->logs->total()]) }}</p>
        </div>
        <select wire:model.live="perPage" class="input-base w-auto">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
    </div>

    <div class="card-tight flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="{{ __('log.md_search') }}"
               class="input-base w-full sm:w-72" />
        <select wire:model.live="statusFilter" class="input-base w-full sm:w-auto">
            <option value="">{{ __('log.md_all_status') }}</option>
            <option value="sent">{{ __('log.md_status.sent') }}</option>
            <option value="failed">{{ __('log.md_status.failed') }}</option>
        </select>
        <select wire:model.live="channelFilter" class="input-base w-full sm:w-auto">
            <option value="">{{ __('log.md_all_channel') }}</option>
            <option value="gmail">{{ __('log.md_channel.gmail') }}</option>
            <option value="ses">{{ __('log.md_channel.ses') }}</option>
        </select>
    </div>

    {{-- 테이블 (데스크탑) --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.time') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.sender') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.vehicle') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.to') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.channel') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.subject') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.documents') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.md_col.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 text-gray-600 text-xs whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $log->user?->name ?? '-' }}</td>
                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $log->vehicle?->vehicle_number ?? '-' }}</td>
                    <td class="py-2 pr-4 text-gray-700 whitespace-nowrap">{{ $log->to_email }}</td>
                    <td class="py-2 pr-4">
                        <span class="badge {{ $log->channel === 'ses' ? 'badge-teal' : 'badge-purple' }}">
                            {{ __('log.md_channel.'.$log->channel) }}
                        </span>
                    </td>
                    <td class="py-2 pr-4 text-gray-600 text-xs max-w-xs truncate" title="{{ $log->subject }}">{{ $log->subject }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500 max-w-xs truncate" title="{{ implode(', ', $log->document_names ?? []) }}">
                        @php $docs = $log->document_names ?? []; @endphp
                        @if(count($docs) > 0)
                            {{ count($docs) }}{{ __('log.md_doc_unit') }}<span class="text-gray-400"> · {{ \Illuminate\Support\Str::limit(implode(', ', $docs), 30) }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4 whitespace-nowrap">
                        @if($log->status === 'sent')
                            <span class="badge badge-green">{{ __('log.md_status.sent') }}</span>
                        @else
                            <span class="badge badge-red" title="{{ $log->error }}">{{ __('log.md_status.failed') }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('log.md_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        <div class="card-tight space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-800 text-sm">{{ $log->to_email }}</span>
                @if($log->status === 'sent')
                    <span class="badge badge-green text-[10px]">{{ __('log.md_status.sent') }}</span>
                @else
                    <span class="badge badge-red text-[10px]">{{ __('log.md_status.failed') }}</span>
                @endif
            </div>
            <div class="text-xs text-gray-600 truncate">{{ $log->subject }}</div>
            <div class="text-xs text-gray-500">
                {{ $log->user?->name ?? '-' }}
                @if($log->vehicle) · {{ $log->vehicle->vehicle_number }} @endif
                · {{ __('log.md_channel.'.$log->channel) }}
            </div>
            @php $docs = $log->document_names ?? []; @endphp
            @if(count($docs) > 0)<div class="text-[11px] text-gray-400 truncate">{{ implode(', ', $docs) }}</div>@endif
            <div class="text-[11px] text-gray-400">{{ $log->created_at?->format('Y-m-d H:i') }}</div>
            @if($log->status === 'failed' && $log->error)<div class="text-[11px] text-red-500">{{ $log->error }}</div>@endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.md_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>

</div>
