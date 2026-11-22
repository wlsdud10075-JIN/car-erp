// 업무 가이드 「감사 로그 — 변경 기록 찾기」(audit-log) 촬영 — 예시 계정(manage). 읽기만 한다(저장 없음).
const { chromium } = require(process.env.PW_CORE || 'C:/Users/User/AppData/Local/Temp/claude/a6613a99d460c33c/844bed06-9f0e-4a53-a3f6-2216dbe9d57d/scratchpad/pw/node_modules/playwright-core');
const out = __dirname + '/../../../resources/guides/audit-log/';
const B = 'http://localhost:8001';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 900 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());   // 카톡 에이전트 차단
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.addInitScript(() => {   // 오른쪽 아래 알람 팝업이 화면을 가린다 — 촬영 브라우저에서만 숨긴다
    const css = document.createElement('style');
    css.textContent = '[data-alarm-center-popup],[x-data*="alarmCenter"] .fixed{display:none!important}';
    document.addEventListener('DOMContentLoaded', () => document.head.appendChild(css));
  });
  await p.goto(B + '/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  const hideAlarm = async () => { await p.evaluate(() => { for (const el of document.querySelectorAll('*')) { if (el.children.length === 0 && /통관서류 알람/.test(el.textContent || '')) { let a = el; for (let i = 0; i < 8 && a; i++) { if (getComputedStyle(a).position === 'fixed') { a.style.display = 'none'; break; } a = a.parentElement; } } } }); };
  const shot = async (name, h = 620) => { await hideAlarm(); await p.screenshot({ path: out + name, clip: { x: 220, y: 44, width: 1380, height: h } }); console.log('shot', name); };

  // 1) 구역 칩 + 목록
  await p.goto(B + '/admin/audit-logs', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1000);
  await shot('01.png');

  // 2) 「판매·입금」 → 컬럼 칸을 열면 그 구역 칸만
  await p.locator('[data-audit-area="sale"]').click();
  await p.waitForTimeout(2200);
  const col = p.locator('input[placeholder*="컬럼"]').first();
  await col.click();
  await p.waitForTimeout(700);
  await shot('02.png');
  await p.keyboard.press('Escape');

  // 3) 액션 칸에 「확정」을 치고 고른 뒤 [조회]
  await p.locator('[data-audit-area="all"]').click();
  await p.waitForTimeout(1800);
  const act = p.locator('input[placeholder*="액션"]').first();
  await act.click();
  await act.fill('확정');
  await p.waitForTimeout(700);
  await shot('03.png', 460);
  await p.locator('button:visible', { hasText: '확정' }).first().click();
  await p.locator('button.btn-search').first().click();
  await p.waitForTimeout(2200);
  await shot('04.png');

  // 5) 차량 창의 「이 차 변경 기록」
  await p.goto(B + '/erp/vehicles?search=' + encodeURIComponent('00가1131'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('tr', { hasText: '00가1131' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await hideAlarm();
  const link = p.locator('[data-vehicle-history-link]').first();
  const box = await link.boundingBox();
  await p.screenshot({ path: out + '05.png', clip: { x: Math.max(0, box.x - 420), y: Math.max(0, box.y - 40), width: 900, height: 200 } });
  console.log('shot 05.png');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 600)); process.exit(1); });
