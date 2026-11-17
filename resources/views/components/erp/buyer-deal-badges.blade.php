{{-- 🏷️ 바이어 DP/FD 뱃지 (jin 2026-11-17) — 바이어 지정(is_dp·is_fd) + 그 유형 차량 대수(dp_count·fd_count, withCount).
     진한색 = 바이어가 「DP·FD 진행 중」으로 지정됨(옆 숫자 = 그 유형으로 지정된 차량 대수).
     연한색 = 바이어 지정은 없는데 차량만 그 유형 — 맞춰 볼 대상. 단일 출처: 바이어 목록 데스크탑·모바일. --}}
@props(['buyer'])
@php
    $deals = [
        'DP' => ['on' => (bool) $buyer->is_dp, 'n' => (int) ($buyer->dp_count ?? 0), 'solid' => 'bg-purple-600 text-white', 'soft' => 'bg-purple-100 text-purple-700'],
        'FD' => ['on' => (bool) $buyer->is_fd, 'n' => (int) ($buyer->fd_count ?? 0), 'solid' => 'bg-blue-600 text-white', 'soft' => 'bg-blue-100 text-blue-700'],
    ];
@endphp
@foreach($deals as $type => $d)
    @if($d['on'] || $d['n'] > 0)
        <span class="ml-1 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-bold {{ $d['on'] ? $d['solid'] : $d['soft'] }}"
              data-buyer-deal="{{ $type }}{{ $d['on'] ? '-on' : '-off' }}"
              title="{{ \App\Models\Vehicle::DEAL_TYPES[$type]['title'] }} — {{ $d['on'] ? __('buyer.deal.on_hint', ['n' => $d['n']]) : __('buyer.deal.off_hint', ['n' => $d['n']]) }}">{{ $type.($d['n'] > 0 ? ' '.$d['n'] : '') }}</span>
    @endif
@endforeach
