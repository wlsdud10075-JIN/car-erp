<?php

use App\Models\DocumentAccessLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $typeFilter = '';
    #[Url] public int $perPage = 30;

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

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return DocumentAccessLog::query()
            ->with(['user:id,name,email', 'vehicle:id,vehicle_number,sales_channel'])
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($q2) use ($term) {
                    $q2->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                        ->orWhereHas('vehicle', fn ($v) => $v->where('vehicle_number', 'like', $term));
                });
            })
            ->when($this->typeFilter, fn ($q) => $q->where('document_type', $this->typeFilter))
            ->orderByDesc('created_at')
            ->paginate($this->perPage);
    }
}; ?>

<div>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">문서 다운로드 감사 로그</h1>
            <p class="mt-0.5 text-xs text-gray-500">개인정보보호법 §29 안전조치 — RRN 포함 서류 접근 기록. 총 {{ $this->logs->total() }}건</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="perPage" class="input-base w-auto">
                <option value="10">10개씩</option>
                <option value="30">30개씩</option>
                <option value="50">50개씩</option>
                <option value="100">100개씩</option>
            </select>
        </div>
    </div>

    <div class="card-tight flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="접근자 · 차량번호"
               class="input-base w-full sm:w-72" />
        <select wire:model.live="typeFilter" class="input-base w-full sm:w-auto">
            <option value="">전체 서류 종류</option>
            @foreach(App\Models\DocumentAccessLog::DOCUMENT_TYPES as $type => $label)
            <option value="{{ $type }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- 테이블 (데스크탑) --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">시각</th>
                    <th class="pb-2 pr-4 font-medium">접근자</th>
                    <th class="pb-2 pr-4 font-medium">차량</th>
                    <th class="pb-2 pr-4 font-medium">서류</th>
                    <th class="pb-2 pr-4 font-medium">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 text-gray-600 text-xs whitespace-nowrap">
                        {{ $log->created_at?->format('Y-m-d H:i:s') }}
                    </td>
                    <td class="py-2 pr-4">
                        <div class="font-medium text-gray-800">{{ $log->user?->name ?? '-' }}</div>
                        <div class="text-xs text-gray-400">{{ $log->user?->email }}</div>
                    </td>
                    <td class="py-2 pr-4 text-gray-700">{{ $log->vehicle?->vehicle_number ?? '-' }}</td>
                    <td class="py-2 pr-4">
                        <span class="badge badge-gray">{{ $log->document_label }}</span>
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-400">{{ $log->ip_address ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="py-12 text-center text-sm text-gray-400">접근 로그가 없습니다.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        <div class="card-tight space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-medium text-gray-800 text-sm">{{ $log->user?->name ?? '-' }}</span>
                <span class="badge badge-gray text-[10px]">{{ $log->document_label }}</span>
            </div>
            <div class="text-xs text-gray-500">{{ $log->vehicle?->vehicle_number ?? '-' }}</div>
            <div class="text-[11px] text-gray-400">
                {{ $log->created_at?->format('Y-m-d H:i') }}
                @if($log->ip_address) · {{ $log->ip_address }} @endif
            </div>
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">접근 로그가 없습니다.</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>

</div>
</div>
