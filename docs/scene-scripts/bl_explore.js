const { chromium } = require('playwright-core');
const raw = __dirname + '/bl/';
const PLATE = '00가1113';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  const row = p.locator('tr', { hasText: PLATE }).first();
  console.log('row:', (await row.innerText()).replace(/\s+/g, ' ').slice(0, 160));
  await row.locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'bl'"]`);
  await p.waitForTimeout(800);
  await p.screenshot({ path: raw + 'e1-bl-tab.png', clip: { x: 460, y: 0, width: 820, height: 1000 } });
  const txt = await p.locator('[x-show="tab === \'bl\'"]').first().innerText();
  console.log('--- BL tab text ---\n' + txt.slice(0, 1500));
  // 진행 줄(흐름도) 문구
  console.log('--- head ---\n' + (await p.locator('body').innerText()).match(/매입[\s\S]{0,200}DHL/)?.[0]);
  await p.evaluate(() => document.querySelectorAll('.overflow-y-auto').forEach(e => e.scrollTop = 600));
  await p.waitForTimeout(500);
  await p.screenshot({ path: raw + 'e2-bl-tab-scroll.png', clip: { x: 460, y: 0, width: 820, height: 1000 } });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
