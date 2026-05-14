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
    if (tooltipEl) return tooltipEl;
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

function initVehicleGauge(root = document) {
    const rows = root.querySelectorAll('tr[data-ratio]');
    rows.forEach((tr) => {
        // 게이지는 매번 재적용 (Livewire morph가 inline style을 reset할 수 있음).
        applyGauge(tr);

        // 이벤트 리스너는 1번만.
        if (tr.dataset.gaugeBound === '1') return;
        tr.dataset.gaugeBound = '1';

        tr.addEventListener('mouseenter', () => {
            applyHoverColor(tr, HOVER_ALPHA);
            showTooltip(tr);
        });
        tr.addEventListener('mouseleave', () => {
            applyHoverColor(tr, BASE_ALPHA);
            hideTooltip();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initVehicleGauge());

// Livewire 네비게이션·morph 후 DOM 갱신 시 재초기화
document.addEventListener('livewire:navigated', () => initVehicleGauge());
if (window.Livewire) {
    window.Livewire.hook('morph.updated', () => initVehicleGauge());
}
