<?php

use App\Services\Ops\OpsBrain;
use App\Services\Ops\OpsHub;
use App\Services\Ops\OpsRoutines;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * 🛰️ 관제센터 (jin 2026-11-12 — 목업 https://claude.ai/artifact/MSFdABnHjthGcxcP5Wq46u) — 설계자용, jin 단독.
 * 사이트 6칸(ERP 3 · BOARD 2 · 회사 GPU PC) 상태·이상 감지·예약 작업·연동. 회사 돈 숫자는 없다(OpsSummary 머리 주석).
 * 데이터 = OpsHub(이 서버는 직접, 나머지는 서명 읽기, 5분 캐시). 세컨드 브레인(문서 지도)은 3단계.
 * ⚠️ 프로퍼티와 메서드 이름을 겹치지 말 것(§8 #32) — siteFilter / pickSite.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public string $siteFilter = '';

    /** 세컨드 브레인 「본문 열기」 — {cluster, no, title, path, body} | null (3단계, jin 11-12) */
    public ?array $docOpen = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isOpsOwner(), 404);
    }

    public function refreshNow(): void
    {
        abort_unless(auth()->user()?->isOpsOwner(), 404);
        OpsHub::collect(true);
    }

    public function pickSite(string $key): void
    {
        $this->siteFilter = $this->siteFilter === $key ? '' : $key;
    }

    public function openDoc(string $cluster, string $id): void
    {
        abort_unless(auth()->user()?->isOpsOwner(), 404);
        $doc = OpsBrain::body($cluster, $id);
        $this->docOpen = $doc ? ['cluster' => $cluster, 'no' => $doc['no'] ?? '', 'title' => $doc['title'] ?? '', 'path' => $doc['path'] ?? '', 'body' => (string) ($doc['body'] ?? '')] : null;
    }

    public function closeDoc(): void
    {
        $this->docOpen = null;
    }

    public function with(): array
    {
        abort_unless(auth()->user()?->isOpsOwner(), 404);
        $sites = OpsHub::collect();

        $anomalies = [];
        $routines = [];
        $erpVersions = [];
        foreach ($sites as $s) {
            $key = $s['site']['key'];
            $d = $s['data'] ?? [];
            if (($s['site']['kind'] ?? '') === 'erp' && ! empty($d['server']['version'])) {
                $erpVersions[$key] = $d['server']['version'];
            }
            foreach ((array) ($d['anomalies'] ?? []) as $a) {
                if (is_array($a) && isset($a['level'])) {
                    $anomalies[] = $a + ['site' => $s['site']['name'], 'site_key' => $key, 'site_kind' => $s['site']['kind'] ?? 'erp'];
                }
            }
            if (! $s['ok'] && (! empty($s['site']['url']) || ! empty($s['site']['self']))) {   // url 없는 메모 항목(GPU PC)은 연결 실패가 아니다
                $anomalies[] = ['level' => 2, 'kind' => '연결', 'count' => 1, 'text' => (string) $s['error'], 'site' => $s['site']['name'], 'site_key' => $key, 'site_kind' => $s['site']['kind'] ?? 'erp'];
            }
            foreach ((array) ($d['routines'] ?? []) as $r) {
                if (is_array($r) && isset($r['command'])) {
                    $routines[$r['command']]['expression'] = $r['expression'] ?? null;
                    $routines[$r['command']]['by'][$key] = $r;
                }
            }
        }
        if (count(array_unique($erpVersions)) > 1) {   // 3사 동시 배포가 어긋남
            $anomalies[] = ['level' => 3, 'kind' => '버전 어긋남', 'count' => count($erpVersions), 'text' => collect($erpVersions)->map(fn ($v, $k) => $k.' '.$v)->implode(' · '), 'site' => 'ERP 3사', 'site_key' => '', 'site_kind' => 'erp'];
        }
        usort($anomalies, fn ($a, $b) => $b['level'] <=> $a['level']);
        ksort($routines);

        $brain = OpsBrain::index();

        $linksSum = ['sent' => 0, 'failed' => 0, 'claude' => 0, 'missing' => [], 'gpu' => '—'];
        foreach ($sites as $s) {
            $d = $s['data'] ?? [];
            if (($s['site']['kind'] ?? '') === 'erp' && $s['ok']) {
                $linksSum['sent'] += (int) ($d['links']['alimtalk_sent_today'] ?? 0);
                $linksSum['failed'] += (int) ($d['links']['alimtalk_failed_today'] ?? 0);
                $linksSum['claude'] += (int) ($d['links']['claude_cost_today_krw'] ?? 0);
                foreach ((array) ($d['links']['keys'] ?? []) as $k => $on) {
                    if (! $on) {
                        $linksSum['missing'][] = $s['site']['name'].' — '.(['anthropic' => 'Claude 키', 'nice' => 'NICE 토큰', 'holiday' => '공휴일 키', 'board_sync' => 'board 연동 시크릿', 'erp_peer' => 'ERP 끼리 시크릿'][$k] ?? $k).' 없음';
                    }
                }
            }
            if (($s['site']['probe'] ?? '') === 'assistant' && $s['ok']) {
                $linksSum['gpu'] = ($d['health'][0]['ok'] ?? false) ? '응답 정상' : '응답 없음';
            }
        }
        $deployed = collect($sites)->first(fn ($s) => ! empty($s['site']['self']))['data']['server']['version'] ?? null;
        $codebase = [
            'deployed' => $deployed, 'dev_head' => $brain['repo_head'] ?? null,
            'tests' => (int) ($brain['clusters']['tests']['count'] ?? 0), 'skills' => (int) ($brain['clusters']['skills']['count'] ?? 0), 'memory' => (int) ($brain['clusters']['memory']['count'] ?? 0),
            'docs' => (int) (($brain['clusters']['design']['count'] ?? 0) + ($brain['clusters']['spec']['count'] ?? 0) + ($brain['clusters']['ops']['count'] ?? 0) + ($brain['clusters']['meet']['count'] ?? 0)),
        ];

        return [
            'linksSum' => $linksSum,
            'codebase' => $codebase,
            'brain' => $brain,
            'brainClusters' => $brain ? array_values(array_map(fn ($c) => ['key' => $c['key'], 'label' => $c['label'], 'color' => $c['color'], 'count' => $c['count'],
                'items' => array_map(fn ($i) => ['id' => $i['id'], 'no' => $i['no'], 'title' => $i['title'], 'meta' => $i['meta'], 'path' => $i['path'] ?? ''], $c['items'])], $brain['clusters'])) : [],
            'sites' => $sites,
            'erpSites' => array_values(array_filter($sites, fn ($s) => ($s['site']['kind'] ?? 'erp') === 'erp')),
            // 줄 셋 = ERP / BOARD / ETC(GPU PC·ssancar.com — jin 11-12 «erp / board / etc 로 할걸»). 모르는 kind 는 ETC 로.
            'rows' => [
                ['ERP', '#7fe7ff', array_values(array_filter($sites, fn ($s) => ($s['site']['kind'] ?? 'erp') === 'erp'))],
                ['BOARD', '#b28cff', array_values(array_filter($sites, fn ($s) => ($s['site']['kind'] ?? '') === 'board'))],
                ['ETC', '#e0c27a', array_values(array_filter($sites, fn ($s) => ! in_array($s['site']['kind'] ?? 'erp', ['erp', 'board'], true)))],
            ],
            'anomalies' => $anomalies,
            'shownAnomalies' => $this->siteFilter === '' ? $anomalies : array_values(array_filter($anomalies, fn ($a) => $a['site_key'] === $this->siteFilter)),
            'routines' => $routines,
            'routineTable' => OpsRoutines::rows($sites),
            'online' => count(array_filter($sites, fn ($s) => $s['ok'])),
            'urgent' => count(array_filter($anomalies, fn ($a) => $a['level'] >= 3)),
        ];
    }
}; ?>

