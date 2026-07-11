@props([
    'status' => 'none',      // none | pending | viewed | signed
    'contractId' => null,    // signed 일 때 서명본 PDF 링크용
    'requestClick' => '',    // 미발송 → wire:click (예: "requestSignatureForBatch('A12')")
    'linkClick' => '',       // pending/viewed → wire:click (예: "showSignLink(12)")
    'disabled' => false,     // 요청 불가(혼합 바이어/통화 등)
    'disabledHint' => '',
])

@php $base = 'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-semibold'; @endphp

@if ($status === 'signed')
    <a href="{{ route('erp.signed-contracts.pdf', $contractId) }}" target="_blank" rel="noopener"
       class="{{ $base }} border-emerald-300 bg-emerald-100 text-emerald-800 hover:bg-emerald-200">
        ✓ {{ __('signed_contract.chip.signed') }}
    </a>
@elseif ($status === 'pending' || $status === 'viewed')
    <button type="button" wire:click="{{ $linkClick }}"
            class="{{ $base }} border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100">
        ⏳ {{ $status === 'viewed' ? __('signed_contract.chip.viewed') : __('signed_contract.chip.waiting') }}
    </button>
@else
    <button type="button" wire:click="{{ $requestClick }}"
            @disabled($disabled) title="{{ $disabled ? $disabledHint : '' }}"
            class="{{ $base }} border-purple-400 bg-purple-600 text-white hover:bg-purple-700 {{ $disabled ? 'cursor-not-allowed opacity-50' : '' }}">
        ✍ {{ __('signed_contract.request_btn') }}
    </button>
@endif
