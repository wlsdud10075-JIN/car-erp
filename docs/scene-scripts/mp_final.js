const { chromium } = require('playwright-core');
const raw = __dirname + '/mp/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.on('dialog', d => { console.log('dialog:', d.message()); d.accept(); });
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/payout-batches', { waitUntil: 'networkidle' });
  // 알람 팝업 닫기
  await p.locator('button:visible', { hasText: '✕' }).last().click().catch(() => {});
  await p.locator('button[wire\\:click="toggle(11)"]').click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'z3-expanded-full.png', fullPage: true });
  const fb = p.locator('button[wire\\:click="finalizeBatch(11)"]');
  console.log('finalize btn', await fb.count(), await fb.count() ? await fb.innerText() : '');
  await fb.click();
  await p.waitForTimeout(2500);
  const t = await p.locator('body').innerText(); const m = t.match(/[^\n]*확정[^\n]*/g); console.log('after:', m ? m.slice(0, 4).join(' | ') : '');
  await p.screenshot({ path: raw + 'z4-finalized-full.png', fullPage: true });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
