const { chromium } = require('playwright-core');
const raw = __dirname + '/bk/';
require('fs').mkdirSync(raw, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(3000);
  const card = p.locator('[data-cash-card]').first();
  console.log('card:', await card.count());
  if (await card.count()) {
    await card.scrollIntoViewIfNeeded();
    await card.screenshot({ path: raw + 'k0-card.png' });
    console.log((await card.innerText()).slice(0, 1500));
  }
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
