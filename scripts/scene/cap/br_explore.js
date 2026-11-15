const { chromium } = require('playwright-core');
const raw = __dirname + '/br/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/alarms', { waitUntil: 'networkidle', timeout: 120000 }); await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'a0-alarms.png', fullPage: true });
  console.log('ALARMS:', (await p.locator('main').innerText()).slice(0, 1500));
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle', timeout: 120000 }); await p.waitForTimeout(2500);
  const chips = await p.locator('button:has-text("📩")').allInnerTexts(); console.log('CHIPS:', chips.join(' | '));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
