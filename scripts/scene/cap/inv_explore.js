const { chromium } = require('playwright-core');
const raw = __dirname + '/inv/';
require('fs').mkdirSync(raw, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/inventory', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'i0.png', fullPage: true });
  const rows = await p.$$eval('table tbody tr', trs => trs.slice(0, 12).map(t => t.innerText.replace(/\s+/g, ' ').slice(0, 160)));
  console.log(rows.join('\n'));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 300)); process.exit(1); });
