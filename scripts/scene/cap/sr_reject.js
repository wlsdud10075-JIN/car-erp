const { chromium } = require('playwright-core');
const raw = __dirname + '/sr/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  p.on('dialog', d => d.accept());
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/shipping-requests', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000);
  await p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  console.log('B/L issue btn visible to 수출통관:', await p.getByRole('button', { name: 'B/L번호·선박명 기입' }).count());
  await p.getByRole('button', { name: '반려', exact: true }).first().click(); await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 's6-rejected.png', fullPage: true });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
