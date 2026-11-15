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
    await shot('e1-header', 200, 0);
    const s = p.getByText('매입 미지급 요약').first(); await s.scrollIntoViewIfNeeded(); await p.waitForTimeout(400);
    const sb = await s.boundingBox();
    await shot('e2-summary', 200, Math.max(0, sb.y - 20));
    await p.locator('button[wire\\:click^="openPaidNotice(\'balance\'"]').first().click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'e3-modal-full.png' });
    const box = await p.locator('text=딜러에게 입금 완료 알림톡 보내기').first().boundingBox();
    console.log('modal title box', JSON.stringify(box));
    await p.locator('button[wire\\:click="cancelPaidNotice"]').first().click();
    await p.waitForTimeout(800);
  } finally {
    toggle('off');
  }
  await b.close();
})().catch(e => { console.error(e); try { toggle('off'); } catch (x) {} process.exit(1); });
