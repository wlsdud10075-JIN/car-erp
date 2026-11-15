const { chromium } = require('playwright-core');
const raw = __dirname + '/bd/';
require('fs').mkdirSync(raw, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  const pick = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가11'), { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(1500);
    for (const pl of ['00가1131', '00가1141']) {
      const cb = p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]');
      if (!(await cb.isChecked())) await cb.check();
      await p.waitForTimeout(600);
    }
  };

  // ① 선적일·ETA·VSL 일괄
  await pick();
  await p.locator('button[wire\\:click="openBulkDate(\'selected\')"]').click();
  await p.waitForTimeout(1500);
  await p.locator('input[wire\\:model="bulkShipDate"]').fill('2026-11-20');
  await p.locator('input[wire\\:model="bulkEtaDate"]').fill('2026-12-20');
  await p.locator('input[wire\\:model="bulkVessel"]').fill('MV GUIDE STAR V.01');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(800);
  await p.screenshot({ path: raw + 'b1-date-modal.png' });
  await p.locator('button[wire\\:click="applyBulkDate"]').last().click();
  await p.waitForTimeout(3000);
  console.log('date:', await body(/[^\n]*적용 \d+대[^\n]*/));
  await p.screenshot({ path: raw + 'b2-date-done.png' });

  // ② 번호 일괄
  await pick();
  await p.locator('button[wire\\:click="openBulkNumber"]').click();
  await p.waitForTimeout(1500);
  await p.locator('input[wire\\:model="bulkNumDecl"]').fill('12345-67-8901234');
  await p.locator('input[wire\\:model="bulkNumContainer"]').fill('TEMU1234567');
  await p.locator('input[wire\\:model="bulkNumReason"]').fill('가이드 예시 선적분');
  await p.waitForTimeout(600);
  await p.screenshot({ path: raw + 'b3-num-modal.png' });
  await p.locator('button[wire\\:click="applyBulkNumber"]').click();
  await p.waitForTimeout(3000);
  console.log('num:', await body(/[^\n]*기입 \d+대[^\n]*/));
  await p.screenshot({ path: raw + 'b4-num-done.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
