const { chromium } = require('playwright-core');
const out = __dirname + '/out/';
require('fs').mkdirSync(out, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'admin@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle' });
  await p.screenshot({ path: out + 'x1-list.png' });
  await p.click('button[wire\\:click="openCreate"]');
  await p.waitForTimeout(1500);
  await p.screenshot({ path: out + 'x2-panel-basic.png' });
  // 탭 이름들
  const tabs = await p.$$eval('button', els => els.filter(e => (e.getAttribute('@click') || e.getAttribute('x-on:click') || '').includes("tab =")).map(e => [e.innerText.trim(), e.getAttribute('@click') || e.getAttribute('x-on:click')]));
  console.log(JSON.stringify(tabs));
  // 기본정보 탭의 라벨·입력
  const fields = await p.$$eval('input:visible, select:visible, textarea:visible', els => els.slice(0, 80).map(e => [e.tagName, e.getAttribute('wire:model') || e.getAttribute('wire:model.live') || e.getAttribute('wire:model.blur') || e.name, e.placeholder || '']));
  console.log(JSON.stringify(fields));
  const btns = await p.$$eval('button:visible', els => els.map(e => [e.innerText.trim().slice(0, 30), e.getAttribute('wire:click') || '']).filter(x => x[1]));
  console.log(JSON.stringify(btns));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
