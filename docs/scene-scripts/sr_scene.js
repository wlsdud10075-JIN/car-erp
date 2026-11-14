const { chromium } = require('playwright-core');
const raw = __dirname + '/sr/';
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  const shot = (n, h = 1000, y = 0) => p.screenshot({ path: raw + n + '.png', clip: { ...panel, y, height: Math.min(h, 1000 - y) } });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const openVehicle = async (tab) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = '${tab}'"]`);
    await p.waitForTimeout(600);
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await openVehicle('sale');
  await shot('s1-before', 420, 0);
  // 신규 바이어
  await p.locator('button[wire\\:click="openQuickAdd(\'buyer\',\'sale\')"]').click();
  await p.waitForTimeout(1200);
  await p.locator('input[wire\\:model="qaName"]').fill('GUIDE SAMPLE TRADING');
  const cs = p.locator('input[placeholder*="국가 검색"]').first();
  await cs.click(); await cs.pressSequentially('요르단'); await p.waitForTimeout(1200);
  const opt = p.locator('li:visible, button:visible, div[role=option]:visible', { hasText: '요르단' });
  console.log('country options', await opt.count());
  if (await opt.count()) await opt.first().click();
  await p.waitForTimeout(800);
  await p.screenshot({ path: raw + 's2-modal-full.png' });
  await p.locator('button[wire\\:click="saveQuickAdd"]').click();
  await p.waitForTimeout(1500);
  console.log('toast:', await body(/신규 바이어[^\n]*/));
  // 판매 정보
  const dt = p.locator('input[wire\\:model="sale_date"]'); await dt.click(); await dt.pressSequentially('20261113'); await p.keyboard.press('Tab');
  await p.locator('select[wire\\:model\\.live="currency"], select[wire\\:model="currency"]').first().selectOption('USD');
  await p.waitForTimeout(600);
  const fx = p.locator('input[wire\\:model\\.live\\.debounce\\.500ms="exchange_rate_str"], input[wire\\:model\\.live="exchange_rate_str"], input[wire\\:model="exchange_rate_str"]').first();
  await fx.click(); await fx.fill(''); await fx.pressSequentially('1400');
  const sp = p.locator('input[wire\\:model*="sale_price_str"]').first(); await sp.click(); await sp.fill(''); await sp.pressSequentially('9000');
  const tf = p.locator('input[wire\\:model*="transport_fee_str"]').first(); await tf.click(); await tf.fill(''); await tf.pressSequentially('1000');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(1200);
  await shot('s3-filled', 640, 0);
  console.log('fields', JSON.stringify(await p.$$eval('input:visible, select:visible', e => e.map(x => [x.getAttribute('wire:model') || x.getAttribute('wire:model.live') || x.getAttribute('wire:model.live.debounce.500ms') || '', x.value]).filter(x => /sale_|currency|exchange|transport|buyer/.test(x[0])))));
  // 저장
  await p.getByRole('button', { name: '수정 저장' }).click();
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 's4-confirm-full.png' });
  const ok = p.getByRole('button', { name: '확인 후 저장' });
  if (await ok.count()) { await ok.click(); await p.waitForTimeout(1800); }
  await p.screenshot({ path: raw + 's5-after-full.png' });
  console.log('after save:', await body(/[^\n]*(수정됐습니다|등록됐습니다|막|차단|미수)[^\n]*/));
  await p.waitForTimeout(3000);
  // 다시 열기 — 판매 탭 위·아래, 서류 탭
  await openVehicle('sale');
  await shot('s6-sale-top', 1000, 0);
  const sm = p.getByText('판매 미입금 요약').first(); await sm.scrollIntoViewIfNeeded(); await p.waitForTimeout(400);
  const sb = await sm.boundingBox();
  await shot('s7-summary', 320, Math.max(0, sb.y - 20));
  await p.click(`button[\\@click="tab = 'docs'"]`);
  await p.waitForTimeout(800);
  await shot('s8-docs', 1000, 0);
  // 목록
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  const row = p.locator('tr', { hasText: PLATE }).first();
  console.log('row', (await row.innerText()).replace(/\s+/g, ' '));
  await p.screenshot({ path: raw + 's9-list-full.png' });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
