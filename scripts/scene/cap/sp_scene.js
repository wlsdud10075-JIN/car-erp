const { chromium } = require('playwright-core');
const raw = __dirname + '/sp/'; require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  const hide = () => p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide();
  await p.locator('button:has-text("📩 대금확인")').click(); await p.waitForTimeout(2500); await hide();
  await p.screenshot({ path: raw + 's1-list.png', fullPage: true });
  console.log((await p.locator('table').first().innerText()).slice(0, 800));
  const open = async (plate) => { await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(plate), { waitUntil: 'networkidle' }); await p.waitForTimeout(1500);
    await p.locator('tr', { hasText: plate }).first().locator('td').nth(1).click(); await p.waitForTimeout(3000); await hide(); };
  await open('00가1141');
  await p.screenshot({ path: raw + 's2-panel.png', clip: { ...panel, height: 420 } });
  await p.locator('button:text-is("판매")').first().click(); await p.waitForTimeout(800);
  const t = p.getByText(/입금 현황|입금현황/).first(); if (await t.count()) { await t.scrollIntoViewIfNeeded(); }
  await p.waitForTimeout(500);
  await p.screenshot({ path: raw + 's3-sale.png', clip: { ...panel, height: 1000 } });
  await p.getByRole('button', { name: '입금 확인' }).first().click(); await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 's4-confirmed.png', clip: { ...panel, height: 300 } });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
