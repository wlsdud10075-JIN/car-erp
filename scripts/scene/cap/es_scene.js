const { chromium } = require('playwright-core');
const raw = __dirname + '/es/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
const PLATE = '00가1141';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const open = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'docs'"]`).catch(async () => { await p.getByRole('button', { name: '서류', exact: true }).click(); });
    await p.waitForTimeout(900);
    await p.locator('text=전자서명').first().evaluate(e => e.scrollIntoView({ block: 'center' }));
    await p.waitForTimeout(400);
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await open();
  await p.screenshot({ path: raw + 'e1-docs.png', clip: panel });
  await p.locator('button', { hasText: '전자서명 요청' }).first().click();
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'e2-modal.png' });
  const url = await p.evaluate(() => {
    const el = [...document.querySelectorAll('input,textarea,code,a,span,div')].find(e => /\/sign\/[^\s"]+signature=/.test(e.value || e.textContent || ''));
    if (!el) return null;
    const m = (el.value || el.textContent).match(/https?:\/\/[^\s"]+\/sign\/[^\s"]+/);
    return m ? m[0] : null;
  });
  console.log('url found:', !!url);

  // 바이어 화면 — 로그인 없이
  const bp = await (await b.newContext({ viewport: { width: 420, height: 900 }, locale: 'en-US', deviceScaleFactor: 2 })).newPage();
  await bp.goto(url, { waitUntil: 'networkidle', timeout: 120000 });
  await bp.waitForTimeout(2500);
  await bp.screenshot({ path: raw + 'e3-buyer-top.png', fullPage: true });
  await bp.fill('#signer_name', 'GUIDE SAMPLE');
  await bp.fill('#recipient_email', 'buyer@example.com');
  const box = await bp.locator('#pad').boundingBox();
  await bp.locator('#pad').scrollIntoViewIfNeeded();
  const bb = await bp.locator('#pad').boundingBox();
  await bp.mouse.move(bb.x + 20, bb.y + bb.height * 0.6);
  await bp.mouse.down();
  for (let i = 0; i <= 30; i++) await bp.mouse.move(bb.x + 20 + i * (bb.width - 60) / 30, bb.y + bb.height * (0.6 - 0.35 * Math.sin(i / 4)));
  await bp.mouse.up();
  await bp.waitForTimeout(500);
  await bp.screenshot({ path: raw + 'e4-buyer-signed.png', fullPage: true });
  await Promise.all([bp.waitForLoadState('networkidle', { timeout: 120000 }), bp.click('#submitBtn')]);
  await bp.waitForTimeout(3000);
  await bp.screenshot({ path: raw + 'e5-buyer-done.png', fullPage: true });
  console.log('buyer done text:', (await bp.locator('body').innerText()).replace(/\s+/g, ' ').slice(0, 300));

  // ERP 에서 다시
  await p.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });
  await open();
  await p.screenshot({ path: raw + 'e6-docs-signed.png', clip: panel });
  console.log('chip:', (await p.locator('body').innerText()).match(/서명완료[^\n]*/)?.[0]);
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
