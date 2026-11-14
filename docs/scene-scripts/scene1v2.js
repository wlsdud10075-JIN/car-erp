const { chromium } = require('playwright-core');
const raw = __dirname + '/v2/';
require('fs').mkdirSync(raw, { recursive: true });
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  const shot = (n, h = 1000, y = 0) => p.screenshot({ path: raw + n + '.png', clip: { ...panel, y, height: h } });
  const sec = async (re) => { const l = p.getByText(re).first(); await l.scrollIntoViewIfNeeded(); await p.waitForTimeout(300); const bb = await l.boundingBox(); console.log(String(re), JSON.stringify(bb)); return bb; };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  if (false) {
  await p.click('button[wire\\:click="openCreate"]');
  await p.waitForTimeout(1500);
  // A. 최소 등록 — 번호 + 담당자 + 바이어 미정
  await p.locator('input[placeholder="12가3456"]').fill(PLATE);
  await p.locator('select').filter({ has: p.locator('option', { hasText: '김영업' }) }).last().selectOption({ label: '김영업' });
  await p.waitForTimeout(400);
  await shot('a1-basic', 330);
  await p.click(`button[\\@click="tab = 'sale'"]`);
  await p.waitForTimeout(400);
  await p.locator('input[type=checkbox][wire\\:model\\.live="buyer_undecided"]').check();
  await p.waitForTimeout(700);
  await shot('a2-sale', 380);
  await p.getByRole('button', { name: '신규 등록' }).click();
  await p.waitForTimeout(1200);
  await p.screenshot({ path: raw + 'a3-confirm.png', clip: { x: 400, y: 315, width: 480, height: 370 } });
  await p.getByRole('button', { name: '확인 후 저장' }).click();
  await p.waitForTimeout(1500);
  await shot('a4-done', 300);
  const body1 = await p.locator('body').innerText();
  let i = body1.indexOf('등록됐습니다'); console.log('toast1:', i >= 0 ? body1.slice(i - 10, i + 100).replace(/\n/g, ' | ') : 'NONE');
  await p.waitForTimeout(5000);
  }
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'basic'"]`);
  await p.waitForTimeout(500);
  await p.locator('input[placeholder="현대"]').fill('현대');
  await p.locator('input[placeholder="쏘나타"]').fill('쏘나타');
  await p.locator('input[placeholder="2020"]').fill('2020');
  await p.locator('input[placeholder="85000"]').fill('85000');
  await shot('b1-basic-edit-top', 1000);
  const nb = await sec(/^NICE 조회$/);
  await shot('b2-nice-btn', 330, Math.max(0, nb.y - 230));
  // C. 매입 탭 상세
  await p.click(`button[\\@click="tab = 'purchase'"]`);
  await p.waitForTimeout(500);
  await p.locator('input[placeholder="경매 / 딜러 / 개인"]').fill('딜러');
  const pp = p.locator('input[wire\\:model*="purchase_price_str"]'); await pp.click(); await pp.pressSequentially('10000000');
  const sf = p.locator('input[wire\\:model*="selling_fee_str"]').first(); await sf.click(); await sf.pressSequentially('300000');
  await p.locator('input[wire\\:model\\.blur="purchase_seller_bank"]').fill('국민은행');
  await p.locator('input[wire\\:model*="purchase_seller_holder"]').fill('홍길동');
  await p.locator('input[wire\\:model\\.blur="purchase_seller_account"]').fill('123456789012');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(800);
  await p.locator('.overflow-y-auto').evaluateAll(els => els.forEach(e => e.scrollTop = 0));
  await p.waitForTimeout(300);
  await shot('c1-purchase-top', 1000);
  for (const [n, re] of [['c2-cost', /^비용 \d+개$/], ['c3-pay', /^매입 지급$/]]) { const bb = await sec(re); await shot(n, 1000 - Math.max(0, bb.y - 16), Math.max(0, bb.y - 16)); }
  // 패널 맨 아래까지
  await p.locator('.overflow-y-auto').evaluateAll(els => els.forEach(e => e.scrollTop = e.scrollHeight));
  await p.waitForTimeout(400);
  await shot('c4-bottom', 1000);
  // 저장
  await p.getByRole('button', { name: '수정 저장' }).click();
  await p.waitForTimeout(1200);
  await p.screenshot({ path: raw + 'c5-confirm-full.png' });
  await p.getByRole('button', { name: '확인 후 저장' }).click();
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'c6-after-full.png' });
  const body2 = await p.locator('body').innerText();
  for (const k of ['저장', '수정']) { i = body2.indexOf(k + '됐습니다'); if (i >= 0) console.log('toast2:', body2.slice(i - 30, i + 100).replace(/\n/g, ' | ')); }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
