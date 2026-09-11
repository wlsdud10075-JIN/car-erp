<?php

use App\Models\DocumentAccessLog;
use App\Support\SearchTerm;
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

    /**
     * 운영 로그 열람 가드 (jin 2026-07-28) — [관리] 이상.
     * 라우트 'operation-logs' 미들웨어와 이중 방어 (구조상 미들웨어만 믿지 않는다, SKILLS §8 #26).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewOperationLogs(), 403);
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 30;
        }
        $this->resetPage();
    }

    /** 검색 실행 — 버튼·Enter 로만 (jin 2026-09-08). 🚨 `search()` 로 짓지 말 것: 프로퍼티와 겹쳐 버튼이 죽는다(SKILLS §8 #32). */
    public function searchNow(): void
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
            ->when(SearchTerm::of($this->search), function ($q) {
                $term = SearchTerm::like($this->search);
                $q->where(function ($q2) use ($term) {
                    $q2->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term))
                        ->orWhereHas('vehicle', fn ($v) => $v->where('vehicle_number', 'like', $term));
                });
            })
            ->when($this->typeFilter, fn ($q) => $q->where('document_type', $this->typeFilter))
            ->orderByDesc('created_at')
            // 동점 tie-break (jin 2026-09-11) — 정렬키가 같은 행의 순서는 DB 가 안 정해 준다.
            //   페이지네이션에서 같은 행이 두 페이지에 나오거나 통째로 빠질 수 있다(SKILLS §8 #92).
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }
}; ?>

<div wire:poll.30s>
<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800">{{ __('log.doc_title') }}</h1>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.doc_subtitle', ['count' => $this->logs->total()]) }}</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="perPage" class="input-base w-auto">
                <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
                <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
                <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
                <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
            </select>
        </div>
    </div>

    <div class="card-tight flex flex-wrap items-center gap-3">
        {{-- 🔎 검색은 **버튼(또는 Enter)으로만** 돈다 (jin 2026-09-08).
                 타이핑마다 서버 왕복이면 `LIKE '%…%'` 가 글자 수만큼 돈다 — 이 ERP 의
                 다른 검색칸(차량·재고·바이어·정산 등)이 전부 이 형태다. --}}
        <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('log.doc_search') }}"
               class="input-base w-full sm:w-72" />
        <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
        <select wire:model.live="typeFilter" class="input-base w-full sm:w-auto">
            <option value="">{{ __('log.all_doc_types') }}</option>
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
                    <th class="pb-2 pr-4 font-medium">{{ __('log.doc_col.time') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.doc_col.accessor') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.doc_col.vehicle') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.doc_col.document') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.doc_col.ip') }}</th>
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
                <tr><td colspan="5" class="py-12 text-center text-sm text-gray-400">{{ __('log.doc_empty') }}</td></tr>
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
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.doc_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>

</div>
</div>
