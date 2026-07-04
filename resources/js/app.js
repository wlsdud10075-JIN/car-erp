// ──────────────────────────────────────────────────────────────────────────
// 차량 미납 게이지 (행 배경 + 호버 툴팁)
//
// 사용: <tr data-ratio="0.42" data-unpaid="4200" data-total="10000" data-currency="USD">
//      Blade에서 위 속성만 박아두면 이 스크립트가 일괄 처리.
//
// ratio 정의: sale_unpaid_amount / sale_total_amount (KPI·채권관리와 동일)
//   ratio === 0   완납  → 게이지 미표시, 호버 시 ✓ 완납 툴팁
//   ratio  > 0    미납  → 게이지 width = ratio×100%, 호버 시 미납 툴팁
//   data-ratio 없음     → 게이지 자체 미적용 (sale_total_amount=0인 차량)
// ──────────────────────────────────────────────────────────────────────────

const BASE_ALPHA = 0.4;
const HOVER_ALPHA = 0.6;

function ratioToColor(r, alpha) {
    let hue;
    if (r <= 0.5) {
        hue = 120 - (r / 0.5) * 70; // 120(초록) → 50(노랑)
    } else {
        hue = 50 - ((r - 0.5) / 0.5) * 50; // 50(노랑) → 0(빨강)
    }
    return `hsla(${hue}, 65%, 50%, ${alpha})`;
}

