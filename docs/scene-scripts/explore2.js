const { chromium } = require('playwright-core');
const out = __dirname + '/out/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1400 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  console.log('after login', p.url());
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle' });
  await p.screenshot({ path: out + 'y1-list.png' });
  await p.click('button[wire\\:click="openCreate"]');
  await p.waitForTimeout(1500);
  console.log(JSON.stringify(await p.$$eval('select[wire\\:model="salesman_id_str"] option, select[wire\\:model\\.live="salesman_id_str"] option', o => o.map(x => x.innerText.trim()))));
  for (const t of ['purchase', 'sale']) {
    await p.click(`button[\\@click="tab = '${t}'"]`);
    await p.waitForTimeout(600);
    await p.screenshot({ path: out + `y2-${t}.png` });
    const fields = await p.$$eval('input, select, textarea', els => els.filter(e => e.offsetParent !== null).map(e => (e.getAttribute('wire:model') || e.getAttribute('wire:model.live') || e.getAttribute('wire:model.blur') || e.getAttribute('wire:model.live.debounce.500ms') || e.name || '') + (e.type === 'checkbox' ? '[chk]' : '')).filter(x => x && !/^(search|dateFrom|dateTo|perPage|user_id|dateType|salesmanId|cancelFilter|freightExact|vinSearch|shipmentMonth|shipDocIds)/.test(x)));
    console.log(t, JSON.stringify(fields));
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
