const { chromium } = require('playwright-core');
const raw = __dirname + '/ps/'; require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  const hide = () => p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1201'), { waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide();
  await p.screenshot({ path: raw + 'p1-list.png', fullPage: true });
  console.log((await p.locator('table').first().innerText()).slice(0, 500));
  await p.locator('tr', { hasText: '00가1201' }).first().locator('td').nth(1).click(); await p.waitForTimeout(3000); await hide();
  await p.screenshot({ path: raw + 'p2-basic.png', clip: { ...panel, height: 1000 } });
  await p.locator('button:text-is("매입")').first().click(); await p.waitForTimeout(1000);
  await p.screenshot({ path: raw + 'p3-purchase.png', clip: { ...panel, height: 1000 } });
  for (let i = 0; i < 3; i++) { await p.mouse.move(870, 600); await p.mouse.wheel(0, 700); await p.waitForTimeout(500); await p.screenshot({ path: raw + `p4-purchase-${i}.png`, clip: { ...panel, height: 1000 } }); }
  console.log((await p.locator('body').innerText()).match(/[^\n]*(board|연동|encar|엔카|송금인|보관)[^\n]*/g)?.slice(0, 15).join('\n'));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
