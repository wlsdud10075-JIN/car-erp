const { chromium } = require('playwright-core');
const raw = __dirname + '/ra/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  const hideAlarm = async () => { await p.evaluate(() => { for (const el of document.querySelectorAll('*')) { if (el.children.length === 0 && /통관서류 알람/.test(el.textContent || '')) { let a = el; while (a && getComputedStyle(a).position !== 'fixed') a = a.parentElement; if (a) a.style.display = 'none'; } } }); };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/settlements', { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('input[wire\\:model="search"]').first().fill('00가1121');
  await p.keyboard.press('Enter');
  await p.waitForTimeout(2500);
  await hideAlarm();
  await p.screenshot({ path: raw + 'r1-wide.png', fullPage: true });
  // 재열기 — 미입금 요약 끝까지
  await p.goto('http://localhost:8001/erp/vehicles?openVehicle=302', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(3000);
  await hideAlarm();
  await p.locator('button:text-is("판매")').first().evaluate(e => e.click());
  await p.waitForTimeout(1500);
  await p.locator('text=판매 미입금 요약').first().evaluate(e => e.scrollIntoView({ block: 'start' }));
  await p.waitForTimeout(800);
  await p.screenshot({ path: raw + 'r8-summary.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
