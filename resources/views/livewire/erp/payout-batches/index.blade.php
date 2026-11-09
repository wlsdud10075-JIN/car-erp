<?php

use App\Models\Settlement;
use App\Models\SettlementPayoutBatch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    // ── 🧾 내수직원 월정산 (jin 2026-11-03) — 같은 화면을 /erp/domestic-payouts 로 열면 내수 배치만 + 제출 칸 ──
    //   볼 사람 = 지정자(canSeeDomesticPayout) + 지금 자기 차례인 결재자. 사내직원 월정산 쪽은 내수 배치를 안 보인다.
    #[Locked]
    public bool $domestic = false;

    public string $domesticMonth = '';

    /** 결재선 title => user_id(문자열) — 정산관리 제출 모달과 같은 기본값 규칙. */
    public array $domesticLine = [];

    public function mount(): void
    {
        $this->domestic = request()->routeIs('erp.domestic-payouts.*');
        if (! $this->domestic) {
            // 🧾 사내직원 월정산 = 기능설정에서 지정한 사람(저장 전엔 관리 이상) + 지금 자기 차례인 결재자 (jin 2026-11-05 «내수처럼»)
            $user = auth()->user();
            abort_unless($user->canSeeStaffPayout() || SettlementPayoutBatch::query()->notDomestic()->awaitingDecisionBy($user)->exists(), 403);

            return;
        }
        $user = auth()->user();
        abort_unless(\App\Models\Setting::domesticPayoutEnabled()
            && ($user->canSeeDomesticPayout() || SettlementPayoutBatch::query()->domestic()->awaitingDecisionBy($user)->exists()), 403);

        $this->domesticMonth = (string) (\App\Models\DomesticFeeStatement::query()->max('month') ?? now()->subMonth()->format('Y-m'));
        $last = SettlementPayoutBatch::lastStepsLine();
        foreach (SettlementPayoutBatch::approvalLineOptions() as $title => $users) {
            $pick = $last[$title] ?? ($users->count() === 1 ? (int) $users->first()->id : null);
            $this->domesticLine[$title] = $pick ? (string) $pick : '';
        }
    }

    /** 제출 전 미리보기 — 고른 달의 평가수수료 표가 있는 내수직원 카드(지정자에게만). */
    #[Computed]
    public function domesticPreview(): ?array
    {
        if (! $this->domestic || ! auth()->user()->canSeeDomesticPayout() || ! \App\Models\PayrollEntry::isMonth($this->domesticMonth)) {
            return null;
        }
        $bd = \App\Services\Payout\BatchPayoutBreakdown::forDomesticPreview($this->domesticMonth);

        return $bd + ['total' => (int) collect($bd['people'])->sum('payout')];
    }

    /** 고를 수 있는 달 — 평가수수료 표가 올라온 달(최근 순). */
    #[Computed]
    public function domesticMonths(): array
    {
        return \App\Models\DomesticFeeStatement::query()->distinct()->orderByDesc('month')->pluck('month')->all();
    }

    public function updatedDomesticMonth(): void
    {
        unset($this->domesticPreview);
    }

    public function submitDomestic(): void
    {
        abort_unless($this->domestic, 403);
        try {
            SettlementPayoutBatch::submitForDomestic(auth()->user(), $this->domesticMonth, array_map('intval', $this->domesticLine));
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches, $this->domesticPreview);
        $this->dispatch('notify', message: __('payout_batch.domestic.submitted'), type: 'success');
    }

    #[Computed]
    public function batches()
    {
        // 💡 `actual_payout`·`margin_rate` 가 차량마다 **잔금·회수이력**을 읽는다
        //    (정산액 → 총마진 → 판매금원화 → 정산환율 → 미수). 안 얹으면 배치 1개당 쿼리가
        //    1,000개를 넘는다(실측 560건 = 1,125개 → 5개).
        return SettlementPayoutBatch::with([
            'submitter', 'salesman', 'approvals.approver', 'steps.approver', 'batchChanges.user', 'batchChanges.salesman', 'settlements.salesman',
            'settlements.vehicle.finalPayments', 'settlements.vehicle.receivableHistories',
            'adjustments.salesman', 'adjustments.creator',
        ])
            ->when($this->domestic, fn ($q) => $q->domestic(), fn ($q) => $q->notDomestic())
            // 🚪 퇴사 정산 카드는 맨 뒤 — 화면이 「퇴사자 정산」 접힌 칸으로 따로 묶는다(jin 2026-11-05 «사내직원 월정산에서 이승훈은 없어져야»).
            ->orderByRaw("CASE WHEN kind = 'leaver' THEN 1 ELSE 0 END")
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

    /** ⏸ 지급보류 · ➕ 지급추가 입력칸(jin 2026-11-06) — 열린 사람·배치·종류와 금액·사유. 🚫 메서드 이름과 겹치지 말 것(§8 #32). */
    public ?int $holdBatch = null;

    public ?int $holdTarget = null;

    public string $holdKind = 'hold';

    public string $holdAmount = '';

    public string $holdNote = '';

    /** 입력칸 열기 — 보류는 기본값 = 아직 보류 안 된 몫(전액), 추가는 빈칸. 사유는 매번 새로. */
    public function startHold(int $batchId, int $salesmanId, string $default = '', string $kind = 'hold'): void
    {
        $this->holdBatch = $batchId;
        $this->holdTarget = $salesmanId;
        $this->holdKind = in_array($kind, \App\Models\PayoutHold::KINDS, true) ? $kind : 'hold';
        $this->holdAmount = $default;
        $this->holdNote = '';
    }

    public function cancelHoldDraft(): void
    {
        $this->holdBatch = null;
        $this->holdTarget = null;
        $this->holdKind = 'hold';
        $this->holdAmount = '';
        $this->holdNote = '';
    }

    /**
     * ⏸ 지급보류 / ➕ 지급추가 기입 — 승인된 사내직원 월정산에서 한 사람. 입력칸이 그 사람에게 열려 있으면 그 종류·금액·사유,
     * 아니면 보류 전액(사유는 holdNote). 권한·상태·금액·사유 판정은 모델(holdPayout / addExtraPayout) 한 곳.
     */
    public function holdPerson(int $batchId, int $salesmanId): void
    {
        $open = $this->holdBatch === $batchId && $this->holdTarget === $salesmanId;
        $amount = $open ? \App\Models\PayoutHold::parseAmount($this->holdAmount) : null;
        $extra = $open && $this->holdKind === \App\Models\PayoutHold::KIND_EXTRA;
        try {
            $batch = SettlementPayoutBatch::findOrFail($batchId);
            $extra
                ? $batch->addExtraPayout(auth()->user(), $salesmanId, $amount, $this->holdNote)
                : $batch->holdPayout(auth()->user(), $salesmanId, $amount, $this->holdNote);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->cancelHoldDraft();
        unset($this->batches);
        $this->dispatch('notify', message: __($extra ? 'payout_batch.hold.extra_done' : 'payout_batch.hold.done'), type: 'success');
    }

    /** ✅ 월정산 최종 확정 — 보류·추가 장부 잠금 + 확정본(jin 2026-11-06). 판정은 모델(finalize) 한 곳. */
    public function finalizeBatch(int $batchId): void
    {
        try {
            SettlementPayoutBatch::findOrFail($batchId)->finalize(auth()->user());
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->cancelHoldDraft();
        unset($this->batches);
        $this->dispatch('notify', message: __('payout_batch.hold.finalize_done'), type: 'success');
    }

    /** 보류·추가 취소 — 아직 처리 전(held)인 것만. 🚫 이름을 hold* 프로퍼티와 겹치지 말 것(§8 #32). */
    public function releaseHold(int $batchId, int $salesmanId, string $kind = 'hold'): void
    {
        try {
            SettlementPayoutBatch::findOrFail($batchId)->cancelHold(auth()->user(), $salesmanId, $kind);
        } catch (\DomainException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }
        unset($this->batches);
        $this->dispatch('notify', message: __($kind === 'extra' ? 'payout_batch.hold.extra_cancelled' : 'payout_batch.hold.cancelled'), type: 'success');
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
        <h1 class="text-xl font-bold text-gray-800">{{ $domestic ? __('payout_batch.domestic.title') : __('payout_batch.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ $domestic ? __('payout_batch.domestic.subtitle') : __('payout_batch.subtitle') }}</p>
    </div>

    <div class="space-y-3">
        @php
            // 🚪 퇴사자 정산 (jin 2026-11-05) — 월정산 목록과 섞으면 같은 달에 「한 번 더 나온 것」처럼 보인다.
            //    목록 맨 뒤(batches() 정렬)에서 접힌 칸으로 묶는다. 결재 중인 게 있으면 펼친 채로(결재자가 놓치지 않게).
            $leaverList = $this->batches->filter(fn ($x) => $x->isLeaverBatch());
            $leaverOpened = false;
        @endphp
        @forelse($this->batches as $b)
            @if($b->isLeaverBatch() && ! $leaverOpened)
                @php $leaverOpened = true; @endphp
                <details class="rounded-lg border border-gray-200 bg-gray-50 p-2" data-leaver-section
                         @if($leaverList->contains(fn ($x) => $x->status === 'pending')) open @endif>
                    <summary class="cursor-pointer select-none px-1 text-sm font-semibold text-gray-700">
                        🚪 {{ __('payout_batch.leaver_section', ['n' => $leaverList->count()]) }}
                    </summary>
                    <div class="mt-2 space-y-3">
            @endif
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
                        <span class="whitespace-nowrap font-semibold text-gray-800">{{ $b->month }}</span>
                        {{-- 🚪 퇴사 정산 (2026-10-15) — 달을 닫지 않는 한 사람 배치. 월정산과 한눈에 갈리게 --}}
                        @if($b->isDomesticBatch())<span class="badge badge-green" data-domestic-batch>{{ __('payout_batch.domestic.badge') }}</span>@endif
                        @if($b->isLeaverBatch())<span class="badge badge-purple" data-leaver-batch>{{ __('payout_batch.leaver_label', ['name' => $b->salesman?->name ?? '-']) }}</span>@endif
                        <span class="badge {{ $statusBadge }}">{{ __('payout_batch.status.'.$b->status) }}</span>
                        @if($b->isFinalized())<span class="badge badge-blue" data-finalized title="{{ __('payout_batch.hold.finalize_hint') }}">✅ {{ __('payout_batch.hold.finalize_badge', ['date' => $b->finalized_at?->format('m/d'), 'name' => $b->finalizedBy?->name ?? '']) }}</span>
                        @php $finalNet = (int) ($b->breakdownForDisplay()['totals']['transfer_total'] ?? 0) - array_sum($b->heldAmountsBySalesman()) + array_sum($b->extraAmountsBySalesman()); @endphp
                        <span class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800" data-final-net title="{{ __('payout_batch.hold.net_label', ['n' => count($b->heldAmountsBySalesman()), 'm' => count($b->extraAmountsBySalesman())]) }}">{{ __('payout_batch.hold.final_net_pill', ['amount' => number_format($finalNet)]) }}</span>@endif
                        <span class="pill-count">{{ $b->isDomesticBatch() ? __('payout_batch.domestic.people', ['n' => $b->settlement_count]) : __('payout_batch.count', ['n' => $b->settlement_count]) }}</span>
                        <span class="text-sm font-medium text-primary-text">₩{{ number_format($b->total_payout) }}</span>
                    </button>
                    <div class="text-xs text-gray-500">
                        {{ __('payout_batch.submitter') }}: {{ $b->submitter?->name ?? '-' }}
                        @if($b->status === 'pending')
                            · <span class="text-amber-600">{{ $b->isStepsMode() ? __('payout_batch.steps.next', ['who' => $b->currentStepLabel()]) : __('payout_batch.next_level', ['role' => $this->levelLabel($b->current_level)]) }}</span>
                        @endif
                    </div>
                    {{-- 📄 엑셀 (jin 2026-11-04 (나)) — 사람별 지급·급여 항목(+ 사내직원은 차량별 정산). 승인 링크 엑셀과 같은 파일. --}}
                    <a href="{{ route('erp.payout-batches.export', $b) }}" data-payout-export
                       class="whitespace-nowrap rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-100">{{ __('payout_batch.export') }}</a>
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
                    {{-- 폰: 의견칸은 한 줄 통째, 승인·반려는 줄바꿈 금지(「반/려」 세로 쪼개짐, 2026-10-13). sm 이상은 종전과 같다. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="approveNote" placeholder="{{ __('payout_batch.steps.note_ph') }}" class="input-base h-9 sm:h-7 w-full sm:w-48 text-xs" />
                        <button wire:click="approve({{ $b->id }})" wire:confirm="{{ __('payout_batch.confirm_approve') }}"
                                class="btn-primary whitespace-nowrap text-xs">{{ __('payout_batch.approve') }}</button>
                        <button wire:click="startReject({{ $b->id }})" class="whitespace-nowrap text-xs text-red-500 hover:text-red-700">{{ __('payout_batch.reject') }}</button>
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
                    @php $bd = $b->breakdownForDisplay(); $changed = $b->changedFieldsBySalesman($bd); $payoutChanges = $b->settlementPayoutChanges($bd); @endphp
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
                    {{-- 🧾 제출 뒤 실지급이 바뀐 정산 (jin 2026-10-21 «바뀐걸 찾아낼 수 있는 방안») --}}
                    @if($payoutChanges !== [])
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-[11px]" data-payout-changes>
                        <div class="font-semibold text-amber-800">{{ __('payout_batch.changes.title', ['n' => count($payoutChanges)]) }}</div>
                        <p class="mb-1 text-[10px] text-amber-700">{{ __('payout_batch.changes.hint') }}</p>
                        @foreach($payoutChanges as $c)
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 border-t border-amber-200 py-1" data-payout-change="{{ $c['vehicle_number'] }}">
                            <span><b>{{ $c['name'] }}</b> {{ $c['vehicle_number'] }}@foreach($c['hints'] as $h)<span class="block text-[10px] text-gray-500">{{ $h }}</span>@endforeach</span>
                            <span class="font-mono">{{ number_format($c['before']) }} → <b>{{ number_format($c['after']) }}</b></span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                    @php
                        // ⏸ 지급보류(jin 2026-11-06) — 대표까지 승인된 사내직원 월정산만. 박제·총액은 그대로, 보류 장부를 겹쳐 보여 준다.
                        $holdable = $b->kind === \App\Models\SettlementPayoutBatch::KIND_MONTH && $b->status === 'approved';
                        $holds = $holdable ? $b->holdsBySalesman() : collect();
                        $extras = $holdable ? $b->extrasBySalesman() : collect();   // ➕ 지급추가(대표가 정해 기안자가 기입)
                        $finalized = $b->isFinalized();
                        $canEditLedger = $holdable && \App\Models\SettlementPayoutBatch::canEditAdjustments(auth()->user());
                        $canHold = $canEditLedger && ! $finalized;   // ✅ 최종 확정 뒤엔 장부 잠금
                        $heldSum = (int) $holds->flatten()->sum('amount_krw');
                        $extraSum = (int) $extras->flatten()->sum('amount_krw');
                        $extraPaidOutside = $holdable ? $b->extraPaidOutsideTotal() : 0;   // (가) 월정산 밖 추가 지급 = 그 달 회사이익 차감
                        $netTransfer = (int) ($bd['totals']['transfer_total'] ?? 0) - $heldSum + $extraSum;
                    @endphp
                    @unless($b->isDomesticBatch())
                    <x-payout.totals :totals="$bd['totals']" :settlement-total="(int) $b->total_payout" :held-total="$heldSum" :extra-total="$extraSum" :extra-paid-outside="$extraPaidOutside" />
                    @endunless
                    @if($holdable && $canEditLedger)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border {{ $finalized ? 'border-blue-200 bg-blue-50' : 'border-dashed border-blue-300 bg-white' }} px-2.5 py-1.5 text-[11px]" data-finalize-row>
                        <span class="text-gray-600">{{ $finalized ? __('payout_batch.hold.finalized_line', ['date' => $b->finalized_at?->format('Y-m-d H:i'), 'name' => $b->finalizedBy?->name ?? '']) : __('payout_batch.hold.finalize_hint') }}</span>
                        @unless($finalized)
                        <button type="button" wire:click="finalizeBatch({{ $b->id }})" wire:confirm="{{ __('payout_batch.hold.finalize_confirm', ['net' => number_format($netTransfer)]) }}"
                                data-finalize-button class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">✅ {{ __('payout_batch.hold.finalize_button') }}</button>
                        @endunless
                    </div>
                    @endif
                    @if($holds->isNotEmpty() || $extras->isNotEmpty())
                    {{-- 실제 송금액 — 송금 총액만 보고 송금하지 않게 크게(jin 11-06). 숫자는 합계 띠의 송금 총액 − 보류 합 + 추가 합. --}}
                    <div class="rounded-md border-2 border-amber-300 bg-amber-50 px-3 py-2" data-hold-net>
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span class="text-sm font-bold text-amber-800">{{ __('payout_batch.hold.net_label', ['n' => $holds->count(), 'm' => $extras->count()]) }}</span>
                            <span class="font-mono text-lg font-bold text-amber-900">₩{{ number_format($netTransfer) }}</span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-amber-700">{{ __('payout_batch.hold.net_hint', ['total' => number_format((int) ($bd['totals']['transfer_total'] ?? 0)), 'held' => number_format($heldSum), 'extra' => number_format($extraSum), 'net' => number_format($netTransfer)]) }}</div>
                    </div>
                    @endif
                    @foreach($bd['people'] as $person)
                    <x-payout.person-card :person="$person" mode="batch" :changed="$changed[$person['salesman_id']] ?? []" />
                    @if($holdable)
                        @php
                            $sid = (int) $person['salesman_id']; $payout = (int) ($person['payout'] ?? 0);
                            $hs = $holds->get($sid); $heldHere = $hs ? (int) $hs->sum('amount_krw') : 0;
                            $es = $extras->get($sid); $extraHere = $es ? (int) $es->sum('amount_krw') : 0;
                            $comp = $b->holdCompositionFor($sid, $payout);   // 보류 구성 — 지급 몫 + 흡수된 추가 몫(jin 11-09 «중복으로 보인다»)
                            $payout = $b->holdBase($sid, $payout);   // 보류 바탕 = 지급합계 + 지급추가(2026-11-09, 모델과 같은 함수)
                            $room = $payout - $heldHere;
                            $open = $this->holdBatch === $b->id && $this->holdTarget === $sid;
                            $isExtra = $open && $this->holdKind === \App\Models\PayoutHold::KIND_EXTRA;
                            // 아직 월정산에 안 들어간 줄이 있으면 같은 자리 버튼이 「취소」로 바뀐다(jin 11-06 «자리 안 차지하게»)
                            $holdOpen = $hs && $hs->whereIn('status', \App\Models\PayoutHold::OPEN)->isNotEmpty();
                            $extraOpen = $es && $es->whereIn('status', \App\Models\PayoutHold::OPEN)->isNotEmpty();
                        @endphp
                        @if($hs || $es || $canHold)
                        <div class="-mt-1 flex flex-wrap items-center justify-end gap-2 rounded-b-md border border-t-0 border-slate-200 bg-slate-50 px-2 py-1.5 text-[11px]" data-hold-row="{{ $sid }}">
                            @if($hs)
                                <span class="rounded bg-slate-600 px-1.5 py-0.5 font-semibold text-white" @if($comp['held_extra'] !== 0) title="{{ __('payout_batch.hold.composition', ['payout' => number_format($comp['held_payout']), 'extra' => number_format($comp['held_extra'])]) }}" @endif>⏸ {{ __('payout_batch.hold.badge') }} ₩{{ number_format($heldHere) }}@if($heldHere !== $payout) <span class="font-normal opacity-80">/ {{ number_format($payout) }}</span>@endif</span>
                                @if($comp['held_extra'] !== 0)
                                <span class="text-gray-500" data-hold-composition="{{ $sid }}">{{ __('payout_batch.hold.composition', ['payout' => number_format($comp['held_payout']), 'extra' => number_format($comp['held_extra'])]) }}</span>
                                @endif
                                <span class="text-gray-500">{{ $hs->map(fn ($x) => $finalized && $x->status === \App\Models\PayoutHold::HELD ? __('payout_batch.hold.status_held_final') : __('payout_batch.hold.status.'.$x->status))->unique()->implode(' · ') }}</span>
                            @endif
                            @if($es && $comp['extra_outside'] !== 0)
                                {{-- 보류에 흡수되지 않은 추가만 ➕ 로 — 흡수된 몫은 위 보류 구성에 들어 있다(jin 11-09) --}}
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800" data-extra-badge="{{ $sid }}">➕ {{ __('payout_batch.hold.extra_badge') }} {{ $comp['extra_outside'] < 0 ? '−' : '+' }}₩{{ number_format(abs($comp['extra_outside'])) }}</span>
                                <span class="text-gray-500">{{ $es->map(fn ($x) => $x->status === \App\Models\PayoutHold::RELEASED_PAID ? __('payout_batch.hold.status_extra_paid') : __('payout_batch.hold.status.'.$x->status))->unique()->implode(' · ') }}</span>
                            @elseif($es)
                                <span class="text-gray-500" data-extra-in-hold="{{ $sid }}">{{ __('payout_batch.hold.extra_in_hold', ['amount' => number_format(abs($comp['held_extra']))]) }}</span>
                            @endif
                            @if($canHold)
                                @if($open)
                                    {{-- 입력칸 — 보류: 기본 전액(남은 몫), 일부면 나머지는 그대로 지급 / 추가: 대표가 정한 금액(+/−). 사유는 둘 다 필수(jin 11-06). --}}
                                    <span class="inline-flex flex-wrap items-center justify-end gap-1.5" data-hold-form="{{ $this->holdKind }}">
                                        <label class="text-gray-500">{{ __($isExtra ? 'payout_batch.hold.extra_amount_label' : 'payout_batch.hold.amount_label') }}</label>
                                        <input type="text" inputmode="numeric" data-money data-money-signed wire:model="holdAmount" wire:key="hold-amount-{{ $b->id }}-{{ $sid }}"
                                               class="input-base h-9 w-32 text-right font-mono text-[11px] sm:h-7">
                                        <label class="text-gray-500">{{ __('payout_batch.hold.note_label') }}</label>
                                        <input type="text" wire:model="holdNote" wire:key="hold-note-{{ $b->id }}-{{ $sid }}" maxlength="200" placeholder="{{ __('payout_batch.hold.note_hint') }}"
                                               class="input-base h-9 w-56 text-[11px] sm:h-7" data-hold-note>
                                        <button type="button" wire:click="holdPerson({{ $b->id }}, {{ $sid }})" data-hold-ok
                                                class="rounded {{ $isExtra ? 'bg-amber-600' : 'bg-slate-600' }} px-2.5 py-1 font-semibold text-white">{{ $isExtra ? '➕ '.__('payout_batch.hold.extra_ok') : '⏸ '.__('payout_batch.hold.ok') }}</button>
                                        <button type="button" wire:click="cancelHoldDraft" class="rounded border border-gray-300 px-2 py-1 text-gray-600 hover:bg-gray-100">{{ __('payout_batch.hold.cancel_draft') }}</button>
                                    </span>
                                    <span class="basis-full text-right text-[10px] text-gray-400">{{ $isExtra ? __('payout_batch.hold.extra_amount_hint') : __('payout_batch.hold.amount_hint', ['max' => number_format($room)]) }}</span>
                                @else
                                    {{-- 같은 자리 토글 — 적은 게 있으면 「취소」(아직 월정산에 안 들어간 줄만), 없으면 「기입」. 금액을 고치려면 취소 → 다시 기입. --}}
                                    @if($holdOpen)
                                    {{-- wire:key — 같은 자리를 다른 버튼으로 바꾸므로 morph 가 요소를 재사용해 옛 wire:confirm 이 남지 않게(jin 11-06 «취소 뒤 다시 누르면 취소할까요?») --}}
                                    <button type="button" wire:key="hold-cancel-{{ $b->id }}-{{ $sid }}" wire:click="releaseHold({{ $b->id }}, {{ $sid }})" wire:confirm="{{ __('payout_batch.hold.cancel_confirm', ['name' => $person['name']]) }}"
                                            data-hold-cancel class="rounded-md border border-slate-400 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm">⏸ {{ __('payout_batch.hold.cancel') }}</button>
                                    @elseif($payout !== 0 && $heldHere !== $payout)
                                    <button type="button" wire:key="hold-start-{{ $b->id }}-{{ $sid }}" wire:click="startHold({{ $b->id }}, {{ $sid }}, '{{ number_format($room) }}')"
                                            data-hold-button class="rounded-md bg-slate-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">⏸ {{ __('payout_batch.hold.button') }}</button>
                                    @endif
                                    @if($extraOpen)
                                    <button type="button" wire:key="extra-cancel-{{ $b->id }}-{{ $sid }}" wire:click="releaseHold({{ $b->id }}, {{ $sid }}, 'extra')" wire:confirm="{{ __('payout_batch.hold.extra_cancel_confirm', ['name' => $person['name']]) }}"
                                            data-extra-cancel class="rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-sm">➕ {{ __('payout_batch.hold.extra_cancel') }}</button>
                                    @else
                                    <button type="button" wire:key="extra-start-{{ $b->id }}-{{ $sid }}" wire:click="startHold({{ $b->id }}, {{ $sid }}, '', 'extra')"
                                            data-extra-button class="rounded-md border border-amber-300 bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-sm">➕ {{ __('payout_batch.hold.extra_button') }}</button>
                                    @endif
                                @endif
                            @endif
                        </div>
                        @endif
                    @endif
                    @endforeach
                    <x-payout.domestic-fee-total :total="$bd['domestic_fee_total'] ?? null" :compact="$bd['domestic_split'] ?? false" :status="$bd['domestic_batch_status'] ?? null" />
                    {{-- ✏️ 결재 중 인센티브 수정 — 제출 권한자·최고관리자. 포인터는 그대로(멈춘 단계부터 이어서). --}}
                    @if($b->status === 'pending' && ! $b->isDomesticBatch() && \App\Models\SettlementPayoutBatch::canEditAdjustments(auth()->user()))
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
                                @foreach(collect($bd['people'])->whereNotIn('type', \App\Models\Salesman::PAYROLL_ONLY_TYPES) as $pp)
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
        @if($leaverOpened)
                    </div>
                </details>
        @endif
    </div>

    {{-- 🧾 내수직원 월정산 제출 (2026-11-03) — 달 고르기 → 사람별 카드(엑셀 항목 그대로) → 결재선 → 올리기. 지정자에게만.
         올린 결재가 맨 위에 보이게 목록 **아래**에 두고(jin «최상단에 있어야 맞지 않나»), 고른 달을 이미 올렸으면 **접어 둔다**.
         열림 상태는 Alpine 이 쥔다 — 서버가 open 을 다시 그리면 달을 바꾸는 사이에 닫혀 버린다. --}}
    @if($domestic && ($dp = $this->domesticPreview) !== null)
    @php
        $monthSubmitted = $this->batches->contains(fn ($b) => $b->month === $domesticMonth
            && in_array($b->status, [\App\Models\SettlementPayoutBatch::STATUS_PENDING, \App\Models\SettlementPayoutBatch::STATUS_APPROVED], true));
    @endphp
    <details class="card-tight mt-4 max-w-3xl" data-domestic-submit data-open="{{ $monthSubmitted ? '0' : '1' }}"
             x-data="{ o: {{ $monthSubmitted ? 'false' : 'true' }} }" :open="o" @toggle="o = $el.open" wire:key="domestic-submit-{{ $monthSubmitted ? 'done' : 'open' }}">
        <summary class="flex cursor-pointer flex-wrap items-center gap-2">
            <span class="section-dot bg-green-500"></span>
            <span class="section-title">{{ __('payout_batch.domestic.submit_title') }}</span>
            <span class="pill-count">{{ __('payout_batch.domestic.people', ['n' => count($dp['people'])]) }}</span>
            <span class="text-sm font-medium text-primary-text">₩{{ number_format($dp['total']) }}</span>
            @if($monthSubmitted)<span class="badge badge-gray text-[10px]">{{ __('payout_batch.domestic.already') }}</span>@endif
        </summary>
        <div class="mt-2 space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-32">
                <select wire:model.live="domesticMonth" class="input-base text-xs text-gray-800">
                    @forelse($this->domesticMonths as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                    @empty
                    <option value="{{ $domesticMonth }}">{{ $domesticMonth }}</option>
                    @endforelse
                </select>
            </div>
        </div>
        @if($dp['people'] === [])
        <p class="text-xs text-gray-400">{{ __('payout_batch.domestic.no_statement') }}</p>
        @else
        @foreach($dp['people'] as $person)
        <x-payout.person-card :person="$person" mode="batch" />
        @endforeach
        <x-payout.domestic-fee-total :total="$dp['domestic_fee_total'] ?? null" />
        @php $lineOpts = \App\Models\SettlementPayoutBatch::approvalLineOptions(); @endphp
        @if(!empty($lineOpts))
        <div data-approval-line>
            <div class="section-header"><span class="section-dot bg-sky-500"></span>
                <span class="section-title">{{ __('settlement.batch.line_title') }}</span></div>
            <div class="mt-1 grid gap-1.5 sm:grid-cols-3">
                @foreach($lineOpts as $title => $users)
                <div class="text-xs">
                    <label class="label-base">{{ $title }}</label>
                    <select wire:model="domesticLine.{{ $title }}" class="input-base text-xs text-gray-800">
                        <option value="">{{ __('settlement.batch.line_none') }}</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}@if(trim((string) $u->phone) === '') — {{ __('settlement.batch.line_no_phone') }}@endif</option>
                        @endforeach
                    </select>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        <div class="flex justify-end">
            <button type="button" wire:click="submitDomestic" wire:confirm="{{ __('payout_batch.domestic.confirm', ['month' => $domesticMonth]) }}"
                    wire:loading.attr="disabled" wire:target="submitDomestic" class="btn-primary whitespace-nowrap text-xs">{{ __('payout_batch.domestic.submit') }}</button>
        </div>
        @endif
        </div>
    </details>
    @endif
</div>