@php
    $panel = 'border: 1px solid rgba(127,231,255,.22); border-radius: 14px; background: rgba(10,20,40,.6); padding: 16px;';
    $head = "font-family: 'Orbitron', 'Noto Sans KR', sans-serif; font-size: 12px; letter-spacing: 2px; color: #7fe7ff";
    $sub = "font-family: 'Noto Sans KR', sans-serif; letter-spacing: 0; color: #9cc3e0";
    $lvName = [1 => '참고', 2 => '주의', 3 => '긴급'];
    $lvColor = [1 => '#9cc3e0', 2 => '#ffcf86', 3 => '#ff7a7a'];
    $fmtAt = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('m/d H:i') : '—';
@endphp

<div wire:poll.300s style="min-height: 100vh; border-radius: 16px; padding: 20px; box-sizing: border-box; color: #d8e6f5; font-family: 'Noto Sans KR', system-ui, sans-serif; background: radial-gradient(1200px 700px at 50% 20%, #0c1a33 0%, #05070d 70%); position: relative" data-ops-control>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=JetBrains+Mono:wght@400;500&display=swap">
    <style>
        [data-ops-control] a { color: #7fe7ff } [data-ops-control] a:hover { color: #c9f6ff }
        @keyframes opsPulse { 0% { box-shadow: 0 0 0 0 rgba(61,255,170,.7) } 70% { box-shadow: 0 0 0 8px rgba(61,255,170,0) } 100% { box-shadow: 0 0 0 0 rgba(61,255,170,0) } }
        @keyframes opsPulseBad { 0% { box-shadow: 0 0 0 0 rgba(255,90,90,.75) } 70% { box-shadow: 0 0 0 9px rgba(255,90,90,0) } 100% { box-shadow: 0 0 0 0 rgba(255,90,90,0) } }
        .ops-ok { animation: opsPulse 2s infinite } .ops-bad { animation: opsPulseBad 1.2s infinite }
        /* 사이트 줄 = 왼쪽 머리칸(ERP·BOARD·ETC) + 회사 3칸 가로(목업 그대로, jin 11-12). 폰에서는 머리칸이 한 줄 차지 */
        .ops-row { display: grid; grid-template-columns: 78px repeat(3, minmax(0, 1fr)); gap: 10px; align-items: start }
        @media (max-width: 760px) { .ops-row { grid-template-columns: minmax(0, 1fr) } }
        [data-ops-control] details > summary { list-style: none } [data-ops-control] details > summary::-webkit-details-marker { display: none }
    </style>
    <div aria-hidden="true" style="position: absolute; inset: 0; background-image: linear-gradient(rgba(80,160,255,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(80,160,255,.06) 1px, transparent 1px); background-size: 40px 40px; pointer-events: none"></div>

    <div style="position: relative; max-width: 1400px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px">
        {{-- 머리줄 --}}
        <div style="{{ $panel }} display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px">
            <div>
                <h1 style="margin: 0; font-family: 'Orbitron', sans-serif; font-size: 18px; letter-spacing: 2px; color: #eaf6ff">CONTROL CENTER <span style="{{ $sub }}; font-size: 14px">· ERP·BOARD 관제</span></h1>
                <span style="font-size: 12px; color: #8fb0cc">시스템 신호만 — 회사 돈 숫자 없음 · 5분마다 갱신 · 접근: 관제 담당자 단독</span>
            </div>
            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 14px; font-family: 'JetBrains Mono', monospace; font-size: 12px">
                <span style="display: flex; align-items: center; gap: 6px"><span class="ops-ok" style="width: 8px; height: 8px; border-radius: 50%; background: #3dffaa"></span>{{ $online }}/{{ count($sites) }} ONLINE</span>
                <span style="display: flex; align-items: center; gap: 6px"><span class="{{ $urgent ? 'ops-bad' : '' }}" style="width: 8px; height: 8px; border-radius: 50%; background: {{ $urgent ? '#ff5a5a' : '#3dffaa' }}"></span>이상 {{ count($anomalies) }} · 긴급 {{ $urgent }}</span>
                <button type="button" wire:click="refreshNow" wire:loading.attr="disabled" style="height: 40px; padding: 0 14px; border-radius: 10px; border: 1px solid rgba(127,231,255,.45); background: rgba(127,231,255,.08); color: #c9f6ff; font: inherit; cursor: pointer">
                    <span wire:loading.remove wire:target="refreshNow">지금 갱신</span><span wire:loading wire:target="refreshNow">읽는 중…</span>
                </button>
            </div>
        </div>

        @if($sites === [])
            <div style="{{ $panel }} color: #ffcf86">사이트 목록이 비어 있습니다 — 서버 .env 의 OPS_SITES 를 채우면 나타납니다.</div>
        @endif

        {{-- 사이트 칸 --}}
        <div style="{{ $panel }} display: flex; flex-direction: column; gap: 12px">
            <span style="{{ $head }}">SITES <span style="{{ $sub }}">· 이름을 누르면 「이상 감지」가 그 사이트만 · 「서버 · 연결」을 누르면 펼침</span></span>
            @foreach($rows as [$rowLabel, $rowColor, $rowSites])
                @if($rowSites !== [])
                <div class="ops-row" data-ops-row="{{ $rowLabel }}">
                    <span style="font-family: 'Orbitron', 'Noto Sans KR', sans-serif; font-size: 13px; letter-spacing: 2px; color: {{ $rowColor }}; padding-top: 12px">{{ $rowLabel }}</span>
                    @foreach($rowSites as $s)
                        @php
                            $site = $s['site']; $d = $s['data'] ?? []; $srv = $d['server'] ?? [];
                            $mine = array_filter($anomalies, fn ($a) => $a['site_key'] === $site['key']);
                            $worst = $mine === [] ? 0 : max(array_column($mine, 'level'));
                            $dot = ! $s['ok'] ? '#6b7790' : ($worst >= 3 ? '#ff5a5a' : ($worst === 2 ? '#ffb040' : '#3dffaa'));
                            $sel = $siteFilter === $site['key'];
                        @endphp
                        <div wire:key="site-{{ $site['key'] }}" data-ops-site="{{ $site['key'] }}" style="border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 6px; border: 1px solid {{ $sel ? $rowColor : 'rgba(127,231,255,.22)' }}; background: {{ $sel ? 'rgba(127,231,255,.10)' : 'rgba(10,20,40,.6)' }}">
                            <button type="button" wire:click="pickSite('{{ $site['key'] }}')" style="all: unset; cursor: pointer; display: flex; flex-direction: column; gap: 4px; min-height: 44px">
                                <span style="display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: #eaf6ff">
                                    <span class="{{ ! $s['ok'] ? '' : ($worst >= 3 ? 'ops-bad' : 'ops-ok') }}" style="width: 8px; height: 8px; border-radius: 50%; background: {{ $dot }}"></span>
                                    {{ $site['name'] }}
                                </span>
                                @if($s['ok'] && empty($site['url']) && empty($site['self']))
                                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #8fb0cc">{{ $d['generated_at'] ?? '' ? '감시 '.\Carbon\Carbon::parse($d['generated_at'])->format('m/d H:i') : '' }}</span>
                                @elseif($s['ok'])
                                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #8fb0cc">{{ $srv['version'] ?? '—' }} · 대기 마이그 {{ $srv['pending_migrations'] ?? '—' }} · 오류 오늘 {{ $srv['errors_today'] ?? '—' }} · 디스크 {{ isset($srv['disk_used_pct']) ? $srv['disk_used_pct'].'%' : '—' }}</span>
                                    <span style="font-size: 12px; color: {{ $mine ? '#ffb38a' : '#3dffaa' }}">{{ $mine ? '이상 '.count($mine).'건' : '이상 없음' }}</span>
                                @else
                                    <span style="font-size: 12px; color: #9aa6bd">{{ $s['error'] }}</span>
                                @endif
                            </button>
                            @if(empty($site['url']) && empty($site['self']))
                                {{-- 메모 항목(GPU PC) — 접지 않고 바로 보인다(jin 11-12 «내용이 안 뜨는 것 같다») --}}
                                <div style="display: flex; flex-direction: column; gap: 3px; border-top: 1px solid rgba(127,231,255,.14); padding-top: 6px; font-size: 11px" data-ops-notes="{{ $site['key'] }}">
                                    @foreach((array) ($d['health'] ?? []) as $h)
                                        <span style="color: {{ ($h['ok'] ?? true) ? '#3dffaa' : '#ffcf86' }}">{{ ($h['ok'] ?? true) ? '✓' : '✗' }} {{ $h['detail'] }}</span>
                                    @endforeach
                                    @foreach((array) ($site['notes'] ?? []) as $note)
                                        <span style="font-family: 'JetBrains Mono', monospace; color: #c9dcee">{{ $note }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <details style="border-top: 1px solid rgba(127,231,255,.14); padding-top: 6px" @if(empty($site['url']) && empty($site['self'])) hidden @endif>
                                <summary style="cursor: pointer; font-size: 12px; color: #9cc3e0; min-height: 32px; display: flex; align-items: center">▸ 서버 · 연결</summary>
                                <div style="display: flex; flex-direction: column; gap: 3px; padding-top: 4px; font-size: 11px">
                                    @foreach((array) ($site['notes'] ?? []) as $note)
                                        <span style="font-family: 'JetBrains Mono', monospace; color: #c9dcee">{{ $note }}</span>
                                    @endforeach
                                    @if($s['ok'])
                                        <span style="font-family: 'JetBrains Mono', monospace; color: #c9dcee">PHP {{ $srv['php'] ?? '' }} · Laravel {{ $srv['laravel'] ?? '' }} · DB {{ isset($srv['db_mb']) ? number_format($srv['db_mb']).'MB' : '—' }}</span>
                                        @foreach((array) ($d['links']['keys'] ?? []) as $k => $on)
                                            <span style="color: {{ $on ? '#c9dcee' : '#7d8aa3' }}">{{ $on ? '● ' : '○ ' }}{{ ['anthropic' => 'Claude API 키', 'nice' => 'NICE 조회 토큰', 'holiday' => '공휴일 API 키', 'board_sync' => 'board 매입 연동 시크릿', 'erp_peer' => 'ERP 끼리 정산 시크릿'][$k] ?? $k }}</span>
                                        @endforeach
                                        <span style="color: #c9dcee">알림톡 오늘 {{ $d['links']['alimtalk_sent_today'] ?? '—' }}건 · 실패 {{ $d['links']['alimtalk_failed_today'] ?? '—' }} · Claude 오늘 {{ isset($d['links']['claude_cost_today_krw']) ? number_format($d['links']['claude_cost_today_krw']).'원' : '—' }}</span>
                                        @foreach((array) ($d['health'] ?? []) as $h)
                                            <span style="color: {{ ($h['ok'] ?? true) ? '#9cc3e0' : '#ffcf86' }}">{{ ($h['ok'] ?? true) ? '✓' : '✗' }} {{ __('health.item.'.$h['key']) !== 'health.item.'.$h['key'] ? __('health.item.'.$h['key']) : $h['key'] }} — {{ $h['detail'] }}</span>
                                        @endforeach
                                    @endif
                                </div>
                            </details>
                            @if(! empty($site['host']))
                                <a href="https://{{ $site['host'] }}" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; justify-content: center; height: 36px; border-radius: 8px; border: 1px solid {{ $rowColor }}66; background: {{ $rowColor }}14; color: #eaf6ff; text-decoration: none; font-size: 12px">{{ $site['host'] }} 열기 ↗</a>
                            @endif
                            @if(! empty($site['ssh']))
                                <span style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #c9dcee; border: 1px dashed rgba(127,231,255,.35); border-radius: 8px; padding: 8px">{{ $site['ssh'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @endif
            @endforeach
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: stretch">
            {{-- 이상 감지 --}}
            <div style="flex: 1 1 300px; min-width: 0; border: 1px solid rgba(255,120,90,.45); border-radius: 14px; background: rgba(40,14,8,.45); padding: 16px; display: flex; flex-direction: column; gap: 10px" data-ops-anomalies>
                <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px">
                    <span style="font-family: 'Orbitron', sans-serif; font-size: 12px; letter-spacing: 2px; color: #ff9b6a">ANOMALY <span style="font-family: 'Noto Sans KR', sans-serif; letter-spacing: 0; color: #ffc7a8">· 이상 감지 — {{ $siteFilter === '' ? '전체 사이트' : collect($sites)->firstWhere('site.key', $siteFilter)['site']['name'] ?? $siteFilter }}</span></span>
                    @if($siteFilter !== '')
                        <button type="button" wire:click="pickSite('{{ $siteFilter }}')" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid rgba(255,155,106,.45); background: rgba(255,155,106,.08); color: #ffd9c4; font: inherit; font-size: 12px; cursor: pointer">전체 보기</button>
                    @endif
                </div>
                @forelse($shownAnomalies as $a)
                    <div style="display: flex; flex-direction: column; gap: 2px; border-top: 1px solid rgba(255,155,106,.18); padding-top: 8px">
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 11px">
                            <span style="padding: 2px 8px; border-radius: 999px; font-weight: 700; color: #0b0f1a; background: {{ $a['site_kind'] === 'board' ? '#c4a8ff' : ($a['site_kind'] === 'erp' ? '#7fe7ff' : '#e0c27a') }}">{{ $a['site'] }}</span>
                            <span style="color: {{ $lvColor[$a['level']] ?? '#9cc3e0' }}">{{ $lvName[$a['level']] ?? '' }}</span>
                            <span style="color: #a98d80">{{ $a['kind'] }}</span>
                        </div>
                        <span style="font-size: 12px; color: #f3e3da">{{ $a['text'] }}</span>
                    </div>
                @empty
                    <span style="font-size: 12px; color: #3dffaa">이상 없음</span>
                @endforelse
            </div>

                    {{-- 세컨드 브레인 (3단계, jin 11-12) — 개발 PC 의 ops:brain-sync --push 가 올린 목록. 지도는 브라우저에서만 그린다(서버 무관). 끌면 전체가 3D 로 돌고, 구를 누르면 목록 --}}
                    <div style="flex: 2 1 520px; min-width: 0; {{ $panel }} display: flex; flex-direction: column; gap: 12px; box-shadow: 0 0 40px rgba(60,140,255,.15)" data-ops-brain
                         x-data='opsBrain({!! json_encode($brainClusters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) !!})' x-init="init()">
                        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px">
                            <span style="{{ $head }}">SECOND BRAIN <span style="{{ $sub }}">· 설계·결정·교훈 지도 — 끌면 회전 · 구를 누르면 목록{{ $brain ? ' · '.\Carbon\Carbon::parse($brain['generated_at'])->format('m/d H:i').' 기준' : '' }}</span></span>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #8fb0cc">찾기
                                <input type="search" x-model="q" placeholder="미수 공식 · departed · 알림톡 반려" style="height: 36px; width: 230px; max-width: 55vw; border-radius: 10px; border: 1px solid rgba(127,231,255,.35); background: rgba(5,10,20,.8); color: #eaf6ff; padding: 0 10px; font: inherit; font-size: 12px"></label>
                        </div>
                        @if(! $brain)
                            <span style="font-size: 12px; color: #ffcf86">아직 목록이 없습니다 — 개발 PC 에서 <code style="font-family: 'JetBrains Mono', monospace">php artisan ops:brain-sync --push={{ rtrim(config('app.url'), '/') }}</code> 를 돌리면 채워집니다.</span>
                        @else
                            <div x-ref="stage" @pointerdown="down($event)" @pointermove="move($event)" @pointerup="up($event)" @pointerleave="drag = null"
                                 style="position: relative; height: 460px; border-radius: 12px; overflow: hidden; cursor: grab; touch-action: none; background: radial-gradient(circle at 50% 50%, rgba(40,90,180,.25), rgba(3,6,14,.9) 70%)">
                                <canvas x-ref="cv" role="img" aria-label="문서 지도 — 덩어리 7개가 CLAUDE.md 를 중심으로 도는 3D 그림" style="position: absolute; inset: 0; width: 100%; height: 100%"></canvas>
                            </div>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px">
                                <template x-for="c in clusters" :key="c.key">
                                    <button type="button" @click="pick = (pick === c.key ? null : c.key)" :aria-pressed="pick === c.key" :data-brain-chip="c.key"
                                            :style="'height: 36px; padding: 0 10px; border-radius: 999px; cursor: pointer; font: inherit; font-size: 12px; color: #eaf6ff; border: 1px solid ' + c.color + (pick === c.key ? '' : '66') + '; background: ' + c.color + (pick === c.key ? '33' : '10')"
                                            x-text="c.label + ' ' + c.count"></button>
                                </template>
                            </div>
                            <div x-show="pick || q.length > 0" x-cloak style="border: 1px solid rgba(127,231,255,.3); border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 6px; background: rgba(5,10,22,.85); max-height: 320px; overflow-y: auto" data-brain-list>
                                <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px">
                                    <span style="font-size: 13px; font-weight: 700; color: #eaf6ff" x-text="listTitle()"></span>
                                    <button type="button" @click="pick = null; q = ''" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid rgba(127,231,255,.35); background: rgba(127,231,255,.06); color: #c9f6ff; font: inherit; font-size: 12px; cursor: pointer">닫기</button>
                                </div>
                                <template x-for="it in visible()" :key="it.cluster + '|' + it.id">
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; border-top: 1px solid rgba(127,231,255,.12); padding-top: 6px; font-size: 12px">
                                        <span style="font-family: 'JetBrains Mono', monospace; color: #7fe7ff; min-width: 54px" x-text="it.no"></span>
                                        <span style="flex: 1 1 240px; min-width: 0" x-text="it.title"></span>
                                        <span style="color: #7d98b3" x-text="it.meta"></span>
                                        <template x-if="it.hasBody">
                                            <button type="button" @click="$wire.openDoc(it.cluster, it.id)" style="height: 32px; padding: 0 8px; border-radius: 8px; border: 1px solid rgba(127,231,255,.35); background: transparent; color: #7fe7ff; font: inherit; font-size: 12px; cursor: pointer">본문 열기</button>
                                        </template>
                                    </div>
                                </template>
                                <span x-show="visible().length === 0" style="font-size: 12px; color: #8fb0cc">맞는 항목이 없습니다.</span>
                                <span x-show="visible().length >= 200" style="font-size: 11px; color: #8fb0cc">앞 200건만 — 찾기로 좁히세요.</span>
                            </div>
                        @endif
                    </div>
            {{-- 오른쪽 기둥 — 코드 현황 · 외부 연동 · 스킬 (목업 그대로) --}}
            <div style="flex: 1 1 280px; min-width: 0; display: flex; flex-direction: column; gap: 14px">
                <div style="{{ $panel }} display: flex; flex-direction: column; gap: 10px" data-ops-codebase>
                    <span style="{{ $head }}">CODEBASE <span style="{{ $sub }}">· 코드 현황</span></span>
                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px">
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">운영 ERP 버전</span><span style="font-family: 'JetBrains Mono', monospace; font-size: 18px; color: #3dffaa">{{ $codebase['deployed'] ?? '—' }}</span></div>
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">dev HEAD(목록 기준)</span><span style="font-family: 'JetBrains Mono', monospace; font-size: 18px; color: #eaf6ff">{{ $codebase['dev_head'] ?? '—' }}</span></div>
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">가드 테스트 파일</span><span style="font-family: 'Orbitron', sans-serif; font-size: 20px; color: #eaf6ff">{{ number_format($codebase['tests']) }}</span></div>
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">예약 작업(기록된 것)</span><span style="font-family: 'Orbitron', sans-serif; font-size: 20px; color: #eaf6ff">{{ count($routines) }}</span></div>
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">교훈 · 메모리</span><span style="font-family: 'Orbitron', sans-serif; font-size: 20px; color: #b28cff">{{ $codebase['skills'] }} · {{ $codebase['memory'] }}</span></div>
                        <div style="display: flex; flex-direction: column; gap: 2px"><span style="font-size: 11px; color: #8fb0cc">설계·연동·운영·회의</span><span style="font-family: 'Orbitron', sans-serif; font-size: 20px; color: #3dffaa">{{ $codebase['docs'] }}</span></div>
                    </div>
                </div>
                <div style="{{ $panel }} display: flex; flex-direction: column; gap: 8px" data-ops-links>
                    <span style="{{ $head }}">LINKS <span style="{{ $sub }}">· 외부 연동 — ERP 3사 오늘</span></span>
                    <div style="display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 10px; font-size: 12px">
                        <span>알림톡 보냄 · 실패</span><span style="font-family: 'JetBrains Mono', monospace">{{ $linksSum['sent'] }} · <span style="color: {{ $linksSum['failed'] ? '#ff9b7a' : '#d8e6f5' }}">{{ $linksSum['failed'] }}</span></span>
                        <span>Claude API 사용액(ssancar+heyman 같은 키)</span><span style="font-family: 'JetBrains Mono', monospace">{{ number_format($linksSum['claude']) }}원</span>
                        <span>GPU PC Ollama</span><span style="font-family: 'JetBrains Mono', monospace">{{ $linksSum['gpu'] }}</span>
                        <span>공휴일 API 키 만료</span><span style="font-family: 'JetBrains Mono', monospace">2028-08-10</span>
                    </div>
                    @foreach($linksSum['missing'] as $m)
                        <span style="font-size: 11px; color: #ffcf86">{{ $m }}</span>
                    @endforeach
                </div>
                <div style="flex: 1 1 auto; {{ $panel }} display: flex; flex-direction: column; gap: 8px" data-ops-skills>
                    <span style="{{ $head }}">SKILLS <span style="{{ $sub }}">· 스킬</span></span>
                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; font-size: 12px">
                        @foreach([['적재양식 import', 'xlsx → 차량 일괄'], ['컨사이니 import', '바이어·컨사이니'], ['라운드테이블 회의', '6부서 검토'], ['교차 검증', 'Codex·Gemini']] as [$t, $d])
                            <div style="border: 1px solid rgba(127,231,255,.2); border-radius: 10px; padding: 8px; background: rgba(127,231,255,.04)"><b style="color: #eaf6ff">{{ $t }}</b><div style="color: #8fb0cc">{{ $d }}</div></div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- 루틴 — 예약 작업을 한글 묶음·시각·사이트별 마지막 결과로(목업 그대로, jin 11-12 «영어로 돼 있어 뭘 뜻하는지 모르겠다»). 데이터 = OpsRoutines --}}
        <div style="{{ $panel }} display: flex; flex-direction: column; gap: 8px" data-ops-routines>
            <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px">
                <span style="{{ $head }}">ROUTINES <span style="{{ $sub }}">· 예약 작업 — 무엇이 언제 돌고, 사이트별 마지막 결과 (11-12 부터 기록)</span></span>
                <span style="font-size: 11px; color: #8fb0cc">초록 = 성공 · 빨강 = 실패(마우스를 올리면 이유) · 회색 = 아직 기록 없음</span>
            </div>
            <div style="overflow-x: auto">
                <table style="width: 100%; min-width: 760px; border-collapse: collapse; font-size: 12px">
                    <thead><tr style="color: #8fb0cc; text-align: left">
                        <th style="padding: 6px 8px 6px 0; font-weight: 500">작업</th>
                        <th style="padding: 6px 8px; font-weight: 500">시각</th>
                        @foreach($routineTable['columns'] as $col)<th style="padding: 6px 8px; font-weight: 500">{{ $col['name'] }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach($routineTable['rows'] as $r)
                            <tr style="border-top: 1px solid rgba(127,231,255,.12)" data-routine-row="{{ $r['label'] }}">
                                <td style="padding: 7px 8px 7px 0; color: #eaf6ff">{{ $r['label'] }}</td>
                                <td style="padding: 7px 8px; font-family: 'JetBrains Mono', monospace; color: #9cc3e0; white-space: nowrap">{{ $r['when'] }}</td>
                                @foreach($routineTable['columns'] as $col)
                                    @php($c = $r['by'][$col['key']])
                                    <td style="padding: 7px 8px; white-space: nowrap; font-family: 'JetBrains Mono', monospace; color: {{ ['ok' => '#3dffaa', 'failed' => '#ff7a7a', 'running' => '#ffcf86'][$c['state']] ?? '#5f6b80' }}" title="{{ $c['title'] }}">{{ $c['text'] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        @foreach(\App\Services\Ops\OpsRoutines::EXTERNAL as $e)
                            <tr style="border-top: 1px solid rgba(127,231,255,.12); color: #7d98b3">
                                <td style="padding: 7px 8px 7px 0">{{ $e['label'] }}</td>
                                <td style="padding: 7px 8px; font-family: 'JetBrains Mono', monospace; white-space: nowrap">{{ $e['when'] }}</td>
                                <td style="padding: 7px 8px" colspan="{{ max(1, count($routineTable['columns'])) }}">{{ $e['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 본문 창 --}}
        @if($docOpen)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.6)" role="dialog" aria-modal="true" aria-labelledby="ops-doc-title" data-ops-doc>
                <div class="max-h-[90vh] w-full max-w-4xl overflow-y-auto" style="border-radius: 14px; border: 1px solid rgba(127,231,255,.35); background: #0b1222; color: #d8e6f5; padding: 20px; box-shadow: 0 0 40px rgba(60,140,255,.25)">
                    <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 8px">
                        <div style="min-width: 0">
                            <div id="ops-doc-title" style="font-size: 15px; font-weight: 700; color: #eaf6ff">{{ $docOpen['no'] }} {{ $docOpen['title'] }}</div>
                            <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #8fb0cc">{{ $docOpen['path'] }}</div>
                        </div>
                        <button type="button" wire:click="closeDoc" style="height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid rgba(127,231,255,.35); background: rgba(127,231,255,.06); color: #c9f6ff; font: inherit; font-size: 12px; cursor: pointer">닫기</button>
                    </div>
                    <pre style="margin: 12px 0 0; white-space: pre-wrap; word-break: break-word; font-family: 'Noto Sans KR', system-ui, sans-serif; font-size: 13px; line-height: 1.6; color: #d8e6f5">{{ $docOpen['body'] }}</pre>
                </div>
            </div>
        @endif
</div>
    </div>
</div>

    @script
    <script>
    Alpine.data('opsBrain', (clusters) => ({
        clusters, pick: null, q: '', drag: null, moved: 0, yaw: 0.3, pitch: -0.25, vy: 0.12, vp: 0, last: 0, proj: [], raf: 0, pts: [], core: [],
        POS: { skills: [-1.0, -0.45, 0.35], memory: [0.95, -0.40, -0.30], tests: [0.05, -0.95, -0.55], ops: [-0.85, 0.55, -0.55], design: [-0.15, 0.85, 0.75], meet: [0.75, 0.70, 0.45], spec: [1.05, 0.15, 0.85] },
        all() { return this.clusters.flatMap((c) => c.items.map((i) => ({ ...i, cluster: c.key, label: c.label, hasBody: c.key !== 'tests' }))); },
        visible() {
            const q = this.q.trim().toLowerCase();
            let rows = this.all().filter((i) => (!this.pick || i.cluster === this.pick) && (q === '' || (i.title + ' ' + i.no + ' ' + (i.path || '')).toLowerCase().includes(q)));
            return rows.slice(0, 200);
        },
        listTitle() {
            const c = this.clusters.find((x) => x.key === this.pick);
            return (c ? c.label + ' — ' + c.count + '건' : '전체 ' + this.all().length + '건') + (this.q ? ' · 「' + this.q + '」' : '');
        },
        init() {
            const sph = (n, jitter) => { const a = []; for (let i = 0; i < n; i++) { const u = Math.random() * 2 - 1, t = Math.random() * Math.PI * 2, s = Math.sqrt(1 - u * u), rr = jitter ? 0.7 + Math.random() * 0.3 : 1; a.push([s * Math.cos(t) * rr, u * rr, s * Math.sin(t) * rr]); } return a; };
            const max = Math.max(1, ...this.clusters.map((c) => c.count));
            this.geo = this.clusters.map((c, i) => ({ key: c.key, label: c.label, color: c.color, count: c.count, p: this.POS[c.key] || [Math.cos(i) , Math.sin(i), 0], r: 0.17 + 0.17 * Math.sqrt(c.count / max), pts: sph(110 + Math.round(190 * Math.sqrt(c.count / max)), false) }));
            this.core = sph(420, true);
            if (!this.$refs.cv) return;
            const step = (ts) => { if (!document.contains(this.$refs.cv)) return; if (!document.hidden) this.draw(ts || 0); this.raf = requestAnimationFrame(step); };
            this.raf = requestAnimationFrame(step);
        },
        down(e) { this.drag = { x: e.clientX, y: e.clientY, at: performance.now() }; this.moved = 0; this.vy = 0; this.vp = 0; },
        move(e) { if (!this.drag) return; const dx = e.clientX - this.drag.x, dy = e.clientY - this.drag.y, now = performance.now(), d = Math.max(0.008, (now - this.drag.at) / 1000); this.moved += Math.abs(dx) + Math.abs(dy); this.yaw += dx * 0.008; this.pitch = Math.max(-1.2, Math.min(1.2, this.pitch + dy * 0.008)); this.vy = dx * 0.008 / d; this.vp = dy * 0.008 / d; this.drag = { x: e.clientX, y: e.clientY, at: now }; },
        up(e) {
            if (this.drag && this.moved < 6 && this.$refs.cv) { const r = this.$refs.cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top; let best = null; this.proj.forEach((p) => { const d = Math.hypot(p.x - x, p.y - y); if (d <= p.r && (!best || p.z > best.z)) best = p; }); if (best) this.pick = (this.pick === best.k ? null : best.k); }
            this.drag = null;
        },
        draw(ts) {
            const cv = this.$refs.cv; const box = cv.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
            const W = Math.max(1, Math.round(box.width)), H = Math.max(1, Math.round(box.height));
            if (cv.width !== W * dpr || cv.height !== H * dpr) { cv.width = W * dpr; cv.height = H * dpr; }
            const g = cv.getContext('2d'); g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, W, H);
            const t = ts / 1000, dt = this.last ? Math.min(0.05, t - this.last) : 0; this.last = t;
            if (!this.drag) { this.yaw += this.vy * dt; this.pitch += this.vp * dt; this.vy = this.vy * 0.97 + 0.12 * 0.03; this.vp *= 0.95; this.pitch = Math.max(-1.2, Math.min(1.2, this.pitch)); }
            const cy0 = Math.cos(this.yaw), sy0 = Math.sin(this.yaw), cp = Math.cos(this.pitch), sp = Math.sin(this.pitch);
            const world = (v) => { const x1 = v[0] * cy0 + v[2] * sy0, z1 = -v[0] * sy0 + v[2] * cy0; return [x1, v[1] * cp - z1 * sp, v[1] * sp + z1 * cp]; };
            const S = Math.min(W, H) * 0.36, cx = W / 2, cy = H / 2, D = 3.2;
            const toScreen = (v) => { const f = D / (D - v[2]); return [cx + v[0] * S * f, cy + v[1] * S * f, f, v[2]]; };
            const spin = (p, a) => { const c = Math.cos(a), s = Math.sin(a); return [p[0] * c + p[2] * s, p[1], -p[0] * s + p[2] * c]; };
            const centers = this.geo.map((c) => toScreen(world(c.p)));
            centers.forEach((q, i) => { const c = this.geo[i]; const grad = g.createLinearGradient(cx, cy, q[0], q[1]); grad.addColorStop(0, 'rgba(127,231,255,0.35)'); grad.addColorStop(1, c.color + '66'); g.strokeStyle = grad; g.lineWidth = 1; g.beginPath(); g.moveTo(cx, cy); g.lineTo(q[0], q[1]); g.stroke(); const k = (t * 0.6 + i * 0.37) % 1; g.fillStyle = c.color; g.beginPath(); g.arc(cx + (q[0] - cx) * k, cy + (q[1] - cy) * k, 2.2, 0, Math.PI * 2); g.fill(); });
            const order = [{ z: 0, core: true }].concat(centers.map((q, i) => ({ z: q[3], i }))); order.sort((a, b) => a.z - b.z);
            this.proj = [];
            order.forEach((o) => {
                if (o.core) {
                    const R0 = S * 0.42; const halo = g.createRadialGradient(cx, cy, 0, cx, cy, R0 * 1.7); halo.addColorStop(0, 'rgba(127,231,255,0.28)'); halo.addColorStop(1, 'rgba(127,231,255,0)'); g.fillStyle = halo; g.beginPath(); g.arc(cx, cy, R0 * 1.7, 0, Math.PI * 2); g.fill();
                    this.core.forEach((p) => { const w = world(spin(p, t * 0.25)); const q = toScreen([w[0] * 0.42, w[1] * 0.42, w[2] * 0.42]); g.fillStyle = 'rgba(160,236,255,' + (0.25 + 0.6 * (w[2] + 1) / 2).toFixed(3) + ')'; g.fillRect(q[0], q[1], 1.6 * q[2], 1.6 * q[2]); });
                    g.font = '700 13px Orbitron, sans-serif'; g.textAlign = 'center'; g.fillStyle = '#ffffff'; g.shadowColor = '#7fe7ff'; g.shadowBlur = 12; g.fillText('CLAUDE.md', cx, cy + 4); g.shadowBlur = 0;
                    return;
                }
                const c = this.geo[o.i], hl = this.pick === c.key, q0 = centers[o.i], rad = c.r * S * q0[2];
                const gl = g.createRadialGradient(q0[0], q0[1], 0, q0[0], q0[1], rad * 1.6); gl.addColorStop(0, c.color + (hl ? '70' : '30')); gl.addColorStop(1, c.color + '00'); g.fillStyle = gl; g.beginPath(); g.arc(q0[0], q0[1], rad * 1.6, 0, Math.PI * 2); g.fill();
                g.fillStyle = c.color;
                c.pts.forEach((p) => { const sp2 = spin(p, t * (0.5 + o.i * 0.06)); const w = world([c.p[0] + sp2[0] * c.r, c.p[1] + sp2[1] * c.r, c.p[2] + sp2[2] * c.r]); const q = toScreen(w); g.globalAlpha = Math.max(0.12, Math.min(1, 0.35 + w[2] * 0.45)); g.fillRect(q[0], q[1], 1.8 * q[2], 1.8 * q[2]); });
                g.globalAlpha = 1;
                if (hl) { g.strokeStyle = c.color; g.lineWidth = 1.5; g.beginPath(); g.arc(q0[0], q0[1], rad + 10 + Math.sin(t * 3) * 3, 0, Math.PI * 2); g.stroke(); }
                g.textAlign = 'center'; g.shadowColor = '#000'; g.shadowBlur = 6;
                g.font = '700 12px "Noto Sans KR", sans-serif'; g.fillStyle = '#eaf6ff'; g.fillText(c.label, q0[0], q0[1] - 2);
                g.font = '700 16px Orbitron, sans-serif'; g.fillStyle = c.color; g.fillText(String(c.count), q0[0], q0[1] + 16); g.shadowBlur = 0;
                this.proj.push({ k: c.key, x: q0[0], y: q0[1], r: Math.max(rad, 28), z: q0[3] });
            });
        }
    }));
    </script>
    @endscript
