const { chromium } = require('playwright-core');
const raw = __dirname + '/dr/';
require('fs').mkdirSync(raw, { recursive: true });
const PLATE = '00가1131';
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const open = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'purchase'"]`);
    await p.waitForTimeout(900);
    await p.locator('#purchase-dereg').evaluate(e => e.scrollIntoView({ block: 'start' }));
    await p.waitForTimeout(500);
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await open();
  await p.screenshot({ path: raw + 'd1-before.png', clip: panel });
  await p.locator('input[wire\\:model="is_deregistered"]').check();
  await p.locator('#purchase-dereg input[type=file]').first().setInputFiles(__dirname + '/sample-dereg.pdf');
  await p.waitForTimeout(2500);
  await p.locator('input[wire\\:model="registration_number"]').fill('2026-11131');
  const d = p.locator('input[wire\\:model="deregistration_date"]');
  await d.click(); await d.pressSequentially('20261114'); await p.keyboard.press('Tab');
  await p.waitForTimeout(600);
  await p.locator('#purchase-dereg').evaluate(e => e.scrollIntoView({ block: 'start' }));
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 'd2-filled.png', clip: panel });
  await p.getByRole('button', { name: '수정 저장' }).click();
  await p.waitForTimeout(2500);
  for (let i = 0; i < 3; i++) { const m = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await m.count()) { console.log('modal:', await m.first().innerText()); await m.first().click(); await p.waitForTimeout(2000); } }
  console.log('save:', await body(/[^\n]*(수정됐습니다|저장할 수 없|필수)[^\n]*/));
  await p.screenshot({ path: raw + 'd3-after.png' });

  await open();
  await p.locator('input[wire\\:model\\.blur="deregistrationBuyerPhone"]').fill('010-0000-1131');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(1500);
  await p.locator('#purchase-dereg').evaluate(e => e.scrollIntoView({ block: 'start' }));
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 'd4-saved.png', clip: panel });
  const t = await p.locator('#purchase-dereg').innerText();
  console.log('section:', t.replace(/\s+/g, ' ').slice(0, 600));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