function formatNumber(n) {
    const num = Number(n) || 0;
    return num.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

// <tr> 자체에 행 전체 폭 기준 단일 gradient 적용.
//   - <td>마다 적용하면 각 셀 폭 기준으로 stop 재계산 → 칸 사이 끊김 발생
//   - <tr> 1회 적용이면 행 전체가 단일 좌표계 → 끊김 없이 연속
function applyGauge(tr) {
    const ratio = parseFloat(tr.dataset.ratio);
    if (Number.isNaN(ratio)) return;

    if (ratio === 0) {
        tr.style.backgroundImage = '';
        return;
    }

    const pct = (ratio * 100).toFixed(2) + '%';
    const baseColor = ratioToColor(ratio, BASE_ALPHA);
    tr.style.backgroundImage = `linear-gradient(to right, ${baseColor} 0%, ${baseColor} ${pct}, transparent ${pct}, transparent 100%)`;
    tr.dataset.gaugePct = pct;
}

function applyHoverColor(tr, alpha) {
    const ratio = parseFloat(tr.dataset.ratio);
    if (Number.isNaN(ratio) || ratio === 0) return;
    const pct = tr.dataset.gaugePct;
    if (!pct) return;
    const color = ratioToColor(ratio, alpha);
    tr.style.backgroundImage = `linear-gradient(to right, ${color} 0%, ${color} ${pct}, transparent ${pct}, transparent 100%)`;
}

let tooltipEl = null;

function ensureTooltip() {
    // wire:navigate 가 <body> 를 교체하면 body 에 append 했던 툴팁이 제거된다.
    // 변수는 detached 옛 요소를 가리켜 툴팁이 안 보이므로, 분리됐으면 재생성.
    if (tooltipEl && tooltipEl.isConnected) return tooltipEl;
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'vehicle-gauge-tooltip';
    Object.assign(tooltipEl.style, {
        position: 'fixed',
        zIndex: '60',
        padding: '4px 10px',
        fontSize: '11px',
        fontWeight: '500',
        color: '#fff',
        borderRadius: '4px',
        pointerEvents: 'none',
        whiteSpace: 'nowrap',
        boxShadow: '0 2px 8px rgba(0,0,0,0.15)',
        opacity: '0',
        transition: 'opacity 0.12s ease',
    });
    document.body.appendChild(tooltipEl);
    return tooltipEl;
}

function showTooltip(tr) {
    const ratio = parseFloat(tr.dataset.ratio);
    if (Number.isNaN(ratio)) return;
    const el = ensureTooltip();

    if (ratio === 0) {
        el.textContent = '✓ 완납';
        el.style.background = '#1D9E75';
    } else {
        const unpaid = formatNumber(tr.dataset.unpaid);
        const total = formatNumber(tr.dataset.total);
        const currency = tr.dataset.currency || '';
        const pct = (ratio * 100).toFixed(1);
        el.textContent = `미납 ${unpaid} / ${total} ${currency} (${pct}%)`;
        el.style.background = '#2C2C2A';
    }

    const rect = tr.getBoundingClientRect();
    let leftPx;
    if (ratio === 0) {
        leftPx = rect.left + rect.width / 2;
    } else {
        leftPx = rect.left + rect.width * (ratio / 2);
    }
    el.style.left = `${leftPx}px`;
    el.style.top = `${rect.top - 28}px`;
    el.style.transform = 'translateX(-50%)';
    el.style.opacity = '1';
}

function hideTooltip() {
    if (tooltipEl) tooltipEl.style.opacity = '0';
}

// 게이지 배경(inline style)은 morph/navigate가 reset 할 수 있어 매번 재적용.
function applyAllGauges(root = document) {
    root.querySelectorAll('tr[data-ratio]').forEach(applyGauge);
}

// 호버는 document 이벤트 위임(delegation) — 1회만 바인딩.
//   wire:navigate 가 페이지를 캐시·복원할 때, 행마다 addEventListener 로 붙인 리스너는
//   유실되는데 dataset 가드(gaugeBound)는 복원돼 남아 재바인딩이 스킵 → hover 죽음.
//   document 는 navigate 로 교체되지 않으므로 위임 리스너는 유지되고, 페이지네이션·morph 로
//   행이 새로 생겨도 자동 적용된다. (mouseenter/leave 는 버블 안 하므로 mouseover/out 사용)
let gaugeHoveredTr = null;
let gaugeDelegationBound = false;
function bindGaugeHoverDelegation() {
    if (gaugeDelegationBound) return;
    gaugeDelegationBound = true;

    document.addEventListener('mouseover', (e) => {
        const tr = e.target.closest ? e.target.closest('tr[data-ratio]') : null;
        if (tr === gaugeHoveredTr) return;
        if (gaugeHoveredTr) {
            applyHoverColor(gaugeHoveredTr, BASE_ALPHA);
            hideTooltip();
        }
        gaugeHoveredTr = tr;
        if (tr) {
            applyHoverColor(tr, HOVER_ALPHA);
            showTooltip(tr);
        }
    });
}

function initVehicleGauge() {
    gaugeHoveredTr = null;   // 이전 페이지의 detached 행 참조 정리
    applyAllGauges();
    bindGaugeHoverDelegation();
}

document.addEventListener('DOMContentLoaded', () => initVehicleGauge());

// Livewire 네비게이션·morph 후 DOM 갱신 시 배경 재적용 (hover 는 위임이라 재바인딩 불필요)
document.addEventListener('livewire:navigated', () => initVehicleGauge());
if (window.Livewire) {
    window.Livewire.hook('morph.updated', () => applyAllGauges());
}

// ──────────────────────────────────────────────────────────────────────────
// UX #4·5 (2026-05-20) — 한국 13개 은행 계좌 mask + RRN mask 헬퍼.
//
// Alpine.store('koreanBanks') 로 전역 노출 — 차량 편집 매입처 계좌 / RRN input 에서 사용.
//   $store.koreanBanks.applyMask(bankName, value) → 은행별 mask 적용 후 반환
//   $store.koreanBanks.patterns → 13개 은행 데이터 (datalist 자동완성용)
// ──────────────────────────────────────────────────────────────────────────

function applyDashPattern(value, pattern) {
    const digits = value.replace(/\D/g, '');
    if (!pattern || !pattern.length) return digits;
    let out = '';
    let pos = 0;
    for (const len of pattern) {
        if (pos >= digits.length) break;
        if (out !== '') out += '-';
        out += digits.substring(pos, pos + len);
        pos += len;
    }
    // 패턴 자릿수 초과분은 버리지 않고 뒤에 이어붙임 — 법인계좌 등 표준 형식과 자릿수가
    // 다른 계좌가 잘려서 "다 기입 안 됨" 되는 것 방지 (신한 3-3-6 등 개인계좌 기준 패턴).
    if (pos < digits.length) {
        out += (out !== '' ? '-' : '') + digits.substring(pos);
    }
    return out;
}

document.addEventListener('alpine:init', () => {
    Alpine.store('koreanBanks', {
        // 주요 한국 은행 13개 + 표준 mask 패턴 (사용자 메모리 2026-05-20)
        patterns: {
            '국민은행': [6, 2, 6],
            '신한은행': [3, 3, 6],
            '우리은행': [4, 3, 6],
            '하나은행': [3, 6, 5],
            '농협': [3, 4, 4, 2],
            'IBK기업은행': [3, 6, 2, 3],
            '우체국': [3, 6, 3],
            '카카오뱅크': [4, 2, 7],
            '토스뱅크': [4, 4, 4],
            '새마을금고': [4, 2, 7],
            '부산은행': [3, 2, 6, 1],
            'SC제일은행': [3, 2, 6],
            '시티은행': [3, 6, 3],
        },
        names() {
            return Object.keys(this.patterns);
        },
        applyMask(bankName, value) {
            return applyDashPattern(value, this.patterns[bankName] || null);
        },
    });

    // RRN mask helper — 6자리 + 7자리 자동 hyphen (000000-0000000)
    Alpine.store('rrnMask', {
        apply(value) {
            const digits = value.replace(/\D/g, '').slice(0, 13);
            return digits.length > 6 ? digits.slice(0, 6) + '-' + digits.slice(6) : digits;
        },
    });

    // 2026-05-21 — 한국 전화번호 mask (4 패턴).
    //   휴대폰   010-xxxx-xxxx  / 011~019-xxx-xxxx · xxxx-xxxx
    //   서울    02-xxx-xxxx · 02-xxxx-xxxx
    //   지방    0xx-xxx-xxxx · 0xx-xxxx-xxxx
    //   대표    1588-xxxx (1[5-9]xx 패턴)
    Alpine.store('phoneMask', {
        apply(value) {
            const d = (value || '').replace(/\D/g, '');
            if (d.length === 0) return '';

            // 02 (서울)
            if (d.startsWith('02')) {
                if (d.length <= 2) return d;
                if (d.length <= 5) return d.slice(0, 2) + '-' + d.slice(2);
                if (d.length <= 9) return d.slice(0, 2) + '-' + d.slice(2, 5) + '-' + d.slice(5);
                return d.slice(0, 2) + '-' + d.slice(2, 6) + '-' + d.slice(6, 10);
            }

            // 1[5-9]xx 대표번호 (1588, 1644 등 8자리)
            if (/^1[5-9]/.test(d) && d.length <= 8) {
                if (d.length <= 4) return d;
                return d.slice(0, 4) + '-' + d.slice(4, 8);
            }

            // 010, 011~019, 031~064, 070, 080 등 3자리 prefix
            if (d.length <= 3) return d;
            if (d.length <= 6) return d.slice(0, 3) + '-' + d.slice(3);
            if (d.length <= 10) return d.slice(0, 3) + '-' + d.slice(3, 6) + '-' + d.slice(6);
            return d.slice(0, 3) + '-' + d.slice(3, 7) + '-' + d.slice(7, 11);
        },
    });
});
