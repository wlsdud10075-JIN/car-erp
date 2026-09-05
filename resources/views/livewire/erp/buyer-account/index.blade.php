<?php

use App\Models\Buyer;
use App\Models\BuyerCashReceipt;
use App\Models\Setting;
use App\Services\BuyerAccountService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * 바이어 정산현황 (jin 2026-09-05) — 기획 docs/design/buyer-cash-ledger.md
 *
 * 🚫 **줄여서 「정산」이라고 부르지 말 것.** 이 ERP 의 「정산」은 **담당자 지급**이다
 *    (정산 처리·정산액·월배치·2차 정산 마감). 이 화면은 바이어와의 대금이다.
 *
 * 답하는 질문 두 개:
 *   ① 이 바이어가 보낸 돈이 아직 얼마 남았나 (현금)
 *   ② 아직 낼 돈이 얼마고 어느 묶음에 걸려 있나 (미수 · 컨테이너/신고번호/BL/선박)
 *
 * 🔑 숫자는 전부 `BuyerAccountService` 에서 나온다 — 엑셀도 같은 것을 쓴다.
 *    화면과 엑셀이 조건을 각자 적으면 「화면엔 3대인데 엑셀엔 300대」가 된다(SKILLS §9).
 */
new #[Layout('components.layouts.app')] class extends Component {
    #[Url] public string $buyerId = '';
    #[Url(as: 'axis')] public string $groupAxis = 'container';

    // 검색 — 차량관리와 **같은 조건**(Vehicle::scopeSearchAny). 칸도 거기와 같게 둘로 나눈다
    //   (통합 / 차대번호 — 숫자·코드가 서로 오검색되는 걸 막으려고 원래 화면이 그렇게 갈라놨다).
    // 🚨 프로퍼티와 같은 이름의 메서드를 만들지 말 것 — 버튼이 요청조차 안 보내고 죽는다(§8 #32).
    #[Url] public string $search = '';
    #[Url(as: 'vin')] public string $vinSearch = '';

    // 미수 차량 정렬 — 금액은 **그 차량 통화**로 정렬한다(원화로 줄 세우면 보이는 숫자와 어긋난다).
    #[Url] public string $sort = 'unpaid';
    #[Url] public string $dir = 'desc';

    /** 현금 사용 내역 — 입금은 계속 쌓이기만 하므로 **더 보기**로 끊어 읽는다. */
    public int $usageShown = self::USAGE_PAGE;

    public const USAGE_PAGE = 10;

    public function mount(): void
    {
        // 토글이 꺼진 회사엔 메뉴도 안 뜨지만, 주소를 직접 쳐도 막아야 한다(SKILLS §8 #26).
        abort_unless(Setting::buyerCashEnabled(), 404);
    }

    /** 축 전환 — 프로퍼티와 이름이 겹치면 버튼이 조용히 죽는다(SKILLS §8 #32). */
    public function selectAxis(string $axis): void
    {
        $this->groupAxis = array_key_exists($axis, BuyerAccountService::AXES) ? $axis : 'container';
    }

    /** 정렬 전환 — 같은 축을 다시 누르면 오름/내림이 뒤집힌다. */
    public function sortBy(string $sort): void
    {
        if (! array_key_exists($sort, BuyerAccountService::SORTS)) {
            return;
        }
        $this->dir = ($this->sort === $sort && $this->dir === 'desc') ? 'asc' : 'desc';
        $this->sort = $sort;
        unset($this->vehicles, $this->groups, $this->unpaidByCurrency);
    }

    /** 현금 사용 내역 더 보기 — 한 번에 전부 그리면 입금이 쌓일수록 화면이 끝없이 길어진다. */
    public function showMoreUsage(): void
    {
        $this->usageShown += self::USAGE_PAGE;
        unset($this->cashUsage);
    }

    /** 검색 실행 — 이름이 `search` 프로퍼티와 겹치면 안 되므로 `searchNow`(§8 #32 의 그 규칙). */
    public function searchNow(): void
    {
        unset($this->vehicles, $this->groups, $this->unpaidByCurrency);
    }

    /** 바이어를 바꾸면 사용 내역 페이저도 처음으로 — 안 그러면 새 바이어에서 엉뚱하게 많이 펼쳐진다. */
    public function updatedBuyerId(): void
    {
        $this->usageShown = self::USAGE_PAGE;
        unset($this->cashUsage, $this->vehicles, $this->groups, $this->unpaidByCurrency, $this->cash);
    }

    public function resetSearch(): void
    {
        $this->search = $this->vinSearch = '';
        $this->searchNow();
    }

    /** 미수가 있거나 현금이 남아 있는 바이어만 — 전체 목록은 고를 이유가 없다. */
    #[Computed]
    public function buyerOptions()
    {
        return Buyer::query()
            ->where(fn ($q) => $q
                ->whereHas('vehicles', fn ($v) => $v->where('sale_unpaid_amount_krw_cache', '>', 0))
                ->orWhereHas('cashReceipts'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function buyer(): ?Buyer
    {
        return $this->buyerId !== '' ? Buyer::find((int) $this->buyerId) : null;
    }

    #[Computed]
    public function cash(): array
    {
        return $this->buyer ? app(BuyerAccountService::class)->cashByCurrency($this->buyer) : [];
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Vehicle> */
    #[Computed]
    public function vehicles()
    {
        return $this->buyer
            ? app(BuyerAccountService::class)->unpaidVehicles(
                $this->buyer, $this->search, $this->vinSearch, $this->sort, $this->dir)
            : collect();
    }

    #[Computed]
    public function groups(): array
    {
        return $this->buyer
            ? app(BuyerAccountService::class)->groupsBy($this->groupAxis, $this->vehicles)
            : [];
    }

    /**
     * 현금 사용 내역 — 「이 입금이 어느 차에 얼마」. 이 기능의 원래 요구가 이것이다.
     * 🚨 검색으로 거르지 않는다 — 현금 원장이라 일부만 보이면 「남은 현금」과 안 맞는 표가 된다.
     */
    #[Computed]
    public function cashUsage()
    {
        if (! $this->buyer) {
            return collect();
        }

        // 🚨 **입금 건수 상한을 두고 읽는다.** 잔금 확정 1건당 배분 1행이라 이 원장은 줄지 않는다
        //    (미수는 받으면 사라지지만 여기는 영원히 쌓인다). 전부 그리면 몇 년 뒤 이 화면만
        //    느려진다 — ssancarerp 에서 정산처리·관리자 대시보드가 그렇게 됐던 그 형태.
        return app(BuyerAccountService::class)->cashUsage($this->buyer, $this->usageShown + 1);
    }

    /** 더 볼 게 남았나 — 상한보다 1건 더 읽어서 판정한다(전체 COUNT 를 따로 세지 않게). */
    #[Computed]
    public function usageHasMore(): bool
    {
        return $this->cashUsage->count() > $this->usageShown;
    }

    /** 통화별 미수 합 — 통화가 섞이면 더하면 안 된다. */
    #[Computed]
    public function unpaidByCurrency(): array
    {
        $out = [];
        foreach ($this->vehicles as $v) {
            $out[$v->currency] = round(($out[$v->currency] ?? 0) + $v->sale_unpaid_amount, 2);
        }
        ksort($out);

        return $out;
    }

    #[Computed]
    public function exportUrl(): string
    {
        // 🚨 화면 필터를 그대로 넘긴다 — 안 넘기면 「화면엔 3대인데 엑셀엔 300대」가 된다(SKILLS §9).
        return route('erp.buyer-account.export', [
            'buyer' => $this->buyerId,
            'axis' => $this->groupAxis,
            'q' => $this->search,
            'vin' => $this->vinSearch,
        ]);
    }
}; ?>

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

    {{-- 헤더 --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('buyer_account.title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('buyer_account.subtitle') }}</p>
        </div>
        @if($this->buyer)
        <a href="{{ $this->exportUrl }}" class="btn-primary px-3 py-1.5 text-sm">{{ __('buyer_account.export') }}</a>
        @endif
    </div>

    {{-- 바이어 · 통합검색 · 차대번호 · 조회 — **네 개가 한 줄에 나란히** (jin 2026-09-05).
         🚫 하나로 합치지 말 것 — 각자 다른 것을 고르고 찾는 칸이다. 한 상자에 묶지도 않는다.
         넓이는 충분하다: 224 + 208 + 160 + 조회 + 초기화 + 여백 ≈ 760px (1280 폭에서도 안 밀린다). --}}
    <div class="card">
        <div data-row="ba-filters" class="flex flex-wrap items-end gap-2">
            <div class="w-56">
                <label class="label-base">{{ __('buyer_account.pick_buyer') }}</label>
                {{-- 🚫 그냥 select 를 쓰지 말 것 — 바이어가 수백이라 스크롤로 못 찾는다.
                     프로젝트 표준 = 타이핑으로 걸러지는 콤보박스(x-erp.combobox).
                     wire:key 를 선택값에 묶어야 서버에서 값이 바뀔 때 재init 된다. --}}
                <x-erp.combobox model="buyerId"
                                :options="$this->buyerOptions"
                                :selected="$buyerId"
                                :placeholder="__('buyer_account.pick_placeholder')"
                                class="w-full"
                                wire:key="ba-buyer-{{ $buyerId }}" />
            </div>

            {{-- 검색은 차량관리와 **같은 조건**(Vehicle::scopeSearchAny)이고 문구도 같은 것을 쓴다.
                 placeholder 는 대표만, 전체 목록은 호버(title)로 — 칸을 넓히면 줄이 바뀐다. --}}
            <div class="w-52">
                <label class="label-base">{{ __('vehicle.search_btn') }}</label>
                <input wire:model="search" wire:keydown.enter="searchNow" type="text"
                       placeholder="{{ __('vehicle.search_placeholder') }}"
                       title="{{ __('vehicle.search_title') }}"
                       class="input-base w-full" />
            </div>

            {{-- 차대번호는 통합검색 **바로 옆**. 숫자·코드가 서로 오검색되는 걸 막으려고 칸을 나눈다. --}}
            <div class="w-40">
                <label class="label-base">{{ __('buyer_account.col_vin') }}</label>
                <input wire:model="vinSearch" wire:keydown.enter="searchNow" type="text"
                       placeholder="{{ __('vehicle.vin_ph') }}" title="{{ __('vehicle.vin_ph') }}"
                       class="input-base w-full" />
            </div>

            <button wire:click="searchNow" class="btn-primary px-4 py-1.5 text-sm">{{ __('vehicle.search_btn') }}</button>
            @if($search !== '' || $vinSearch !== '')
            <button type="button" wire:click="resetSearch"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                {{ __('vehicle.reset_btn') }}
            </button>
            @endif
        </div>

        @if($this->buyerOptions->isEmpty())
        <p class="mt-2 text-xs text-gray-400">{{ __('buyer_account.no_buyers') }}</p>
        @endif
    </div>

    @if(! $this->buyer)
    <div class="card text-center text-sm text-gray-400">{{ __('buyer_account.pick_first') }}</div>
    @else

    {{-- 통화별 현금 · 미수 --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="card">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer_account.cash_title') }}</h3>
            @forelse($this->cash as $cur => $c)
            <div class="mb-2 rounded-lg border border-gray-200 bg-gray-50 p-3 last:mb-0">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm font-semibold text-gray-700">{{ $cur }}</span>
                    <span class="font-mono text-base font-bold {{ $c['remaining'] > 0.005 ? 'text-emerald-700' : 'text-gray-400' }}">
                        {{ number_format($c['remaining'], 2) }}
                    </span>
                </div>
                <div class="mt-1 flex flex-wrap gap-x-3 text-[11px] text-gray-500">
                    <span>{{ __('buyer_account.received') }} <span class="font-mono">{{ number_format($c['received'], 2) }}</span></span>
                    <span>·</span>
                    <span>{{ __('buyer_account.allocated') }} <span class="font-mono">{{ number_format($c['allocated'], 2) }}</span></span>
                    <span>·</span>
                    <span class="font-medium text-gray-700">{{ __('buyer_account.remaining') }}</span>
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-400">{{ __('buyer_account.no_cash') }}</p>
            @endforelse
        </div>

        <div class="card">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer_account.unpaid_title') }}</h3>
            @forelse($this->unpaidByCurrency as $cur => $sum)
            <div class="mb-2 flex items-center justify-between rounded-lg border border-red-100 bg-red-50 p-3 last:mb-0">
                <span class="font-mono text-sm font-semibold text-gray-700">{{ $cur }}</span>
                <span class="font-mono text-base font-bold text-red-700">{{ number_format($sum, 2) }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-400">{{ __('buyer_account.no_unpaid') }}</p>
            @endforelse
            <p class="mt-2 text-[11px] text-gray-400">{{ __('buyer_account.unpaid_note', ['count' => $this->vehicles->count()]) }}</p>
        </div>
    </div>

    {{-- 현금 사용 내역 — 「이 10,000 이 어디로 갔나」. 이 기능의 원래 요구가 이것이다.
         ⚠️ 여기 나오는 차량은 아래 「미수 차량」 표에 없을 수 있다 — 현금으로 완납된 차가 그렇다.
            그래서 이 표가 따로 필요하다.
         🚨 검색으로 거르지 않는다(현금 원장이라 일부만 보이면 남은 현금과 안 맞는다). --}}
    <div class="card">
        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer_account.usage_title') }}</h3>
        <p class="mb-3 text-[11px] text-gray-400">{{ __('buyer_account.usage_note') }}</p>

        @forelse($this->cashUsage->take($usageShown) as $r)
        <div class="mb-3 rounded-lg border border-gray-200 last:mb-0">
            {{-- 입금 한 건 --}}
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-3 py-2">
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="text-xs font-semibold text-gray-700">{{ $r->received_date->format('Y-m-d') }}</span>
                    <span class="font-mono text-sm font-bold text-gray-800">{{ number_format((float) $r->amount, 2) }}</span>
                    <span class="text-[10px] text-gray-400">{{ $r->currency }}</span>
                    @if($r->note)
                    <span class="max-w-[220px] truncate text-[11px] text-gray-400" title="{{ $r->note }}">{{ $r->note }}</span>
                    @endif
                </div>
                <span class="text-xs {{ $r->remaining_amount > 0.005 ? 'text-emerald-700' : 'text-gray-400' }}">
                    {{ __('buyer_account.remaining') }}
                    <span class="font-mono font-semibold">{{ number_format($r->remaining_amount, 2) }}</span>
                </span>
            </div>

            {{-- 그 입금이 간 곳 — 제목 줄(th)이 있어야 무슨 값인지 알 수 있다(jin 2026-09-05). --}}
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-[10px] uppercase tracking-wider text-gray-400">
                        <th class="py-1 pl-3 pr-3 font-medium">{{ __('buyer_account.col_vehicle') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('buyer_account.col_vin') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ __('buyer_account.col_used_at') }}</th>
                        <th class="py-1 pr-3 text-right font-medium">{{ __('buyer_account.col_used_amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($r->allocations as $a)
                    <tr>
                        <td class="py-1.5 pl-3 pr-3 font-mono whitespace-nowrap text-gray-700">{{ $a->vehicle?->vehicle_number ?? '-' }}</td>
                        <td class="py-1.5 pr-3 max-w-[150px] truncate font-mono text-gray-400" title="{{ $a->vehicle?->nice_reg_vin }}">{{ $a->vehicle?->nice_reg_vin }}</td>
                        <td class="py-1.5 pr-3 whitespace-nowrap text-gray-400">{{ $a->finalPayment?->payment_date?->format('Y-m-d') }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono font-semibold text-gray-700 whitespace-nowrap">
                            {{ number_format((float) $a->amount, 2) }} <span class="text-[10px] font-normal text-gray-400">{{ $r->currency }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-2 pl-3 text-gray-300">{{ __('buyer_account.not_used_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @empty
        <p class="text-xs text-gray-400">{{ __('buyer_account.no_cash') }}</p>
        @endforelse

        {{-- 입금은 계속 쌓이기만 한다 — 한 번에 다 그리면 몇 년 뒤 이 화면만 느려진다. --}}
        @if($this->usageHasMore)
        <button type="button" wire:click="showMoreUsage"
                class="mt-1 w-full rounded-lg border border-dashed border-gray-300 py-2 text-xs text-gray-500 hover:border-primary hover:text-primary-text">
            {{ __('buyer_account.usage_more', ['count' => self::USAGE_PAGE]) }}
        </button>
        @endif
    </div>

    {{-- 묶음별 잔여 --}}
    <div class="card">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer_account.groups_title') }}</h3>
            @foreach(array_keys(\App\Services\BuyerAccountService::AXES) as $axis)
            <button type="button" wire:click="selectAxis('{{ $axis }}')"
                    class="tab-pill {{ $groupAxis === $axis ? 'is-active' : '' }}">{{ __('buyer_account.axis.'.$axis) }}</button>
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b text-left text-gray-400">
                        <th class="pb-1.5 pr-3">{{ __('buyer_account.axis.'.$groupAxis) }}</th>
                        <th class="pb-1.5 pr-3 text-right">{{ __('buyer_account.col_count') }}</th>
                        <th class="pb-1.5 pr-3 text-right">{{ __('buyer_account.col_unpaid') }}</th>
                        <th class="pb-1.5">{{ __('buyer_account.col_vehicles') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->groups as $g)
                    <tr>
                        <td class="py-1.5 pr-3 font-mono {{ $g['key'] === '' ? 'text-gray-400' : 'text-gray-700' }}">
                            {{ $g['key'] === '' ? __('buyer_account.unassigned') : $g['key'] }}
                        </td>
                        <td class="py-1.5 pr-3 text-right text-gray-600">{{ $g['count'] }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono font-semibold text-red-700 whitespace-nowrap">
                            {{ number_format($g['unpaid'], 2) }} <span class="text-[10px] font-normal text-gray-400">{{ $g['currency'] }}</span>
                        </td>
                        <td class="py-1.5 max-w-[260px] truncate text-gray-500" title="{{ implode(', ', $g['vehicles']) }}">
                            {{ implode(', ', $g['vehicles']) }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('buyer_account.no_unpaid') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 미수 차량 --}}
    <div class="card">
        <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('buyer_account.vehicles_title') }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b text-left text-gray-400">
                        {{-- 정렬 가능한 열 — 같은 축을 다시 누르면 오름/내림이 뒤집힌다.
                             🚨 금액 정렬은 **그 차량 통화** 기준이다(원화로 줄 세우면 보이는 숫자와
                                순서가 어긋난다 — 이 표는 바이어에게 그대로 나간다). --}}
                        <th class="pb-1.5 pr-3">
                            <button type="button" wire:click="sortBy('vehicle')" class="hover:text-primary-text">
                                {{ __('buyer_account.col_vehicle') }}{!! $sort === 'vehicle' ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '' !!}
                            </button>
                        </th>
                        <th class="pb-1.5 pr-3">{{ __('buyer_account.col_vin') }}</th>
                        <th class="pb-1.5 pr-3">
                            <button type="button" wire:click="sortBy('progress')" class="hover:text-primary-text">
                                {{ __('buyer_account.col_progress') }}{!! $sort === 'progress' ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '' !!}
                            </button>
                        </th>
                        <th class="pb-1.5 pr-3 text-right">{{ __('buyer_account.col_total') }}</th>
                        <th class="pb-1.5 pr-3 text-right">{{ __('buyer_account.col_received') }}</th>
                        <th class="pb-1.5 pr-3 text-right">
                            <button type="button" wire:click="sortBy('unpaid')" class="hover:text-primary-text"
                                    title="{{ __('buyer_account.sort_unpaid_note') }}">
                                {{ __('buyer_account.col_unpaid') }}{!! $sort === 'unpaid' ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '' !!}
                            </button>
                        </th>
                        <th class="pb-1.5 pr-3">{{ __('buyer_account.axis.container') }}</th>
                        <th class="pb-1.5 pr-3">{{ __('buyer_account.axis.declaration') }}</th>
                        <th class="pb-1.5 pr-3">{{ __('buyer_account.axis.bl') }}</th>
                        <th class="pb-1.5">{{ __('buyer_account.axis.vessel') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($this->vehicles as $v)
                    @php $total = $v->sale_total_amount; @endphp
                    <tr>
                        <td class="py-1.5 pr-3 font-mono whitespace-nowrap text-gray-700">{{ $v->vehicle_number }}</td>
                        <td class="py-1.5 pr-3 max-w-[140px] truncate font-mono text-gray-500" title="{{ $v->nice_reg_vin }}">{{ $v->nice_reg_vin }}</td>
                        <td class="py-1.5 pr-3 whitespace-nowrap text-gray-500">{{ $v->progress_status_cache }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono text-gray-600 whitespace-nowrap">
                            {{ number_format($total, 2) }} <span class="text-[10px] text-gray-400">{{ $v->currency }}</span>
                        </td>
                        <td class="py-1.5 pr-3 text-right font-mono text-gray-600">{{ number_format($total - $v->sale_unpaid_amount, 2) }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono font-semibold text-red-700">{{ number_format($v->sale_unpaid_amount, 2) }}</td>
                        <td class="py-1.5 pr-3 max-w-[130px] truncate text-gray-500" title="{{ $v->container_number }}">{{ $v->container_number }}</td>
                        <td class="py-1.5 pr-3 max-w-[110px] truncate text-gray-500" title="{{ $v->export_declaration_number }}">{{ $v->export_declaration_number }}</td>
                        <td class="py-1.5 pr-3 max-w-[110px] truncate text-gray-500" title="{{ $v->bl_number }}">{{ $v->bl_number }}</td>
                        <td class="py-1.5 max-w-[110px] truncate text-gray-500" title="{{ $v->vessel_name }}">{{ $v->vessel_name }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="py-6 text-center text-gray-400">{{ __('buyer_account.no_unpaid') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @endif
</div>
