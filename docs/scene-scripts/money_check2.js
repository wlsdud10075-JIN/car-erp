const { chromium } = require('playwright-core');
const PLATE = '00가1113';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR' })).newPage();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  const vals = () => p.$$eval('input[data-money]', els => els.filter(e => e.value && /[0-9]{4}/.test(e.value.replace(/,/g,''))).map(e => (e.getAttribute('wire:model')||'?')+'='+e.value));
  const open = async (tag) => {
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    const t0 = Date.now();
    for (let i = 0; i < 20; i++) {
      const v = await vals();
      const bad = v.filter(x => !x.split('=')[1].includes(','));
      if (v.length) { console.log(tag, (Date.now()-t0)+'ms', 'no-comma:', JSON.stringify(bad)); if (!bad.length) break; }
      await p.waitForTimeout(300);
    }
  };
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  await open('first');
  await p.getByRole('button', { name: '수정 저장' }).click();
  await p.waitForTimeout(2500);
  for (let i = 0; i < 3; i++) { const m = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await m.count()) { await m.first().click(); await p.waitForTimeout(2000); } }
  console.log('panel open after save?', await p.locator('button', { hasText: '수정 저장' }).isVisible().catch(()=>false));
  await p.keyboard.press('Escape'); await p.waitForTimeout(1500);
  const c = p.locator('button:visible', { hasText: /닫기|버리고/ }); if (await c.count()) { await c.first().click(); await p.waitForTimeout(800); }
  await open('reopen');
  await b.close();
})().catch(e => { console.error(e.message.slice(0,300)); process.exit(1); });
