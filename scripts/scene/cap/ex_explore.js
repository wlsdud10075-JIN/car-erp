const { chromium } = require('playwright-core');
const raw = __dirname + '/ex/';
require('fs').mkdirSync(raw, { recursive: true });
const PLATE = '00가1113';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  console.log('login', p.url());
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'export'"]`);
  await p.waitForTimeout(800);
  await p.screenshot({ path: raw + 'x-full.png', fullPage: false });
  const panelH = await p.locator('.overflow-y-auto').evaluateAll(els => els.map(e => e.scrollHeight));
  console.log('scroll heights', JSON.stringify(panelH));
  const fields = await p.$$eval('input, select, textarea, button', els => els.filter(e => e.offsetParent !== null && e.closest('[x-show]')?.getAttribute('x-show')?.includes("export")).map(e => [e.tagName, e.type || '', e.getAttribute('wire:model') || e.getAttribute('wire:model.live') || e.getAttribute('wire:model.blur') || e.getAttribute('wire:click') || '', (e.placeholder || e.innerText || '').trim().slice(0, 30)]));
  console.log(JSON.stringify(fields));
  // 단계별 스크롤 캡처
  for (let i = 0; i < 6; i++) {
    await p.locator('.overflow-y-auto').evaluateAll((els, k) => els.forEach(e => e.scrollTop = k * 700), i);
    await p.waitForTimeout(300);
    await p.screenshot({ path: raw + `s${i}.png`, clip: { x: 460, y: 0, width: 820, height: 1000 } });
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
