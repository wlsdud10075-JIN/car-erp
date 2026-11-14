const { chromium } = require('playwright-core');
const PLATE = '00가1113';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  const vals = () => p.$$eval('input[data-money]', els => els.filter(e => e.value && /[0-9]{4}/.test(e.value.replace(/,/g,''))).map(e => (e.getAttribute('wire:model')||e.getAttribute('wire:model.live')||e.getAttribute('wire:model.blur')||'?')+'='+e.value));
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  for (let round = 1; round <= 3; round++) {
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    const t0 = Date.now();
    for (let i = 0; i < 12; i++) {
      const v = await vals();
      if (v.length) { console.log('round', round, (Date.now()-t0)+'ms', JSON.stringify(v)); if (v.every(x => x.split('=')[1].includes(','))) break; }
      await p.waitForTimeout(250);
    }
    await p.keyboard.press('Escape'); await p.waitForTimeout(1200);
    const confirm = p.locator('button:visible', { hasText: /닫기|버리고/ }); if (await confirm.count()) { await confirm.first().click(); await p.waitForTimeout(800); }
  }
  await b.close();
})().catch(e => { console.error(e.message.slice(0,300)); process.exit(1); });
