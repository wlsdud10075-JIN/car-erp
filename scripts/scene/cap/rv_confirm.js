const { chromium } = require('playwright-core');
const raw = __dirname + '/rv/';
const PLATE = '00가1131';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await p.goto('http://localhost:8001/erp/transfers?tabType=sale_payment&search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  const row = p.locator('tr', { hasText: PLATE }).first();
  console.log('row:', (await row.innerText()).replace(/\s+/g, ' '));
  await p.screenshot({ path: raw + 'c1-transfers.png', fullPage: true });
  await row.locator('button', { hasText: '재무 처리 완료' }).first().click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'c2-modal.png' });
  const btn = p.locator('button[wire\\:click="confirmPayment"]').first();
  console.log('confirm btn:', await btn.innerText());
  await btn.click();
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'c3-after.png' });

  await p.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
  await p.getByRole('button', { name: '조회', exact: true }).click();
  await p.waitForTimeout(2000);
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'c4-panel.png' });
  const t = await p.locator('body').innerText();
  const m = t.match(/미납금\s*\n\s*USD [\d,]+[^\n]*/);
  console.log('panel 미납:', m ? m[0].replace(/\s+/g, ' ') : '(없음)');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
