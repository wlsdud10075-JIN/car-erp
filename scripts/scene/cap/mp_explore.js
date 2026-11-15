const { chromium } = require('playwright-core');
const raw = __dirname + '/mp/';
require('fs').mkdirSync(raw, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/settlements', { waitUntil: 'networkidle' });
  await p.screenshot({ path: raw + 'x1.png', fullPage: true });
  console.log('selects', JSON.stringify(await p.$$eval('select:visible', e => e.map(x => [x.getAttribute('wire:model') || x.getAttribute('wire:model.live'), [...x.options].map(o => o.value + '=' + o.text).slice(0, 8)]))));
  console.log('buttons', JSON.stringify(await p.$$eval('button:visible', e => e.map(x => [x.innerText.trim().slice(0, 24), x.getAttribute('wire:click')]).filter(x => x[1]).slice(0, 40))));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
