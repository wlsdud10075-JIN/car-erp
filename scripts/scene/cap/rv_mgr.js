const { chromium } = require('playwright-core');
const raw = __dirname + '/rv/';
const PLATE = '00가1131';
(async () => {
  // 로컬 전용 — 예시 차의 채권담당 지정을 비워 「영업담당자와 같게」 상태로
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
  await p.getByRole('button', { name: '조회', exact: true }).click();
  await p.waitForTimeout(2000);
  console.log('row:', (await p.locator('tr', { hasText: PLATE }).first().innerText()).replace(/\s+/g, ' '));
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'm1-panel.png' });
  await p.locator('select[wire\\:model="managerIdInput"]').selectOption({ label: '재무담당 (user)' });
  await p.getByRole('button', { name: '지정', exact: true }).click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'm2-assigned.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 300)); process.exit(1); });
