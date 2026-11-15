const { chromium } = require('playwright-core');
const raw = __dirname + '/sr/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/shipping-requests', { waitUntil: 'networkidle' }); await p.waitForTimeout(2500);
  await p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.screenshot({ path: raw + 'x0.png', fullPage: true });
  console.log((await p.locator('body').innerText()).slice(0, 3000));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
