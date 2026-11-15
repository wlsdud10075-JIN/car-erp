const { chromium } = require('playwright-core');
const raw = __dirname + '/doc/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5, acceptDownloads: true });
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 한 대 — 서류 탭
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1113'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('tr', { hasText: '00가1113' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'docs'"]`);
  await p.waitForTimeout(1000);
  await p.screenshot({ path: raw + 'd1-tab.png', clip: panel });
  const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), p.locator('a', { hasText: 'Proforma Invoice' }).first().click()]);
  console.log('download 1대:', dl.suggestedFilename());
  // 통관 SET (차대번호 없는 차면 어떻게 되나)
  const cs = p.locator('a', { hasText: '통관 SET (Ver.1)' }).first();
  console.log('clearance href:', await cs.getAttribute('href'), 'class:', (await cs.getAttribute('class') || '').slice(0, 80));
  const [dl2] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }).catch(() => null), cs.click()]);
  await p.waitForTimeout(1500);
  console.log('clearance download:', dl2 ? dl2.suggestedFilename() : '(없음)');
  console.log('msg:', (await p.locator('body').innerText()).match(/[^\n]*(차대번호|만들 수 없|비어)[^\n]*/)?.[0]);
  await p.screenshot({ path: raw + 'd2-after-clearance.png' });
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1500);

  // ② 여러 대 — 목록 체크박스
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가11'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  for (const pl of ['00가1131', '00가1141']) await p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]').check();
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'd3-bar.png' });
  const bar = await p.locator('body').innerText();
  console.log('bar:', (bar.match(/[^\n]*선택[^\n]*/g) || []).slice(0, 4));
  const [dl3] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }).catch(() => null), p.locator('a', { hasText: /컨테이너 Invoice/ }).first().click()]);
  console.log('download 여러대:', dl3 ? dl3.suggestedFilename() : '(없음)');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
