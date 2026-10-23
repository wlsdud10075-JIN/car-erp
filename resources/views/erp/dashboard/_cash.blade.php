{{-- 자금 현황 — 통장 마감잔액 입력 (재무·업무관리자·admin = canEnterCashBalance). 손익은 관리자 대시보드(대표) 전용. 10-17 역할 뷰 분리 때 erp/dashboard 본문에서 그대로 옮겨 왔다(마크업 불변 — CashAccountBalanceTest·CapitalStatusTest 가 본다). --}}
@if(auth()->user()->canEnterCashBalance())
<div class="card" data-cash-card>
    <div class="mb-2 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="section-dot bg-blue-500"></span>
            <span class="text-sm font-semibold text-gray-800">{{ __('cash.input_title') }}</span>
        </div>
        @if($cashSavedAt)<span class="text-xs text-gray-400">{{ __('cash.last_input') }} {{ $cashSavedAt }}</span>@endif
    </div>
    @if($this->cashAccounts->isNotEmpty())
    {{-- 🏦 계좌별 입력 모드 (jin 2026-10-14) — 날짜 · 계좌 드롭다운 · 그 계좌의 통화 칸 · 저장. 아래 표 = 이 날짜에 적용되는 계좌별 값. --}}
    @php $selAcc = $this->cashAccounts->firstWhere('id', (int) $cashAccountId); @endphp
    <div class="flex flex-wrap items-end gap-2" data-cash-accounts>
        <div class="w-full sm:w-40">
            <label class="text-xs text-gray-500">{{ __('cash.date') }}</label>
            <input type="date" wire:model.live="cashDate" max="{{ now()->toDateString() }}" class="input-base w-full" />
        </div>
        <div class="w-full sm:w-64">
            <label class="text-xs text-gray-500">{{ __('cash.account.label') }}</label>
            <select wire:model.live="cashAccountId" class="input-base w-full text-gray-800">
                <option value="">{{ __('cash.account.pick') }}</option>
                @foreach($this->cashAccounts as $acc)
                <option value="{{ $acc->id }}">{{ $acc->display_name }}</option>
                @endforeach
            </select>
        </div>
        <button type="button" wire:click="$toggle('showAddAccount')" class="h-9 rounded-lg border border-dashed border-gray-300 px-3 text-xs text-gray-600 hover:bg-gray-50">{{ __('cash.account.add_row') }}</button>
        @if($selAcc)
            {{-- 어느 계좌든 원화·달러·유로 세 칸(jin 2026-10-14). 비운 칸은 안 건드린다(이어쓰기). ✏️ 수정 중이면 비운 칸 = 그 날짜 값 삭제(2026-10-22). --}}
            @foreach(\App\Models\CashAccount::CURRENCIES as $cur)
            <div class="w-full sm:w-44">
                <label class="text-xs text-gray-500">{{ __('cash.'.strtolower($cur)) }}</label>
                <div class="flex gap-1">
                    @if($cur === 'KRW')
                    {{-- 마이너스 통장은 칸에 − 를 직접 친다(data-money-signed) — 10-07 단축키 폐지로 가능해졌다(jin 2026-10-14). --}}
                    <input type="text" wire:model="cashAmounts.{{ $cur }}" wire:key="cash-amt-{{ $selAcc->id }}-{{ $cur }}" data-money data-money-signed inputmode="numeric" class="input-base w-full text-right" placeholder="0" title="{{ __('cash.sign_hint_typed') }}" />
                    @else
                    <input type="text" wire:model="cashAmounts.{{ $cur }}" wire:key="cash-amt-{{ $selAcc->id }}-{{ $cur }}" inputmode="decimal" class="input-base w-full text-right" placeholder="0.00" />
                    @endif
                </div>
            </div>
            @endforeach
            <button type="button" wire:click="saveAccountBalance" class="btn-primary h-9">{{ $cashEditAccountId === $selAcc->id ? __('cash.account.save_edit') : __('cash.save') }}</button>
        @endif
    </div>
    @if($selAcc && $cashEditAccountId === $selAcc->id)
    {{-- ✏️ 수정 중 (jin 2026-10-22 «숨김 말고 수정 — 잘못 기록한 것을 수정하거나 삭제하고 다시 기입») --}}
    <div class="mt-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800" data-cash-editing>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-semibold">{{ __('cash.account.editing', ['account' => $selAcc->display_name, 'date' => $cashDate]) }}</span>
            <div class="flex flex-wrap items-center gap-3">
                <label class="inline-flex items-center gap-1"><input type="checkbox" wire:click="toggleCashForeign" @checked($selAcc->is_foreign) /> {{ __('cash.account.foreign') }}</label>
                <button type="button" wire:click="deleteCashEntry" wire:confirm="{{ __('cash.account.delete_confirm') }}" class="text-red-600 hover:underline">{{ __('cash.account.delete_date') }}</button>
                <button type="button" wire:click="deactivateCashAccount({{ $selAcc->id }})" wire:confirm="{{ __('cash.account.hide_confirm') }}" class="text-gray-500 hover:underline">{{ __('cash.account.hide_account') }}</button>
                <button type="button" wire:click="cancelCashEdit" class="text-gray-600 hover:underline">{{ __('cash.account.cancel') }}</button>
            </div>
        </div>
        <p class="mt-1 text-blue-700/80">{{ __('cash.account.edit_hint') }}</p>
    </div>
    @endif
    @if($cashFxWarn && $selAcc)
    {{-- 🌐 외화 계좌에 원화 — 막지 않고 묻는다(jin 2026-10-22). [그래도 기록] 이면 저장된다. --}}
    <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4" data-cash-fx-warn>
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
            <p class="text-sm font-semibold text-gray-900">{{ __('cash.account.fx_warn_title') }}</p>
            <p class="mt-2 text-sm text-gray-600">{{ __('cash.account.fx_warn_body', ['account' => $selAcc->display_name, 'krw' => number_format((float) str_replace([',', ' '], '', (string) ($cashAmounts['KRW'] ?? '0')))]) }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" wire:click="cancelFxSave" class="h-9 rounded-lg border border-gray-300 px-4 text-sm text-gray-700 hover:bg-gray-50">{{ __('cash.account.cancel') }}</button>
                <button type="button" wire:click="confirmFxSave" class="h-9 rounded-lg bg-amber-600 px-4 text-sm font-medium text-white hover:bg-amber-700">{{ __('cash.account.fx_confirm') }}</button>
            </div>
        </div>
    </div>
    @endif
    @if($showAddAccount)
    <div class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-gray-50 p-2" data-cash-account-add>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.bank') }}</label><input type="text" wire:model="newBank" class="input-base w-28" placeholder="{{ __('cash.account.bank_ph') }}" /></div>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.number') }}</label><input type="text" wire:model="newAccountNo" class="input-base w-48" placeholder="{{ __('cash.account.number_ph') }}" /></div>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.label_opt') }}</label><input type="text" wire:model="newLabel" class="input-base w-24" placeholder="{{ __('cash.account.label_ph') }}" /></div>
        <label class="inline-flex h-9 items-center gap-1 text-xs text-gray-600" title="{{ __('cash.account.foreign_hint') }}"><input type="checkbox" wire:model="newForeign" /> {{ __('cash.account.foreign') }}</label>
        <button type="button" wire:click="addCashAccount" class="btn-primary h-9">{{ __('cash.account.add') }}</button>
        @error('newBank')<p class="w-full text-xs text-red-500">{{ $message }}</p>@enderror
        @error('newAccountNo')<p class="w-full text-xs text-red-500">{{ $message }}</p>@enderror
    </div>
    @endif
    @php
        $rows = $this->cashAccountRows;
        $sums = ['KRW' => 0.0, 'USD' => 0.0, 'EUR' => 0.0];
        foreach ($rows as $r) { if ($r['balance'] !== null) { $sums[$r['currency']] += $r['balance']; } }
        // 계좌 한 줄 = 통화 3칸. 한 번도 안 넣은 계좌(세 통화 전부 없음)만 센다 — 외화가 없는 계좌는 달러·유로가 비는 게 정상이다.
        $byAccount = $rows->groupBy(fn ($r) => $r['account']->id);
        $never = $byAccount->filter(fn ($g) => $g->every(fn ($r) => $r['balance'] === null))->count();
        // 💱 외화 잔액의 원화 환산 (jin 2026-10-23) — 화면 위 「실시간 환율」 줄과 같은 값. 표시만 하고 저장·청산가치엔 안 쓴다.
        $fxRates = $this->exchangeRates;
        $fxUsd = isset($fxRates['USD']) ? $sums['USD'] * $fxRates['USD'] : null;
        $fxEur = isset($fxRates['EUR']) ? $sums['EUR'] * $fxRates['EUR'] : null;
        $fxTotal = ($fxUsd !== null && $fxEur !== null) ? $sums['KRW'] + $fxUsd + $fxEur : null;
    @endphp
    {{-- 🚨 한 번도 안 넣은 계좌는 합계에 0 으로 들어간다 — 계좌 7개를 등록하고 하나만 넣은 날 청산가치가 꺼진다. 눈에 띄게 알린다. --}}
    @if($never > 0)
    <p class="mt-2 rounded bg-amber-50 px-3 py-1.5 text-xs text-amber-700" data-cash-never-entered>{{ __('cash.account.never_entered', ['n' => $never]) }}</p>
    @endif
    {{-- 📱 폰 = 계좌별 카드(jin 2026-10-20 «모바일에서 옆으로 스크롤해야 되던데») — 표는 sm 이상에서만. 값·합계는 같은 $byAccount/$sums. --}}
    <div class="mt-2 space-y-2 sm:hidden" data-cash-account-cards>
        @foreach($byAccount as $accId => $g)
            @php $acc = $g->first()['account']; $cells = $g->keyBy('currency'); $asOf = $g->pluck('as_of')->filter()->max(); $carried = $g->contains(fn ($r) => $r['carried']); @endphp
            <div class="rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-700">
                <div class="flex items-start justify-between gap-2">
                    <span class="break-all font-medium text-gray-800">{{ $acc->display_name }}@if($acc->is_foreign) <span class="rounded bg-sky-100 px-1 text-[10px] text-sky-700">{{ __('cash.account.foreign_badge') }}</span>@endif</span>
                    <button type="button" wire:click="editCashAccount({{ $acc->id }})" class="shrink-0 text-[11px] text-blue-600 hover:underline" data-cash-edit>{{ __('cash.account.edit') }}</button>
                </div>
                <dl class="mt-1 space-y-0.5">
                    @foreach(\App\Models\CashAccount::CURRENCIES as $cur)
                    @php $c = $cells->get($cur); @endphp
                    <div class="flex justify-between"><dt class="text-gray-500">{{ $cur }}</dt><dd class="font-mono {{ ($c['carried'] ?? false) ? 'text-gray-400' : '' }}">{{ ($c['balance'] ?? null) === null ? '—' : ($cur === 'KRW' ? number_format($c['balance']) : number_format($c['balance'], 2)) }}</dd></div>
                    @endforeach
                </dl>
                <p class="mt-1 text-[11px] {{ $carried ? 'text-gray-400' : 'text-gray-500' }}">{{ __('cash.account.as_of') }} {{ $asOf ?? __('cash.account.never') }}{{ $carried ? ' '.__('cash.account.carried') : '' }}</p>
            </div>
        @endforeach
        <div class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-800">
            <div class="mb-0.5">{{ __('cash.account.total') }}</div>
            <div class="flex justify-between"><span>KRW</span><span class="font-mono">{{ number_format($sums['KRW']) }}</span></div>
            <div class="flex justify-between"><span>USD</span><span class="font-mono">{{ number_format($sums['USD'], 2) }}</span></div>
            <div class="flex justify-between"><span>EUR</span><span class="font-mono">{{ number_format($sums['EUR'], 2) }}</span></div>
            <div class="mt-1 border-t border-gray-200 pt-1 font-normal text-gray-600" data-cash-fx-total>
                @if($fxTotal !== null)
                <div class="flex justify-between"><span>{{ __('cash.account.fx_total') }}</span><span class="font-mono font-semibold text-gray-800">₩{{ number_format($fxTotal) }}</span></div>
                <div class="text-[10px] text-gray-400">USD ≈ ₩{{ number_format($fxUsd) }} · EUR ≈ ₩{{ number_format($fxEur) }} ({{ __('cash.account.fx_rates', ['usd' => number_format($fxRates['USD'], 2), 'eur' => number_format($fxRates['EUR'], 2)]) }})</div>
                @else
                <div class="text-[10px] text-amber-600">{{ __('cash.account.fx_unavailable') }}</div>
                @endif
            </div>
        </div>
    </div>
    <div class="mt-2 hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[520px] text-xs" data-cash-account-table>
            <thead><tr class="border-b border-gray-200 text-left text-gray-500">
                <th class="py-1 pr-2">{{ __('cash.account.label') }}</th>
                <th class="py-1 pr-2 text-right">KRW</th><th class="py-1 pr-2 text-right">USD</th><th class="py-1 pr-2 text-right">EUR</th>
                <th class="py-1 pr-2">{{ __('cash.account.as_of') }}</th><th class="py-1"></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            @foreach($byAccount as $accId => $g)
                @php $acc = $g->first()['account']; $cells = $g->keyBy('currency'); $asOf = $g->pluck('as_of')->filter()->max(); $carried = $g->contains(fn ($r) => $r['carried']); @endphp
                <tr class="text-gray-700">
                    <td class="py-1 pr-2">{{ $acc->display_name }}@if($acc->is_foreign) <span class="rounded bg-sky-100 px-1 text-[10px] text-sky-700">{{ __('cash.account.foreign_badge') }}</span>@endif</td>
                    @foreach(\App\Models\CashAccount::CURRENCIES as $cur)
                    @php $c = $cells->get($cur); @endphp
                    <td class="py-1 pr-2 text-right font-mono {{ ($c['carried'] ?? false) ? 'text-gray-400' : '' }}">{{ ($c['balance'] ?? null) === null ? '—' : ($cur === 'KRW' ? number_format($c['balance']) : number_format($c['balance'], 2)) }}</td>
                    @endforeach
                    <td class="py-1 pr-2 {{ $carried ? 'text-gray-400' : '' }}">{{ $asOf ?? __('cash.account.never') }}{{ $carried ? ' '.__('cash.account.carried') : '' }}</td>
                    <td class="py-1 text-right">
                        <button type="button" wire:click="editCashAccount({{ $acc->id }})" class="text-[11px] text-blue-600 hover:underline" data-cash-edit>{{ __('cash.account.edit') }}</button>
                    </td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="border-t border-gray-200 font-semibold text-gray-800">
                <td class="py-1 pr-2">{{ __('cash.account.total') }}</td>
                <td class="py-1 pr-2 text-right font-mono">{{ number_format($sums['KRW']) }}</td>
                <td class="py-1 pr-2 text-right font-mono">{{ number_format($sums['USD'], 2) }}</td>
                <td class="py-1 pr-2 text-right font-mono">{{ number_format($sums['EUR'], 2) }}</td>
                <td class="py-1" colspan="2"></td>
            </tr>
            <tr class="text-gray-600" data-cash-fx-total>
                @if($fxTotal !== null)
                <td class="py-1 pr-2">{{ __('cash.account.fx_total') }}</td>
                <td class="py-1 pr-2 text-right font-mono font-semibold text-gray-800">₩{{ number_format($fxTotal) }}</td>
                <td class="py-1 pr-2 text-right font-mono text-gray-500">≈ ₩{{ number_format($fxUsd) }}</td>
                <td class="py-1 pr-2 text-right font-mono text-gray-500">≈ ₩{{ number_format($fxEur) }}</td>
                <td class="py-1 text-[10px] text-gray-400" colspan="2">{{ __('cash.account.fx_rates', ['usd' => number_format($fxRates['USD'], 2), 'eur' => number_format($fxRates['EUR'], 2)]) }}</td>
                @else
                <td class="py-1 pr-2 text-[11px] text-amber-600" colspan="6">{{ __('cash.account.fx_unavailable') }}</td>
                @endif
            </tr></tfoot>
        </table>
    </div>
    @else
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <div>
            <label class="text-xs text-gray-500">{{ __('cash.date') }}</label>
            <input type="date" wire:model="cashDate" max="{{ now()->toDateString() }}" class="input-base w-full" />
        </div>
        <div>
            <label class="text-xs text-gray-500">{{ __('cash.krw') }}</label>
            {{-- 🏦 마이너스 통장은 − 를 직접 친다(data-money-signed). 구 ± 토글(08-12)은 10-07 단축키 폐지로 불필요해져 10-14 제거(jin). --}}
            <input type="text" wire:model="cashKrw" data-money data-money-signed inputmode="numeric" class="input-base w-full text-right" placeholder="0" title="{{ __('cash.sign_hint_typed') }}" />
        </div>
        <div>
            <label class="text-xs text-gray-500">{{ __('cash.usd') }}</label>
            <input type="text" wire:model="cashUsd" inputmode="decimal" class="input-base w-full text-right" placeholder="0.00" />
        </div>
        <div>
            <label class="text-xs text-gray-500">{{ __('cash.eur') }}</label>
            <input type="text" wire:model="cashEur" inputmode="decimal" class="input-base w-full text-right" placeholder="0.00" />
        </div>
        <div class="flex items-end">
            <button type="button" wire:click="saveCashBalance" class="btn-primary w-full">{{ __('cash.save') }}</button>
        </div>
    </div>
    @if(auth()->user()->canEnterCashBalance())
    <button type="button" wire:click="$toggle('showAddAccount')" class="mt-2 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('cash.account.add_row') }}</button>
    @if($showAddAccount)
    <div class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-gray-50 p-2" data-cash-account-add>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.bank') }}</label><input type="text" wire:model="newBank" class="input-base w-28" placeholder="{{ __('cash.account.bank_ph') }}" /></div>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.number') }}</label><input type="text" wire:model="newAccountNo" class="input-base w-48" placeholder="{{ __('cash.account.number_ph') }}" /></div>
        <div><label class="text-xs text-gray-500">{{ __('cash.account.label_opt') }}</label><input type="text" wire:model="newLabel" class="input-base w-24" placeholder="{{ __('cash.account.label_ph') }}" /></div>
        <label class="inline-flex h-9 items-center gap-1 text-xs text-gray-600" title="{{ __('cash.account.foreign_hint') }}"><input type="checkbox" wire:model="newForeign" /> {{ __('cash.account.foreign') }}</label>
        <button type="button" wire:click="addCashAccount" class="btn-primary h-9">{{ __('cash.account.add') }}</button>
        @error('newBank')<p class="w-full text-xs text-red-500">{{ $message }}</p>@enderror
        @error('newAccountNo')<p class="w-full text-xs text-red-500">{{ $message }}</p>@enderror
    </div>
    @endif
    @endif
    @endif
    <p class="mt-2 text-[11px] text-gray-400">{{ __('cash.input_note') }}</p>
</div>
@endif
