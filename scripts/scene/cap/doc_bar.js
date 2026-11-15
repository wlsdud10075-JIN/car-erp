const { chromium } = require('playwright-core');
const raw = __dirname + '/doc/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가11'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.evaluate(() => {
    const h = [...document.querySelectorAll('*')].find(e => e.children.length < 4 && /통관서류 알람/.test(e.textContent || '') && e.querySelector('button'));
    const btn = h && [...h.querySelectorAll('button')].find(x => /✕|×/.test(x.textContent));
    if (btn) btn.click();
  });
  await p.waitForTimeout(800);
  const box = await p.locator('text=통관서류 알람').first().boundingBox().catch(() => null);
  if (box) { await p.mouse.click(box.x + 300, box.y + box.height / 2); await p.waitForTimeout(400); }
  const still = await p.locator('text=통관서류 알람').first().isVisible().catch(() => false);
  if (still) {
    const bb = await p.locator('text=통관서류 알람').first().boundingBox();
    // 헤더 오른쪽 끝 × 위치를 찾아 누른다
    const xs = await p.$$eval('button', bs => bs.filter(b => b.offsetParent && /✕|×/.test(b.textContent || '')).map(b => { const r = b.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; }));
    const near = xs.filter(([x, y]) => Math.abs(y - (bb.y + bb.height / 2)) < 20).pop();
    if (near) await p.mouse.click(near[0], near[1]);
    await p.waitForTimeout(600);
  }
  console.log('alarm visible:', await p.locator('text=통관서류 알람').first().isVisible().catch(() => false));
  for (const pl of ['00가1131', '00가1141']) await p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]').check();
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'd3-bar.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 300)); process.exit(1); });
