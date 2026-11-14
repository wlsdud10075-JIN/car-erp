const { chromium } = require('playwright-core');
const raw = __dirname + '/ss2/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(60000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 300)); d.accept(); });
  const list = async (tag) => {
    await p.goto('http://localhost:8001/erp/settlements?monthFilter=2026-11', { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"], input[wire\\:model\\.live="search"]').first().fill('00가112').catch(() => {});
    await p.getByRole('button', { name: '조회', exact: true }).click().catch(() => {});
    await p.waitForTimeout(1800);
    for (const pl of ['00가1121', '00가1122']) console.log(tag, (await p.locator('tr', { hasText: pl }).first().innerText()).replace(/\s+/g, ' '));
    await p.screenshot({ path: raw + tag + '-list.png' });
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await list('b1');

  // 차량 관리 → 명세서 기입
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle', timeout: 120000 });
  const btn = p.getByRole('button', { name: '명세서 기입' });
  await btn.scrollIntoViewIfNeeded();
  const bb = await btn.boundingBox();
  await p.screenshot({ path: raw + 'b2-button.png', clip: { x: Math.max(0, bb.x - 520), y: Math.max(0, bb.y - 40), width: 760, height: 110 } });
  await btn.click();
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'b3-modal.png' });
  await p.locator('textarea[wire\\:model="costImportRaw"]').fill('00가1121 탁송 150,000\n00가1122 탁송 120,000');
  await p.locator('button[wire\\:click="parseCostImport"]').click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'b4-preview.png' });
  await p.locator('button[wire\\:click="applyCostImport"]').click();
  await p.waitForTimeout(2500);
  const t = await p.locator('body').innerText();
  console.log('apply:', (t.match(/[^\n]*(기입했습니다|기입됨|대에|실패)[^\n]*/) || ['(없음)'])[0]);
  await p.screenshot({ path: raw + 'b5-applied.png' });

  await list('b6');
  // 드로어 — 00가1121
  await p.locator('tr', { hasText: '00가1121' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2200);
  const d = p.locator('text=2차 차액 (예상').first();
  if (await d.count()) { await d.evaluate(e => e.scrollIntoView({ block: 'center' })); await p.waitForTimeout(400); }
  await p.screenshot({ path: raw + 'b7-drawer.png' });
  await p.locator('button[wire\\:click="close"]').first().click().catch(() => {});
  await p.waitForTimeout(1000);

  // 이 달 2차 일괄 마감
  await p.locator('button[wire\\:click="openCloseSecondaryModal"]').click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'b8-bulk-modal.png' });
  await p.locator('button[wire\\:click="closeSecondaryMonth"]').click();
  await p.waitForTimeout(3000);
  const t2 = await p.locator('body').innerText();
  console.log('bulk:', (t2.match(/[^\n]*(마감했습니다|마감됨|건 마감|완료)[^\n]*/) || ['(없음)'])[0]);
  await list('b9');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
