<?php

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 예치·가수금 (jin 2026-07-27, 안건4 1단계) — 사이드바 1개 메뉴 안에서 탭 2개.
 *
 *   가수금(advance)    = 대표·관계사가 회사에 넣은 돈. 상호명·담당자·금액.
 *   경매보증금(auction) = 경매장에 예치한 돈. 업체·금액.
 *
 * ⚠️ 회수/반제하면 **행을 삭제**한다 → 목록에 남은 합계 = 현재 잔액(지금 묶여 있는 돈).
 *    반환일 칸을 두고 걸러 보는 것보다 단순하다는 jin 판단. softDelete 라 DB 이력은 남는다.
 *
 * 권한 = canEnterCashBalance(재무·관리·업무관리자·대표) — 통장 마감잔액 입력과 같은 축.
 * 2단계(자금현황 반영)·3단계(월보고)는 별도.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url] public string $tab = 'advance';   // advance | auction

    // 입력 폼 (한 줄 입력 → 추가 → 목록에 쌓임)
    public string $date = '';
    public string $party = '';        // 상호명(가수금) / 업체(경매보증금)
    public string $person = '';       // 담당자 — 가수금만
    public string $amount = '';
    public string $note = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canEnterCashBalance(), 403);
        $this->date = now()->format('Y-m-d');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['advance', 'auction'], true) ? $tab : 'advance';
        $this->resetForm();
        $this->resetValidation();
        unset($this->rows);
    }

    #[Computed]
    public function rows()
    {
        return $this->tab === 'auction'
            ? AuctionDeposit::orderByDesc('deposited_date')->orderByDesc('id')->get()
            : AdvanceReceipt::orderByDesc('received_date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function total(): int
    {
        return (int) $this->rows->sum('amount');
    }

    public function add(): void
    {
        abort_unless(auth()->user()?->canEnterCashBalance(), 403);

        $amount = (float) str_replace(',', '', $this->amount);
        $this->validate([
            'date' => 'required|date',
            'party' => 'required|string|max:100',
            'person' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:255',
        ], [], [
            'date' => __('deposits.f_date'),
            'party' => $this->tab === 'auction' ? __('deposits.f_house') : __('deposits.f_company'),
            'person' => __('deposits.f_person'),
        ]);

        if ($amount <= 0) {
            $this->addError('amount', __('deposits.err_amount'));

            return;
        }

        if ($this->tab === 'auction') {
            AuctionDeposit::create([
                'deposited_date' => $this->date,
                'auction_house' => $this->party,
                'amount' => $amount,
                'note' => $this->note ?: null,
                'created_by' => auth()->id(),
            ]);
        } else {
            AdvanceReceipt::create([
                'received_date' => $this->date,
                'company_name' => $this->party,
                'person_name' => $this->person ?: null,
                'amount' => $amount,
                'note' => $this->note ?: null,
                'created_by' => auth()->id(),
            ]);
        }

        $this->resetForm();
        unset($this->rows);
        session()->flash('success', __('deposits.added'));
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->canEnterCashBalance(), 403);

        if ($this->tab === 'auction') {
            AuctionDeposit::findOrFail($id)->delete();
        } else {
            AdvanceReceipt::findOrFail($id)->delete();
        }

        unset($this->rows);
        session()->flash('success', __('deposits.removed'));
    }

    private function resetForm(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->party = $this->person = $this->amount = $this->note = '';
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4 flex items-center justify-between">
        <h2 class="text-xl font-bold text-gray-800">{{ __('deposits.title') }}</h2>
    </div>

    {{-- 탭 --}}
    <div class="mb-4 flex gap-2">
        <button type="button" wire:click="setTab('advance')"
                class="tab-pill {{ $tab === 'advance' ? 'is-active' : '' }}">{{ __('deposits.tab_advance') }}</button>
        <button type="button" wire:click="setTab('auction')"
                class="tab-pill {{ $tab === 'auction' ? 'is-active' : '' }}">{{ __('deposits.tab_auction') }}</button>
    </div>

    @if (session('success'))
        <div class="mb-3 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ session('success') }}
        </div>
    @endif

    {{-- 합계 요약 --}}
    <div class="card mb-4">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-[11px] uppercase text-gray-500">
                    {{ $tab === 'auction' ? __('deposits.sum_auction') : __('deposits.sum_advance') }}
                </div>
                <div class="mt-1 text-2xl font-bold text-gray-800">₩{{ number_format($this->total) }}</div>
            </div>
            <div class="text-right text-xs text-gray-400">
                {{ __('deposits.count', ['count' => $this->rows->count()]) }}<br>
                {{ $tab === 'auction' ? __('deposits.hint_auction') : __('deposits.hint_advance') }}
            </div>
        </div>
    </div>

    {{-- 입력 줄 --}}
    <div class="card mb-4">
        <div class="section-header">
            <span class="section-dot bg-emerald-500"></span>
            <span class="section-title">{{ __('deposits.new') }}</span>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div>
                <label class="mb-1 block text-[11px] text-gray-500">{{ __('deposits.f_date') }}</label>
                <input type="text" data-date wire:model="date" placeholder="YYYY-MM-DD" class="input-base w-32">
            </div>
            <div class="min-w-40 flex-1">
                <label class="mb-1 block text-[11px] text-gray-500">
                    {{ $tab === 'auction' ? __('deposits.f_house') : __('deposits.f_company') }}
                </label>
                <input type="text" wire:model="party" class="input-base w-full"
                       placeholder="{{ $tab === 'auction' ? __('deposits.ph_house') : __('deposits.ph_company') }}">
            </div>
            @if ($tab === 'advance')
                <div>
                    <label class="mb-1 block text-[11px] text-gray-500">{{ __('deposits.f_person') }}</label>
                    <input type="text" wire:model="person" class="input-base w-28">
                </div>
            @endif
            <div>
                <label class="mb-1 block text-[11px] text-gray-500">{{ __('deposits.f_amount') }}</label>
                <input type="text" data-money wire:model="amount" class="input-base w-36 text-right" placeholder="0">
            </div>
            <div class="min-w-32 flex-1">
                <label class="mb-1 block text-[11px] text-gray-500">{{ __('deposits.f_note') }}</label>
                <input type="text" wire:model="note" class="input-base w-full">
            </div>
            <button type="button" wire:click="add" class="btn-primary">{{ __('deposits.add') }}</button>
        </div>
        @error('date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('party') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('person') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- 목록 (데스크탑) --}}
    <div class="card hidden sm:block">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('deposits.f_date') }}</th>
                    <th class="pb-2 pr-4 font-medium">
                        {{ $tab === 'auction' ? __('deposits.f_house') : __('deposits.f_company') }}
                    </th>
                    @if ($tab === 'advance')
                        <th class="pb-2 pr-4 font-medium">{{ __('deposits.f_person') }}</th>
                    @endif
                    <th class="pb-2 pr-4 text-right font-medium">{{ __('deposits.f_amount') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('deposits.f_note') }}</th>
                    <th class="pb-2 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->rows as $row)
                    <tr wire:key="dep-{{ $tab }}-{{ $row->id }}">
                        <td class="py-3 pr-4 text-gray-500">
                            {{ ($tab === 'auction' ? $row->deposited_date : $row->received_date)?->format('Y-m-d') }}
                        </td>
                        <td class="py-3 pr-4 font-medium text-gray-800">
                            {{ $tab === 'auction' ? $row->auction_house : $row->company_name }}
                        </td>
                        @if ($tab === 'advance')
                            <td class="py-3 pr-4 text-gray-500">{{ $row->person_name ?: '-' }}</td>
                        @endif
                        <td class="py-3 pr-4 text-right text-gray-800">₩{{ number_format((int) $row->amount) }}</td>
                        <td class="py-3 pr-4 text-xs text-gray-500">{{ $row->note ?: '-' }}</td>
                        <td class="py-3 text-right">
                            <button type="button" wire:click="remove({{ $row->id }})"
                                    wire:confirm="{{ $tab === 'auction' ? __('deposits.confirm_auction') : __('deposits.confirm_advance') }}"
                                    class="text-xs text-red-600 hover:underline">{{ __('deposits.remove') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-12 text-center text-sm text-gray-400">{{ __('deposits.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 목록 (모바일 카드) --}}
    <div class="block space-y-2 sm:hidden">
        @forelse($this->rows as $row)
            <div class="card-sm" wire:key="dep-m-{{ $tab }}-{{ $row->id }}">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-medium text-gray-800">
                            {{ $tab === 'auction' ? $row->auction_house : $row->company_name }}
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500">
                            {{ ($tab === 'auction' ? $row->deposited_date : $row->received_date)?->format('Y-m-d') }}
                            @if ($tab === 'advance' && $row->person_name) · {{ $row->person_name }} @endif
                        </div>
                        @if ($row->note)
                            <div class="mt-1 text-xs text-gray-400">{{ $row->note }}</div>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="font-bold text-gray-800">₩{{ number_format((int) $row->amount) }}</div>
                        <button type="button" wire:click="remove({{ $row->id }})"
                                wire:confirm="{{ $tab === 'auction' ? __('deposits.confirm_auction') : __('deposits.confirm_advance') }}"
                                class="mt-1 text-xs text-red-600">{{ __('deposits.remove') }}</button>
                    </div>
                </div>
            </div>
        @empty
            <div class="card-sm text-center text-sm text-gray-400">{{ __('deposits.empty') }}</div>
        @endforelse
    </div>
</div>
