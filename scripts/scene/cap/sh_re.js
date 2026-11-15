const { chromium } = require('playwright-core');
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1141'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('tr', { hasText: '00가1141' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'dhl'"]`);
  await p.waitForTimeout(900);
  await p.screenshot({ path: __dirname + '/sh/h7.png', clip: { x: 460, y: 0, width: 820, height: 1000 } });
  await b.close();
})();
