<?php

use App\Services\Ops\OpsHub;
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

        return [
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
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 10px; align-items: start">
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
                                    <span style="font-size: 10px; letter-spacing: 1px; color: {{ $rowColor }}">{{ $rowLabel }}</span> {{ $site['name'] }}
                                </span>
                                @if($s['ok'])
                                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #8fb0cc">{{ $srv['version'] ?? '—' }} · 대기 마이그 {{ $srv['pending_migrations'] ?? '—' }} · 오류 오늘 {{ $srv['errors_today'] ?? '—' }} · 디스크 {{ isset($srv['disk_used_pct']) ? $srv['disk_used_pct'].'%' : '—' }}</span>
                                    <span style="font-size: 12px; color: {{ $mine ? '#ffb38a' : '#3dffaa' }}">{{ $mine ? '이상 '.count($mine).'건' : '이상 없음' }}</span>
                                @else
                                    <span style="font-size: 12px; color: #9aa6bd">{{ $s['error'] }}</span>
                                @endif
                            </button>
                            <details style="border-top: 1px solid rgba(127,231,255,.14); padding-top: 6px">
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
            <div style="flex: 1 1 380px; min-width: 0; border: 1px solid rgba(255,120,90,.45); border-radius: 14px; background: rgba(40,14,8,.45); padding: 16px; display: flex; flex-direction: column; gap: 10px" data-ops-anomalies>
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

            {{-- 예약 작업 --}}
            <div style="flex: 2 1 560px; min-width: 0; {{ $panel }} display: flex; flex-direction: column; gap: 8px" data-ops-routines>
                <span style="{{ $head }}">ROUTINES <span style="{{ $sub }}">· 예약 작업 — 사이트별 마지막 실행 (11-12 부터 기록)</span></span>
                @if($routines === [])
                    <span style="font-size: 12px; color: #8fb0cc">아직 기록이 없습니다 — 다음 정기 작업부터 쌓입니다.</span>
                @else
                    <div style="overflow-x: auto">
                        <table style="width: 100%; min-width: 620px; border-collapse: collapse; font-size: 12px">
                            <thead><tr style="color: #8fb0cc; text-align: left">
                                <th style="padding: 6px 6px 6px 0; font-weight: 500">작업</th>
                                @foreach($erpSites as $s)<th style="padding: 6px; font-weight: 500">{{ $s['site']['name'] }}</th>@endforeach
                            </tr></thead>
                            <tbody style="font-family: 'JetBrains Mono', monospace">
                                @foreach($routines as $cmd => $r)
                                    <tr style="border-top: 1px solid rgba(127,231,255,.12)">
                                        <td style="padding: 6px 6px 6px 0">{{ $cmd }} <span style="color: #7d98b3">{{ $r['expression'] }}</span></td>
                                        @foreach($erpSites as $s)
                                            @php $x = $r['by'][$s['site']['key']] ?? null; @endphp
                                            <td style="padding: 6px; color: {{ ! $x ? '#5f6b80' : ($x['status'] === 'failed' ? '#ff7a7a' : ($x['status'] === 'running' ? '#ffcf86' : '#3dffaa')) }}" title="{{ $x['error'] ?? '' }}">
                                                {{ $x ? ($x['status'] === 'failed' ? '실패 ' : ($x['status'] === 'running' ? '도는 중 ' : '')).$fmtAt($x['started_at']) : '—' }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div style="{{ $panel }} display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px">
            <span style="{{ $head }}">SECOND BRAIN <span style="{{ $sub }}">· 설계·결정·교훈 지도 — 3단계에서 이 자리에 들어옵니다</span></span>
            <a href="https://claude.ai/artifact/MSFdABnHjthGcxcP5Wq46u" target="_blank" rel="noopener" style="font-size: 12px">목업 보기 ↗</a>
        </div>
    </div>
</div>
