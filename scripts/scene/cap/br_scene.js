const { chromium } = require('playwright-core');
const raw = __dirname + '/br/';
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  const hide = () => p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  const login = async (email) => {
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', email); await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  };
  // 관리 — 알림 화면
  await login('manage@car-erp.test');
  await p.goto('http://localhost:8001/erp/alarms', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide();
  await p.screenshot({ path: raw + 'm1-alarms.png', fullPage: true });
  await p.context().clearCookies();
  // 재무 — 차량관리 칩
  await login('finance@car-erp.test');
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide();
  const chip = p.locator('button:has-text("📩")');
  console.log('CHIPS:', (await chip.allInnerTexts()).join(' | '));
  const bb = await chip.first().boundingBox();
  await p.screenshot({ path: raw + 'f1-chips.png', fullPage: true });
  console.log('chipbox', JSON.stringify(bb));
  await p.locator('button:has-text("📩 매입잔금")').click(); await p.waitForTimeout(2500); await hide();
  await p.screenshot({ path: raw + 'f2-filtered.png', fullPage: true });
  // 패널 — 11가1001
  await p.locator('tr', { hasText: '11가1001' }).first().locator('td').nth(1).click(); await p.waitForTimeout(3000); await hide();
  await p.screenshot({ path: raw + 'f3-panel.png', clip: { ...panel, height: 420 } });
  // 00가1131 계약금 → 거절
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1131'), { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: '00가1131' }).first().locator('td').nth(1).click(); await p.waitForTimeout(3000); await hide();
  await p.screenshot({ path: raw + 'f4-deposit.png', clip: { ...panel, height: 420 } });
  await p.getByRole('button', { name: '거절', exact: true }).first().click(); await p.waitForTimeout(1500);
  await p.getByPlaceholder('예: 바이어 입금이').fill('계약서 사본이 아직 안 와서 송금 보류 — 받으면 다시 요청해 주세요');
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 'f5-reject.png' });
  await p.getByRole('button', { name: '거절 회신' }).click(); await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'f6-rejected.png', clip: { ...panel, height: 420 } });
  console.log('body has 거절했습니다:', (await p.locator('body').innerText()).includes('거절했습니다'));
  // 11가1001 매입잔금 → 입금 확인
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('11가1001'), { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: '11가1001' }).first().locator('td').nth(1).click(); await p.waitForTimeout(3000); await hide();
  await p.getByRole('button', { name: '입금 확인' }).first().click(); await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'f7-confirmed.png', clip: { ...panel, height: 420 } });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
