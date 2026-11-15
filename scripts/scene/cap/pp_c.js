const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const raw = __dirname + '/pp/';
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
const toggle = (s) => console.log(execSync(`php "${__dirname}/altoggle.php" ${s}`, { cwd: 'C:/xampp/htdocs/car-erp' }).toString().trim());
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  const shot = (n, h, y) => p.screenshot({ path: raw + n + '.png', clip: { ...panel, y, height: Math.min(h, 1000 - y) } });
  const toPay = async () => { const l = p.getByText(/^매입 지급$/).first(); await l.scrollIntoViewIfNeeded(); await p.waitForTimeout(300); const bb = await l.boundingBox(); return Math.max(0, bb.y - 16); };
  try {
    toggle('on');
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', 'finance@car-erp.test');
    await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'purchase'"]`);
    await p.waitForTimeout(600);
    let y = await toPay();
    await shot('c1-confirmed', 700, y);
    const ph = p.locator('input[wire\\:model\\.blur="deregistrationBuyerPhone"]');
    await ph.scrollIntoViewIfNeeded(); await ph.fill('010-1234-5678'); await p.keyboard.press('Tab'); await p.waitForTimeout(800);
    const pb = await p.getByText('국내 딜러 알림톡').first().boundingBox();
    await shot('c2-phone', 190, Math.max(0, pb.y - 16));
    y = await toPay();
    await p.locator('button[wire\\:click^="openPaidNotice(\'balance\'"]').first().click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'c3-modal-full.png' });
    console.log('paid modal buttons', JSON.stringify(await p.$$eval('button:visible', e => e.map(x => x.innerText.trim()).filter(Boolean).slice(-6))));
    await p.locator('button[wire\\:click="cancelPaidNotice"]').first().click();
    await p.waitForTimeout(800);
  } finally {
    toggle('off');
  }
  await b.close();
})().catch(e => { console.error(e); try { toggle('off'); } catch (x) {} process.exit(1); });
