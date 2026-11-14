const { chromium } = require('playwright-core');
const out = 'C:/xampp/htdocs/car-erp/resources/guides/vehicle-register/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1113'), { waitUntil: 'networkidle' });
  await p.waitForTimeout(800);
  const row = p.locator('tr', { hasText: '00가1113' }).first();
  const head = p.locator('thead').first();
  const hb = await head.boundingBox(), rb = await row.boundingBox();
  console.log(JSON.stringify(hb), JSON.stringify(rb), (await row.innerText()).replace(/\s+/g, ' '));
  await p.screenshot({ path: out + '08.png', clip: { x: 220, y: hb.y - 8, width: 1060, height: rb.y + rb.height - hb.y + 16 } });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
