<?php

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // Phase 2 — 월배치 정산지급 승인큐. 제출자([관리]/업무관리자) 상태확인 + 승인자(현재 계단) 결정.
    public ?int $expandedId = null;

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    // 🔀 2026-08-06 (jin) — 조정 입력·매입취소 손실 요약을 **정산관리 제출 모달로 이전**했다.
    //   구조상 조정은 배치에 종속(batch_id)이라 여기선 "제출·카톡 발송 뒤"에만 만들 수 있었고,
    //   그래서 승인자가 카톡에서 본 총액과 실제 지급액이 어긋났다. 이 화면은 이제 승인 전용이다.
    //   조정 내역은 아래 배치 카드에 **읽기 전용**으로 표시된다.

    #[Computed]
    public function batches()
    {
        // 💡 `actual_payout`·`margin_rate` 가 차량마다 **잔금·회수이력**을 읽는다
        //    (정산액 → 총마진 → 판매금원화 → 정산환율 → 미수). 안 얹으면 배치 1개당 쿼리가
        //    1,000개를 넘는다(실측 560건 = 1,125개 → 5개).
        return SettlementPayoutBatch::with([
            'submitter', 'approvals.approver', 'settlements.salesman',
            'settlements.vehicle.finalPayments', 'settlements.vehicle.receivableHistories',
            'adjustments.salesman', 'adjustments.creator',
        ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    public function levelLabel(int $level): string
    {
        return match ($level) {
            2 => __('nav.permission.manager'),
            3 => __('nav.permission.admin'),
            default => (string) $level,
        };
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function approve(int $id): void
    {
        $batch = SettlementPayoutBatch::findOrFail($id);
        try {
            $batch->approveBy(auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.notify.approved'), type: 'success');
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function confirmReject(): void
    {
        if (trim($this->rejectReason) === '') {
            $this->dispatch('notify', message: __('payout_batch.notify.reason_required'), type: 'warning');

            return;
        }
        $batch = SettlementPayoutBatch::findOrFail($this->rejectingId);
        try {
            $batch->rejectBy(auth()->user(), trim($this->rejectReason));
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->rejectingId = null;
        $this->rejectReason = '';
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.notify.rejected'), type: 'success');
    }
}; ?>

<div class="p-3 md:p-6">
    <div class="mb-4">
        <h1 class="text-xl font-bold text-gray-800">{{ __('payout_batch.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('payout_batch.subtitle') }}</p>
    </div>

    <div class="space-y-3">
        @forelse($this->batches as $b)
            @php
                $statusBadge = ['pending' => 'badge-amber', 'approved' => 'badge-green', 'rejected' => 'badge-red', 'cancelled' => 'badge-gray'][$b->status] ?? 'badge-gray';
                $canDecide = $b->canDecide(auth()->user());
                $bySalesman = $b->settlements->groupBy(fn ($s) => $s->salesman?->name ?? __('payout_batch.no_salesman'));
                // 담당자별 조정 합(음수 포함) — 개인 소계에 반영 (jin 2026-07-14). 배치 총액은 recomputeTotal 이 이미 반영.
                $adjBySalesman = $b->adjustments->groupBy(fn ($a) => $a->salesman?->name ?? __('payout_batch.no_salesman'))->map(fn ($g) => (int) $g->sum('amount'));
            @endphp
            <div class="card-tight">
                {{-- 헤더 --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <button type="button" wire:click="toggle({{ $b->id }})" class="flex flex-1 items-center gap-2 text-left">
                        <span class="font-semibold text-gray-800">{{ $b->month }}</span>
                        <span class="badge {{ $statusBadge }}">{{ __('payout_batch.status.'.$b->status) }}</span>
                        <span class="pill-count">{{ __('payout_batch.count', ['n' => $b->settlement_count]) }}</span>
                        <span class="text-sm font-medium text-primary-text">₩{{ number_format($b->total_payout) }}</span>
                    </button>
                    <div class="text-xs text-gray-500">
                        {{ __('payout_batch.submitter') }}: {{ $b->submitter?->name ?? '-' }}
                        @if($b->status === 'pending')
                            · <span class="text-amber-600">{{ __('payout_batch.next_level', ['role' => $this->levelLabel($b->current_level)]) }}</span>
                        @endif
                    </div>
                    @if($b->status === 'pending' && $canDecide)
                    <div class="flex items-center gap-2">
                        <button wire:click="approve({{ $b->id }})" wire:confirm="{{ __('payout_batch.confirm_approve') }}"
                                class="btn-primary text-xs">{{ __('payout_batch.approve') }}</button>
                        <button wire:click="startReject({{ $b->id }})" class="text-xs text-red-500 hover:text-red-700">{{ __('payout_batch.reject') }}</button>
                    </div>
                    @endif
                </div>

                {{-- 반려 사유 입력 --}}
                @if($rejectingId === $b->id)
                <div class="mt-2 flex items-center gap-2 rounded-md border border-red-100 bg-red-50 px-2 py-2">
                    <input type="text" wire:model="rejectReason" wire:keydown.enter="confirmReject"
                           placeholder="{{ __('payout_batch.reject_reason_ph') }}" class="input-base flex-1 text-xs" />
                    <button wire:click="confirmReject" class="rounded bg-red-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-red-700">{{ __('payout_batch.reject_confirm') }}</button>
                    <button wire:click="cancelReject" class="text-xs text-gray-500">{{ __('common.cancel') }}</button>
                </div>
                @endif

                {{-- 승인 이력 --}}
                @if($b->approvals->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-400">
                    @foreach($b->approvals as $a)
                    <span>{{ $a->action === 'approved' ? '✓' : '✕' }} {{ $a->approver?->name }} · {{ $a->created_at?->format('m-d H:i') }}@if($a->note) — {{ $a->note }}@endif</span>
                    @endforeach
                </div>
                @endif
                @if($b->status === 'rejected' && $b->reject_reason)
                <div class="mt-1 text-[11px] text-red-500">{{ __('payout_batch.rejected_reason', ['reason' => $b->reject_reason]) }}</div>
                @endif

                {{-- 드릴다운: 사람별 → 차량별. max-w-md 로 내역↔금액 간격 축소(카드 전폭 양끝 벌어짐 방지, jin 2026-07-07) --}}
                @if($expandedId === $b->id)
                <div class="mt-3 max-w-md space-y-2 border-t border-gray-100 pt-3">
                    {{-- 📊 배치 전체 요약 (jin 2026-09-18) — 마진율 + 「+기본급 = 이달 송금 예상」.
                         ⚠️ **펼쳤을 때만 계산한다** — 마진율은 배치의 전 정산을 훑어야 나오고
                            (실측 560건 0.48초) 카드 목록은 한 번에 최대 60개다. 접힌 카드에 붙이면
                            목록 렌더가 배치 수만큼 곱해져 몇 초가 된다(§8 #96-C). --}}
                    @php
                        $batchRate = Settlement::marginRateOf($b->settlements);
                        $baseTotal = $b->baseSalaryTotal($b->settlements);
                    @endphp
                    <div class="rounded-md bg-gray-50 px-2.5 py-2">
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="font-semibold text-gray-600">{{ __('payout_batch.margin.batch_total') }}</span>
                            <span class="font-semibold text-gray-700" title="{{ __('payout_batch.margin.hint') }}">
                                {{ __('payout_batch.margin.label') }} {{ Settlement::formatMarginRate($batchRate) }}
                            </span>
                        </div>
                        @if($baseTotal > 0)
                        <div class="mt-1 flex items-center justify-between text-[11px] text-gray-500"
                             title="{{ __('payout_batch.margin.pay.expected_hint') }}">
                            <span>+ {{ __('payout_batch.margin.pay.base_salary_total') }} ₩{{ number_format($baseTotal) }}</span>
                            <span class="font-semibold text-primary-text tabular-nums">{{ __('payout_batch.margin.pay.expected_transfer') }} ₩{{ number_format($b->total_payout + $baseTotal) }}</span>
                        </div>
                        @endif
                    </div>

                    @foreach($bySalesman as $name => $group)
                    @php
                        $payoutSum = (int) $group->sum(fn ($s) => $s->actual_payout);
                        $adjSum = (int) ($adjBySalesman[$name] ?? 0);
                        $netSum = $payoutSum + $adjSum;
                        $personRate = Settlement::marginRateOf($group);
                        // 💰 기본급·예치금은 **연결된 담당자**에 붙는다. 없는 사람은 줄이 안 뜼다.
                        $person = $group->first()?->salesman;
                        $baseSalary = (int) ($person?->base_salary_krw ?? 0);
                        $deposit = $person?->deposit_krw;
                    @endphp
                    <div>
                        <div class="flex items-center justify-between text-xs font-medium text-gray-700">
                            <span>{{ $name }}
                                <span class="ml-1 text-[10px] font-normal text-gray-400" title="{{ __('payout_batch.margin.hint') }}">{{ __('payout_batch.margin.label') }} {{ Settlement::formatMarginRate($personRate) }}</span>
                            </span>
                            <span>{{ __('payout_batch.count', ['n' => $group->count()]) }} · ₩{{ number_format($netSum) }}@if($adjSum !== 0) <span class="text-[10px] {{ $adjSum < 0 ? 'text-red-500' : 'text-green-600' }}">({{ $adjSum < 0 ? '−' : '+' }}₩{{ number_format(abs($adjSum)) }} {{ __('payout_batch.adjust.reflected') }})</span>@endif</span>
                        </div>

                        {{-- 💴 사내직원 — 「기본급 + 정산 = 월수령액」 (jin 2026-09-18).
                             🚫 배치 총액에는 안 들어간다. 위 금액(정산)과 아래 월수령액은 뜻이 다르다. --}}
                        @if($baseSalary > 0)
                        <div class="mt-1 ml-3 rounded border border-gray-100 bg-white px-2 py-1 text-[11px]">
                            <div class="flex justify-between text-gray-500"><span>{{ __('payout_batch.margin.pay.base_salary') }}</span><span class="tabular-nums">₩{{ number_format($baseSalary) }}</span></div>
                            <div class="flex justify-between text-gray-500"><span>{{ __('payout_batch.margin.pay.settlement') }}</span><span class="tabular-nums">₩{{ number_format($netSum) }}</span></div>
                            <div class="mt-0.5 flex justify-between border-t border-gray-100 pt-0.5 font-semibold text-gray-700"><span>{{ __('payout_batch.margin.pay.take_home') }}</span><span class="tabular-nums">₩{{ number_format($baseSalary + $netSum) }}</span></div>
                        </div>
                        @endif

                        {{-- 🏦 프리랜서 예치금 — 보유액 표시일 뿐, 지급액에 더하지 않는다(jin 「그냥 보유하면되고」). --}}
                        @if($deposit !== null && $baseSalary === 0)
                        <div class="mt-1 ml-3 text-[11px] text-gray-400">({{ __('payout_batch.margin.pay.deposit') }} ₩{{ number_format($deposit) }})</div>
                        @endif

                        <div class="mt-1 space-y-0.5 pl-3">
                            @foreach($group as $s)
                            <div class="flex items-center justify-between text-[11px] text-gray-500">
                                <span>{{ $s->vehicle?->vehicle_number ?? ('#'.$s->vehicle_id) }}
                                    <span class="ml-1 text-gray-400">{{ Settlement::formatMarginRate($s->margin_rate) }}</span>
                                </span>
                                <span class="tabular-nums">₩{{ number_format($s->actual_payout) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach

                    {{-- 조정 내역 — 읽기 전용 (jin 2026-08-06). 입력은 정산관리 제출 모달 한 곳. --}}
                    @if($b->adjustments->isNotEmpty())
                    <div class="mt-2 border-t border-dashed border-gray-200 pt-2">
                        <div class="mb-1 text-[10px] font-semibold uppercase text-gray-400">{{ __('payout_batch.adjust.title') }}</div>
                        @foreach($b->adjustments as $adj)
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-gray-600">{{ $adj->salesman?->name ?? '-' }} · {{ $adj->reason }}</span>
                            <span class="tabular-nums font-medium {{ $adj->amount < 0 ? 'text-red-600' : 'text-green-600' }}">{{ $adj->amount < 0 ? '−' : '+' }}₩{{ number_format(abs($adj->amount)) }}</span>
                        </div>
                        @endforeach
                        <p class="mt-1 text-[10px] text-gray-400">{{ __('payout_batch.adjust.readonly_hint') }}</p>
                    </div>
                    @endif
                </div>
                @endif
            </div>
        @empty
            <div class="py-12 text-center text-sm text-gray-400">{{ __('payout_batch.empty') }}</div>
        @endforelse
    </div>
</div>
