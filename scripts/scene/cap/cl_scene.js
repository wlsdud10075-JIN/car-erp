const { chromium } = require('playwright-core');
const raw = __dirname + '/cl/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/buyers', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000);
  await p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.locator('tr', { hasText: 'GUIDE SAMPLE TRADING' }).first().locator('td').first().click(); await p.waitForTimeout(2500);
  await p.locator('button', { hasText: '클레임' }).last().click(); await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'c1.png', fullPage: false });
  const box = await p.locator('[data-buyer-claims]').boundingBox(); console.log(JSON.stringify(box));
  console.log((await p.locator('[data-buyer-claims]').innerText()).slice(0, 1200));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
