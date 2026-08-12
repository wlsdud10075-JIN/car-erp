<?php

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\AuditLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 예치·가수금 (jin 2026-07-27, 안건4 1단계) — 사이드바 1개 메뉴 안에서 탭 2개.
 *
 *   가수금(advance)    = 대표·관계사가 회사에 넣은 돈. 상호명·금액·**성격**.
 *   경매보증금(auction) = 경매장에 예치한 돈. 업체·금액.
 *
 * 🔁 **가수금 상환 = [상환완료] 버튼** (jin 2026-08-12). 누른 날로 `repaid_at` 을 찍고 목록에 남긴다.
 *    종전엔 반제 = 행 삭제였는데, 숫자는 맞아도 **갚은 이력이 화면에서 사라져** 누적 확인이 안 됐다.
 *    ⚠️ 집계(`liabilityKrw`·`equityKrw`·`totalKrw`)는 **미상환만** 센다 — 모델 `outstanding` 스코프가 단일 출처.
 *    ⚠️ 「갚아야 할 돈」만 대상(jin 확정). 대표 자산 회수는 종전대로 삭제로 정리한다.
 *    ⚠️ 이제 [삭제]는 **"잘못 입력한 행 지우기" 전용**이다. 갚은 건 삭제가 아니라 상환완료로.
 *    ⚠️ 경매보증금은 종전 그대로 삭제로 정리한다(예치금 회수는 이력 누적 요구가 없었다).
 *
 * 💰 **성격(nature)** — 청산가치에서 뺄 돈인지 가른다 (jin 2026-07-31).
 *    liability(갚아야 할 돈, 예: 김진숙차입) → 차감 / equity(대표 본인 돈) → 차감 안 함.
 *    기본값 liability 라 분류하기 전에는 종전과 같이 전액 차감된다.
 *    ⚠️ 성격을 바꿔도 **이미 찍힌 스냅샷은 안 변한다** — 다음 통장잔액 입력부터 반영된다.
 *
 * 🗑️ 담당자(person_name) 칸은 2026-07-31 화면에서 제거했다(jin). 컬럼은 기존 데이터 보존을 위해 남겨둠.
 *
 * 권한 = canAccessDeposits(대표·업무관리자 = 「업무관리자 이상」, jin 2026-08-05). [관리]·재무는 못 본다.
 *   구 canEnterCashBalance(재무·관리 포함)에서 분리 — 통장 마감잔액 입력은 그쪽 게이트로 종전대로 열려 있다.
 * 2단계(자금현황 반영)·3단계(월보고)는 별도.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    #[Url] public string $tab = 'advance';   // advance | auction

    // 입력 폼 (한 줄 입력 → 추가 → 목록에 쌓임)
    public string $date = '';
    public string $party = '';        // 상호명(가수금) / 업체(경매보증금)
    public string $nature = AdvanceReceipt::NATURE_LIABILITY;   // 성격 — 가수금만
    public string $amount = '';
    public string $note = '';

    /**
     * 상환완료 행까지 펼쳐 볼지 (jin 2026-08-12). 기본은 **미상환만** —
     * 갚은 행이 계속 쌓이면 정작 "지금 남은 게 얼마" 인지가 안 보인다.
     * 상단 합계는 이 토글과 무관하게 **항상 미상환 기준**이다(회계 숫자라 화면 설정으로 흔들리면 안 된다).
     */
    public bool $showRepaid = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);
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
        if ($this->tab === 'auction') {
            return AuctionDeposit::orderByDesc('deposited_date')->orderByDesc('id')->get();
        }

        return AdvanceReceipt::query()
            ->with('repaidBy:id,name')   // 「누가 갚음 처리했나」를 목록에서 바로 보여준다 (N+1 회피)
            ->unless($this->showRepaid, fn ($q) => $q->outstanding())
            ->orderByDesc('received_date')->orderByDesc('id')->get();
    }

    /** 상환완료 건수 — 0 이면 토글을 아예 안 보여준다(쓸 일 없는 버튼을 늘리지 않는다). */
    #[Computed]
    public function repaidCount(): int
    {
        return $this->tab === 'advance' ? AdvanceReceipt::repaid()->count() : 0;
    }

    #[Computed]
    public function total(): int
    {
        // ⚠️ 목록이 아니라 **미상환 전체**를 센다 — showRepaid 를 켜면 합계가 부풀던 길을 막는다.
        return $this->tab === 'auction'
            ? (int) $this->rows->sum('amount')
            : AdvanceReceipt::totalKrw();
    }

    /**
     * 성격별 소계 — 갚아야 할 돈만 청산가치에서 빠지므로 나눠 보여준다.
     * ⚠️ 화면 목록이 아니라 **모델 집계**를 쓴다. 목록은 토글로 내용이 바뀌지만
     *    이 숫자는 자금현황에 들어가는 값과 같아야 한다(모델이 단일 출처).
     */
    #[Computed]
    public function natureTotals(): array
    {
        if ($this->tab !== 'advance') {
            return [];
        }

        return [
            AdvanceReceipt::NATURE_LIABILITY => AdvanceReceipt::liabilityKrw(),
            AdvanceReceipt::NATURE_EQUITY => AdvanceReceipt::equityKrw(),
            'repaid' => AdvanceReceipt::repaidKrw(),
        ];
    }

    /** 기존 행의 성격 변경 — 분류를 나중에 정하므로 목록에서 바로 바꿀 수 있어야 한다. */
    public function setNature(int $id, string $nature): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);
        abort_unless(array_key_exists($nature, AdvanceReceipt::NATURES), 422);

        $row = AdvanceReceipt::findOrFail($id);
        // 상환된 행의 성격을 바꾸면 「갚은돈」과 「대표 자산」 사이를 오가며 과거 숫자가 흔들린다.
        abort_if($row->repaid_at !== null, 422);

        $old = $row->nature;
        $row->update(['nature' => $nature]);
        // 성격 하나로 청산가치와 원금이 동시에 억 단위로 움직인다 — 무엇을 언제 바꿨는지 남긴다.
        if ($old !== $nature) {
            AuditLog::recordChange($row, 'nature', $old, $nature);
        }
        $this->refreshTotals();
        session()->flash('success', __('deposits.nature_updated'));
    }

    /**
     * 🔁 상환완료 — 버튼을 누른 날로 찍는다 (jin 2026-08-12).
     *
     * ⚠️ **「갚아야 할 돈」만** 대상이다(jin 확정). 대표 자산 회수는 종전대로 삭제로 정리한다.
     * ⚠️ 실제로 송금한 **뒤에** 누르는 버튼이다 — 부채는 즉시 사라지는데 통장잔액은 사람이
     *    입력할 때까지 그대로라, 그 사이엔 청산가치가 실제보다 높게 보인다(화면에 안내 표시).
     *
     * 🔍 **감사 기록 필수** (jin 2026-08-12) — 억 단위 부채가 목록에서 사라지는 조작인데 종전엔
     *    아무 흔적도 안 남았다. `repaid_at` 변경을 감사로그에 남겨 "누가 언제 갚음 처리했나"를 남긴다.
     * 👀 그리고 **누른 행이 눈앞에서 사라지지 않게** 상환분 보기를 자동으로 켠다 — 기본이 미상환만
     *    보기라 누르는 즉시 행이 증발해 **날짜가 찍혔는지 확인할 방법이 없었다**(jin 실제 제보).
     */
    public function markRepaid(int $id): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);

        $row = AdvanceReceipt::findOrFail($id);
        abort_unless($row->canRepay(), 422);

        $date = now()->toDateString();
        $row->update(['repaid_at' => $date, 'repaid_by' => auth()->id()]);
        AuditLog::recordChange($row, 'repaid_at', null, $date);

        $this->showRepaid = true;   // 방금 처리한 행을 그대로 보여준다(날짜 확인용)
        $this->refreshTotals();
        session()->flash('success', __('deposits.repaid_done', [
            'amount' => number_format((int) $row->amount),
            'date' => $date,
        ]));
    }

    /** 무르기 — 오클릭 되돌림. 없으면 잘못 누른 순간 복구할 방법이 없다. */
    public function undoRepaid(int $id): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);

        $row = AdvanceReceipt::findOrFail($id);
        $old = $row->repaid_at?->toDateString();
        $row->update(['repaid_at' => null, 'repaid_by' => null]);
        AuditLog::recordChange($row, 'repaid_at', $old, null);

        $this->refreshTotals();
        session()->flash('success', __('deposits.repaid_undone'));
    }

    public function toggleShowRepaid(): void
    {
        $this->showRepaid = ! $this->showRepaid;
        unset($this->rows);
    }

    private function refreshTotals(): void
    {
        unset($this->rows, $this->total, $this->natureTotals, $this->repaidCount);
    }

    public function add(): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);

        $amount = (float) str_replace(',', '', $this->amount);
        $this->validate([
            'date' => 'required|date',
            'party' => 'required|string|max:100',
            'nature' => 'required|in:'.implode(',', array_keys(AdvanceReceipt::NATURES)),
            'note' => 'nullable|string|max:255',
        ], [], [
            'date' => __('deposits.f_date'),
            'party' => $this->tab === 'auction' ? __('deposits.f_house') : __('deposits.f_company'),
            'nature' => __('deposits.f_nature'),
        ]);

        if ($amount <= 0) {
            $this->addError('amount', __('deposits.err_amount'));

            return;
        }

        // 두 원장 모두 청산가치에 억 단위로 들어간다 — 생성·삭제를 감사로그에 남긴다 (jin 2026-08-12).
        if ($this->tab === 'auction') {
            $row = AuctionDeposit::create([
                'deposited_date' => $this->date,
                'auction_house' => $this->party,
                'amount' => $amount,
                'note' => $this->note ?: null,
                'created_by' => auth()->id(),
            ]);
        } else {
            $row = AdvanceReceipt::create([
                'received_date' => $this->date,
                'company_name' => $this->party,
                'amount' => $amount,
                'nature' => $this->nature,
                'note' => $this->note ?: null,
                'created_by' => auth()->id(),
            ]);
        }
        AuditLog::recordEvent($row, 'created');
        // 금액·상호는 행이 지워지면 못 찾으므로 로그 자체에 박아둔다(soft delete 라도 조회는 번거롭다).
        AuditLog::recordChange($row, 'amount', null, (int) $amount);

        $this->resetForm();
        $this->refreshTotals();
        session()->flash('success', __('deposits.added'));
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->user()?->canAccessDeposits(), 403);

        $row = $this->tab === 'auction'
            ? AuctionDeposit::findOrFail($id)
            : AdvanceReceipt::findOrFail($id);

        // 지우기 **전에** 금액을 로그에 박는다 — 지운 뒤엔 감사 화면에서 얼마였는지 알 길이 없다.
        AuditLog::recordChange($row, 'amount', (int) $row->amount, null);
        AuditLog::recordEvent($row, 'deleted');
        $row->delete();

        $this->refreshTotals();
        session()->flash('success', __('deposits.removed'));
    }

    private function resetForm(): void
    {
        $this->date = now()->format('Y-m-d');
        $this->party = $this->amount = $this->note = '';
        $this->nature = AdvanceReceipt::NATURE_LIABILITY;
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

        {{-- 성격별 소계 — 갚아야 할 돈만 청산가치에서 빠지므로 나눠 보여준다(jin 2026-07-31). --}}
        @if ($tab === 'advance')
            <div class="mt-3 flex flex-wrap gap-4 border-t border-gray-100 pt-3 text-sm">
                <div>
                    <span class="badge badge-red">{{ \App\Models\AdvanceReceipt::NATURES['liability'] }}</span>
                    <span class="ml-1 font-semibold text-gray-800">₩{{ number_format($this->natureTotals['liability'] ?? 0) }}</span>
                    <span class="ml-1 text-[11px] text-gray-400">{{ __('deposits.nature_liability_hint') }}</span>
                </div>
                <div>
                    <span class="badge badge-gray">{{ \App\Models\AdvanceReceipt::NATURES['equity'] }}</span>
                    <span class="ml-1 font-semibold text-gray-800">₩{{ number_format($this->natureTotals['equity'] ?? 0) }}</span>
                    <span class="ml-1 text-[11px] text-gray-400">{{ __('deposits.nature_equity_hint') }}</span>
                </div>
                {{-- 갚은돈 — 표시 전용 누계(청산가치·원금 어디에도 안 들어간다). 0 이면 감춘다. --}}
                @if (($this->natureTotals['repaid'] ?? 0) > 0)
                    <div>
                        <span class="badge badge-green">{{ __('deposits.repaid_label') }}</span>
                        <span class="ml-1 font-semibold text-emerald-700">₩{{ number_format($this->natureTotals['repaid']) }}</span>
                        <span class="ml-1 text-[11px] text-gray-400">{{ __('deposits.repaid_hint') }}</span>
                    </div>
                @endif
            </div>
        @endif
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
                    <label class="mb-1 block text-[11px] text-gray-500">{{ __('deposits.f_nature') }}</label>
                    <select wire:model="nature" class="input-base w-36">
                        @foreach (\App\Models\AdvanceReceipt::NATURES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
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
        @error('nature') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('amount') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('note') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- 상환완료 보기 토글 — 갚은 게 없으면 안 보여준다(쓸 일 없는 버튼을 늘리지 않는다). --}}
    @if ($tab === 'advance' && $this->repaidCount > 0)
        <div class="mb-2 flex items-center gap-2 text-xs">
            <button type="button" wire:click="toggleShowRepaid"
                    class="{{ $showRepaid ? 'tab-pill is-active' : 'tab-pill' }}">
                {{ $showRepaid ? __('deposits.repaid_hide') : __('deposits.repaid_show', ['count' => $this->repaidCount]) }}
            </button>
            <span class="text-gray-400">{{ __('deposits.repaid_total_note') }}</span>
        </div>
    @endif

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
                        <th class="pb-2 pr-4 font-medium">{{ __('deposits.f_nature') }}</th>
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
                            {{-- 분류를 나중에 정하므로 목록에서 바로 바꿀 수 있게 둔다(jin 2026-07-31).
                                 상환된 행은 잠근다 — 바꾸면 「갚은돈」과 「대표 자산」 사이를 오가며 과거 숫자가 흔들린다. --}}
                            <td class="py-3 pr-4">
                                @if ($row->repaid_at)
                                    <span class="badge badge-gray">{{ \App\Models\AdvanceReceipt::NATURES[$row->nature] ?? $row->nature }}</span>
                                @else
                                    <select class="input-base w-32 py-1 text-xs"
                                            wire:change="setNature({{ $row->id }}, $event.target.value)">
                                        @foreach (\App\Models\AdvanceReceipt::NATURES as $key => $label)
                                            <option value="{{ $key }}" @selected($row->nature === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                        @endif
                        <td class="py-3 pr-4 text-right {{ $row->repaid_at ? 'text-gray-400 line-through' : 'text-gray-800' }}">
                            ₩{{ number_format((int) $row->amount) }}
                        </td>
                        <td class="py-3 pr-4 text-xs text-gray-500">{{ $row->note ?: '-' }}</td>
                        <td class="py-3 text-right">
                            @if ($tab === 'advance' && $row->repaid_at)
                                <span class="badge badge-green">{{ __('deposits.repaid_on', ['date' => $row->repaid_at->format('Y-m-d')]) }}</span>
                                @if ($row->repaidBy)
                                    <span class="ml-1 text-xs text-gray-400">{{ $row->repaidBy->name }}</span>
                                @endif
                                <button type="button" wire:click="undoRepaid({{ $row->id }})"
                                        wire:confirm="{{ __('deposits.confirm_undo') }}"
                                        class="ml-2 text-xs text-gray-500 hover:underline">{{ __('deposits.repaid_undo') }}</button>
                            @else
                                @if ($tab === 'advance' && $row->canRepay())
                                    <button type="button" wire:click="markRepaid({{ $row->id }})"
                                            wire:confirm="{{ __('deposits.confirm_repaid') }}"
                                            class="mr-2 rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                        {{ __('deposits.repaid_btn') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="remove({{ $row->id }})"
                                        wire:confirm="{{ $tab === 'auction' ? __('deposits.confirm_auction') : __('deposits.confirm_advance') }}"
                                        class="text-xs text-red-600 hover:underline">{{ __('deposits.remove') }}</button>
                            @endif
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
                            @if ($tab === 'advance') · {{ \App\Models\AdvanceReceipt::NATURES[$row->nature] ?? $row->nature }} @endif
                        </div>
                        @if ($tab === 'advance' && $row->repaid_at)
                            <div class="mt-1">
                                <span class="badge badge-green">{{ __('deposits.repaid_on', ['date' => $row->repaid_at->format('Y-m-d')]) }}</span>
                                @if ($row->repaidBy)
                                    <span class="ml-1 text-xs text-gray-400">{{ $row->repaidBy->name }}</span>
                                @endif
                            </div>
                        @endif
                        @if ($row->note)
                            <div class="mt-1 text-xs text-gray-400">{{ $row->note }}</div>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="font-bold {{ $row->repaid_at ? 'text-gray-400 line-through' : 'text-gray-800' }}">
                            ₩{{ number_format((int) $row->amount) }}
                        </div>
                        @if ($tab === 'advance' && $row->repaid_at)
                            <button type="button" wire:click="undoRepaid({{ $row->id }})"
                                    wire:confirm="{{ __('deposits.confirm_undo') }}"
                                    class="mt-1 text-xs text-gray-500">{{ __('deposits.repaid_undo') }}</button>
                        @else
                            @if ($tab === 'advance' && $row->canRepay())
                                <button type="button" wire:click="markRepaid({{ $row->id }})"
                                        wire:confirm="{{ __('deposits.confirm_repaid') }}"
                                        class="mt-1 block w-full rounded border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">
                                    {{ __('deposits.repaid_btn') }}
                                </button>
                            @endif
                            <button type="button" wire:click="remove({{ $row->id }})"
                                    wire:confirm="{{ $tab === 'auction' ? __('deposits.confirm_auction') : __('deposits.confirm_advance') }}"
                                    class="mt-1 text-xs text-red-600">{{ __('deposits.remove') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card-sm text-center text-sm text-gray-400">{{ __('deposits.empty') }}</div>
        @endforelse
    </div>
</div>
