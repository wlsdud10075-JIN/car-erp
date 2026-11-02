@props(['type'])
{{-- 🏷️ 거래 유형 뱃지 DP/FD (B2B 파트너스 1차, jin 2026-11-01) — 차종명 옆. 단일 출처 = Vehicle::DEAL_TYPES(라벨·전체 명칭·색).
     색은 빌드 CSS 에 이미 있는 것(board 칩이 쓴다 — §8 #50). 값이 비었거나 모르는 값이면 아무것도 안 그린다. --}}
{{-- 회사별 토글이 꺼져 있으면 아무 데도 안 그린다(기능설정 「B2B 파트너스 사용」, jin 2026-11-02) — 5곳이 이 한 줄을 본다. --}}
@php $meta = ($type && \App\Models\Setting::b2bPartnersEnabled()) ? (\App\Models\Vehicle::DEAL_TYPES[$type] ?? null) : null; @endphp
@if($meta)<span {{ $attributes->merge(['class' => 'ml-1 whitespace-nowrap rounded-full px-1.5 py-0.5 text-[10px] font-bold align-middle '.$meta['class']]) }} title="{{ $meta['title'] }}" data-deal-badge="{{ $type }}">{{ $meta['label'] }}</span>@endif
