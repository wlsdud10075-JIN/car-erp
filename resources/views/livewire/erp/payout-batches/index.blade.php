<?php

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // Phase 2 — 월정산 정산지급 승인큐. 제출자([관리]/업무관리자) 상태확인 + 승인자(현재 계단) 결정.
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
            'submitter', 'approvals.approver', 'steps.approver', 'batchChanges.user', 'batchChanges.salesman', 'settlements.salesman',
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

    // ── 월정산 v3 — 결재 중 인센티브 수정 (jin 2026-10-08 「즉시 반영해서 재결재」) ──
    //   포인터는 그대로(멈춘 단계부터 이어서), 변경 이력 + 총액 재계산. 알림은 카드의 노란 표시(ERP 안).
    public string $incSalesmanId = '';

    public string $incAmount = '';

    public string $incReason = '';

    public string $approveNote = '';

    /** 인센티브 반영 뒤 배너(닫기 전까지 유지) — 토스트만으론 「아무 반응 없다」로 보였다(jin 2026-10-09). */
    public ?string $incentiveNotice = null;

    public function addIncentive(int $batchId): void
    {
        $batch = SettlementPayoutBatch::findOrFail($batchId);
        $amount = (int) preg_replace('/[^\-0-9]/', '', $this->incAmount);
        if ($this->incSalesmanId === '' || $amount === 0 || trim($this->incReason) === '') {
            $this->dispatch('notify', message: __('payout_batch.steps.incentive_invalid'), type: 'warning');

            return;
        }
        try {
            $batch->addIncentive(auth()->user(), (int) $this->incSalesmanId, $amount, trim($this->incReason));
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $name = \App\Models\Salesman::find((int) $this->incSalesmanId)?->name ?? '-';
        $this->incSalesmanId = $this->incAmount = $this->incReason = '';
        unset($this->batches);
        $this->incentiveNotice = __('payout_batch.steps.incentive_added', ['name' => $name, 'amount' => number_format($amount)]);
        $this->dispatch('notify', message: $this->incentiveNotice, type: 'success');
    }

    public function removeIncentive(int $batchId, int $adjustmentId): void
    {
        $batch = SettlementPayoutBatch::findOrFail($batchId);
        try {
            $batch->removeIncentive(auth()->user(), $adjustmentId);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->incentiveNotice = __('payout_batch.steps.incentive_removed');
        $this->dispatch('notify', message: $this->incentiveNotice, type: 'success');
    }

    public function approve(int $id): void
    {
        $batch = SettlementPayoutBatch::findOrFail($id);
        try {
            $batch->approveBy(auth()->user(), trim($this->approveNote) ?: null);
            $this->approveNote = '';
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.notify.approved'), type: 'success');
    }

    /** 📨 승인요청 재전송 (jin 2026-10-07) — 판정(대기 중·제출 권한·10분 대기)은 모델 단일 출처. */
    public function resendRequest(int $id): void
    {
        $batch = SettlementPayoutBatch::findOrFail($id);
        try {
            $batch->resendPayoutRequest(auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.resend.done', ['role' => $this->levelLabel($batch->current_level)]), type: 'success');
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
                            · <span class="text-amber-600">{{ $b->isStepsMode() ? __('payout_batch.steps.next', ['who' => $b->currentStepLabel()]) : __('payout_batch.next_level', ['role' => $this->levelLabel($b->current_level)]) }}</span>
                        @endif
                    </div>
                    {{-- 📨 승인요청 발송 결과 (jin 2026-10-07 「색상으로 발송성공·실패 둘만」) — 시각 없이 뱃지 하나. 알림톡 자체는 무변경.
                         초록 = 발송성공(카카오 접수·전달) / 빨강 = 발송실패(실패·미전달·설정으로 차단). 기록 없으면 안 그린다. --}}
                    @if($b->status === 'pending' && ($sendOk = $b->requestSendOk()) !== null)
                    <span class="rounded px-2 py-0.5 text-[11px] font-medium {{ $sendOk ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}" data-request-send-state="{{ $sendOk ? 'ok' : 'fail' }}">
                        {{ $sendOk ? __('payout_batch.resend.state_ok') : __('payout_batch.resend.state_fail') }}
                    </span>
                    @endif
                    {{-- 📨 승인요청 재전송 (jin 2026-10-07) — 대표가 카톡을 놓쳤을 때 제출 권한자가 다시 보낸다. 연타 방지 10분. --}}
                    @if($b->status === 'pending' && \App\Models\SettlementPayoutBatch::canResendRequest(auth()->user()))
                    @php $wait = $b->resendWaitMinutes(); @endphp
                    <button type="button" wire:click="resendRequest({{ $b->id }})" @disabled($wait > 0)
                            wire:loading.attr="disabled" wire:target="resendRequest({{ $b->id }})"
                            wire:confirm="{{ __('payout_batch.resend.confirm', ['role' => $this->levelLabel($b->current_level)]) }}"
                            title="{{ $wait > 0 ? __('payout_batch.resend.wait', ['min' => $wait]) : __('payout_batch.resend.hint') }}"
                            class="rounded border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            data-resend-request>
                        📨 {{ $wait > 0 ? __('payout_batch.resend.btn_wait', ['min' => $wait]) : __('payout_batch.resend.btn') }}
                    </button>
                    @endif
                    @if($b->status === 'pending' && $canDecide)
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="approveNote" placeholder="{{ __('payout_batch.steps.note_ph') }}" class="input-base h-9 sm:h-7 w-48 text-xs" />
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
                <div class="mt-3 max-w-3xl space-y-2 border-t border-gray-100 pt-3">
                    {{-- 🧾 월정산 v3 (jin 2026-10-08) — 합계 띠 + 사람별 카드(접기/펼치기). 숫자는 BatchPayoutBreakdown/PersonPayoutBreakdown 한 곳.
                         ⚠️ **펼쳤을 때만 계산한다** — 배치의 전 정산을 훑는다(§8 #96-C). 접힌 카드 목록은 최대 60개다. --}}
                    @php $bd = $b->breakdownForDisplay(); $changed = $b->changedFieldsBySalesman(); @endphp
                    @if($incentiveNotice && $b->status === 'pending')
                    <div class="flex items-start justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800" data-incentive-notice>
                        <span><b>✅ 반영되었습니다.</b> {{ $incentiveNotice }} — {{ __('payout_batch.steps.changed_hint') }}</span>
                        <button type="button" wire:click="$set('incentiveNotice', null)" class="shrink-0 text-emerald-700 hover:text-emerald-900" aria-label="닫기">&times;</button>
                    </div>
                    @endif
                    @if($b->status === 'rejected')
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[11px] text-red-700" data-rejected-kept>{{ __('payout_batch.steps.rejected_kept') }}</div>
                    @endif
                    {{-- 🪜 결재선 strip (steps 모드) --}}
                    @if($b->isStepsMode())
                    <div class="flex flex-wrap gap-0" data-approval-steps>
                        <div class="flex-1 min-w-[120px] rounded-l border border-gray-200 bg-white px-2 py-1.5 text-[11px]">
                            <div class="text-gray-500">{{ __('payout_batch.steps.submitted') }} · {{ __('nav.permission.manager') }}</div>
                            <div class="font-semibold text-gray-800">{{ $b->submitter?->name ?? '-' }}</div>
                            <div class="text-emerald-600">✓ {{ $b->submitted_at?->format('m-d H:i') }}</div>
                        </div>
                        @foreach($b->steps as $st)
                        @php $now = $b->status === 'pending' && (int) $b->current_step === (int) $st->seq; @endphp
                        <div class="flex-1 min-w-[120px] border border-l-0 border-gray-200 px-2 py-1.5 text-[11px] {{ $now ? 'bg-violet-50' : 'bg-white' }} {{ $loop->last ? 'rounded-r' : '' }}" data-step="{{ $st->seq }}" data-step-status="{{ $st->status }}">
                            <div class="text-gray-500">{{ $st->title }}</div>
                            <div class="font-semibold text-gray-800">{{ $st->approver?->name ?? '-' }}</div>
                            @if($st->status === 'approved')<div class="text-emerald-600">✓ {{ __('payout_batch.steps.approved') }} {{ $st->acted_at?->format('m-d H:i') }}</div>
                            @elseif($st->status === 'rejected')<div class="text-red-600">✕ {{ __('payout_batch.steps.rejected') }} {{ $st->acted_at?->format('m-d H:i') }}</div>
                            @elseif($now)<div class="font-semibold text-primary-text">{{ __('payout_batch.steps.pending') }}</div>
                            @else<div class="text-gray-400">{{ __('payout_batch.steps.pending') }}</div>@endif
                        </div>
                        @endforeach
                    </div>
                    @endif
                    {{-- 📝 결재 내역 — 서명 + 변경(노란색) 시간순 --}}
                    @php
                        $log = $b->approvals->toBase()->map(fn ($a) => ['at' => $a->created_at, 'who' => $a->approver?->name ?? '-', 'what' => $a->action === 'approved' ? __('payout_batch.steps.approved') : __('payout_batch.steps.rejected'), 'text' => $a->note, 'changed' => false])
                            ->merge($b->batchChanges->toBase()->map(fn ($c) => ['at' => $c->created_at, 'who' => $c->user?->name ?? '-', 'what' => __('payout_batch.steps.change_'.$c->field), 'text' => ($c->salesman?->name ?? '').' '.number_format((int) $c->before).' → '.number_format((int) $c->after).($c->note ? ' ('.$c->note.')' : ''), 'changed' => true]))
                            ->sortBy('at')->values();
                    @endphp
                    @if($log->isNotEmpty())
                    <div class="space-y-0.5 text-[11px]" data-approval-log>
                        <div class="text-[10px] font-semibold uppercase text-gray-400">{{ __('payout_batch.steps.log_title') }}</div>
                        @foreach($log as $l)
                        <div class="flex flex-wrap gap-x-2 rounded px-2 py-1 {{ $l['changed'] ? 'bg-amber-50' : 'bg-gray-50' }}">
                            <b>{{ $l['who'] }}</b><span class="text-gray-500">{{ $l['what'] }}</span><span class="text-gray-400">{{ $l['at']?->format('m-d H:i') }}</span>@if($l['text'])<span>{{ $l['text'] }}</span>@endif
                        </div>
                        @endforeach
                        @if($b->batchChanges->isNotEmpty())<p class="text-[10px] text-gray-400">{{ __('payout_batch.steps.changed_hint') }}</p>@endif
                    </div>
                    @endif
                    <x-payout.totals :totals="$bd['totals']" :settlement-total="(int) $b->total_payout" />
                    @foreach($bd['people'] as $person)
                    <x-payout.person-card :person="$person" mode="batch" :changed="$changed[$person['salesman_id']] ?? []" />
                    @endforeach
                    {{-- ✏️ 결재 중 인센티브 수정 — 제출 권한자·최고관리자. 포인터는 그대로(멈춘 단계부터 이어서). --}}
                    @if($b->status === 'pending' && \App\Models\SettlementPayoutBatch::canEditAdjustments(auth()->user()))
                    <div class="rounded-md border border-dashed border-amber-300 bg-amber-50/40 px-2.5 py-2" data-incentive-form>
                        <div class="mb-1 text-[10px] font-semibold uppercase text-amber-700">{{ __('payout_batch.steps.incentive_title') }}</div>
                        @foreach($b->adjustments->where('kind', 'incentive') as $adj)
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="text-gray-600">{{ $adj->salesman?->name ?? '-' }} · {{ $adj->reason }}</span>
                            <span class="flex items-center gap-2"><span class="tabular-nums font-medium {{ $adj->amount < 0 ? 'text-red-600' : 'text-green-600' }}">{{ $adj->amount < 0 ? '−' : '+' }}₩{{ number_format(abs($adj->amount)) }}</span>
                            <button type="button" wire:click="removeIncentive({{ $b->id }}, {{ $adj->id }})" wire:confirm="{{ __('payout_batch.steps.incentive_removed') }}?" class="h-9 sm:h-7 text-gray-400 hover:text-red-500">&times;</button></span>
                        </div>
                        @endforeach
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <select wire:model="incSalesmanId" class="input-base w-32 text-xs text-gray-800">
                                <option value="">{{ __('settlement.batch.adjust_salesman') }}</option>
                                @foreach(collect($bd['people'])->where('type', '!=', 'inspector') as $pp)
                                <option value="{{ $pp['salesman_id'] }}">{{ $pp['name'] }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="incAmount" data-money data-money-signed placeholder="{{ __('settlement.batch.adjust_amount') }}" class="input-base w-28 text-xs" />
                            <input type="text" wire:model="incReason" placeholder="{{ __('settlement.batch.adjust_reason') }}" class="input-base flex-1 text-xs" />
                            <button type="button" wire:click="addIncentive({{ $b->id }})" wire:confirm="{{ __('payout_batch.steps.incentive_confirm') }}" class="rounded bg-amber-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-amber-700">{{ __('payout_batch.steps.incentive_add') }}</button>
                        </div>
                    </div>
                    @endif

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
