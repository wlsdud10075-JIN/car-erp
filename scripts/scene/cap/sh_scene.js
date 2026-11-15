const { chromium } = require('playwright-core');
const raw = __dirname + '/sh/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const login = async (email) => {
    const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
    p.setDefaultTimeout(90000);
    p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', email);
    await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
    return p;
  };
  const body = async (p, re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const open = async (p, plate) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(plate), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: plate }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'dhl'"]`);
    await p.waitForTimeout(900);
  };

  // ① 수출통관 — 한 대 EMS
  const c = await login('clear@car-erp.test');
  await open(c, '00가1141');
  await c.screenshot({ path: raw + 'h1-empty.png', clip: panel });
  await c.locator('button[wire\\:click="addShipment(\'ems\')"]').click();
  await c.waitForTimeout(1200);
  await c.locator('input[wire\\:model="shipments.0.tracking_no"]').fill('EE123456789KR');
  await c.locator('input[wire\\:model="shipments.0.fee"]').fill('25000');
  const d = c.locator('input[wire\\:model="shipments.0.sent_date"]');
  await d.fill('2026-11-14'); await c.keyboard.press('Tab');
  await c.waitForTimeout(600);
  await c.screenshot({ path: raw + 'h2-filled.png', clip: panel });
  await c.getByRole('button', { name: '수정 저장' }).click();
  await c.waitForTimeout(2500);
  for (let i = 0; i < 3; i++) { const x = c.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await x.count()) { await x.first().click(); await c.waitForTimeout(2000); } }
  console.log('save:', await body(c, /[^\n]*(수정됐습니다|저장할 수 없)[^\n]*/));
  await c.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await c.waitForTimeout(1500);
  await open(c, '00가1141');
  await c.screenshot({ path: raw + 'h3-saved.png', clip: panel });
  await c.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await c.waitForTimeout(1500);
  await c.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });

  // ② 관리 이상 — 일괄 DHL (2대에 n/1)
  const m = await login('guide.manager@car-erp.test');
  await m.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle', timeout: 120000 });
  await m.getByRole('button', { name: /EMS\/DHL 기입/ }).first().click();
  await m.waitForTimeout(1500);
  await m.locator('select[wire\\:model\\.live="shipBulkCarrier"]').selectOption('dhl');
  await m.waitForTimeout(800);
  await m.locator('input[wire\\:model="shipBulkTrackingNo"]').fill('1234567890');
  const bd = m.locator('input[wire\\:model="shipBulkDate"]');
  await bd.fill('2026-11-14'); await m.keyboard.press('Tab');
  await m.locator('input[wire\\:model="shipBulkTotal"]').fill('60000');
  await m.locator('textarea[wire\\:model="shipBulkRaw"]').fill('00가1131\n00가1141');
  await m.locator('button[wire\\:click="previewShipmentBulk"]').click();
  await m.waitForTimeout(2000);
  await m.screenshot({ path: raw + 'h4-bulk-preview.png' });
  await m.locator('button[wire\\:click="applyShipmentBulk"]').click();
  await m.waitForTimeout(2500);
  console.log('bulk:', await body(m, /[^\n]*(기입했|적용|대에)[^\n]*/));
  await m.screenshot({ path: raw + 'h5-bulk-done.png' });
  await open(m, '00가1141');
  await m.screenshot({ path: raw + 'h6-after-bulk.png', clip: panel });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
