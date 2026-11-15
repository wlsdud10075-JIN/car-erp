const { chromium } = require('playwright-core');
const raw = __dirname + '/kk/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/kakao', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(3000);
  await p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.screenshot({ path: raw + 'k0-full.png', fullPage: true });
  const main = p.locator('main').first();
  console.log((await main.innerText()).slice(0, 3000));
  console.log('buttons:', (await p.locator('main button').allInnerTexts()).slice(0, 60).join(' | '));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
