const { chromium } = require('playwright-core');
const raw = __dirname + '/cancel/';
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
  const open = async (p, plate, tab) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(plate), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: plate }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = '${tab}'"]`);
    await p.waitForTimeout(900);
  };
  const toCancel = async (p) => {
    await p.locator('button[wire\\:click="markPurchaseCancelled"], button[wire\\:click="closePurchaseCancelUnpaid"], button[wire\\:click="unmarkPurchaseCancelled"], button[wire\\:click="reopenPurchaseCancelClosed"]').first()
      .evaluate(e => e.closest('div.rounded-md').scrollIntoView({ block: 'center' })).catch(async () => {
        await p.locator('text=매입취소').first().evaluate(e => e.scrollIntoView({ block: 'center' }));
      });
    await p.waitForTimeout(500);
  };
  const save = async (p, tag) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(2500);
    for (let i = 0; i < 3; i++) { const m = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await m.count()) { console.log(tag, 'modal:', await m.first().innerText()); await m.first().click(); await p.waitForTimeout(2000); } }
    console.log(tag, 'save:', await body(p, /[^\n]*(수정됐습니다|저장할 수 없|필수)[^\n]*/));
  };

  const m = await login('guide.manager@car-erp.test');
  // ── 00가1151: 전환 → 위약금 → (재무 입금) → 취소완료
  await open(m, '00가1151', 'purchase');
  await toCancel(m);
  await m.screenshot({ path: raw + 'c1-before.png', clip: panel });
  await m.locator('button[wire\\:click="markPurchaseCancelled"]').click();
  await m.waitForTimeout(2000);
  console.log('mark:', await body(m, /[^\n]*매입취소로 전환[^\n]*/));
  await toCancel(m);
  await m.screenshot({ path: raw + 'c2-marked.png', clip: panel });
  await m.click(`button[\\@click="tab = 'sale'"]`);
  await m.waitForTimeout(900);
  const sp = m.locator('input[wire\\:model="sale_price_str"]');
  await sp.evaluate(e => e.scrollIntoView({ block: 'center' }));
  await sp.fill('500');
  await m.keyboard.press('Tab');
  await m.waitForTimeout(500);
  await m.screenshot({ path: raw + 'c3-penalty.png', clip: panel });
  await save(m, 'c4');
  await m.screenshot({ path: raw + 'c4-saved.png' });

  // ── 00가1152: 전환 → 위약금 → 미수 마감
  await open(m, '00가1152', 'purchase');
  await toCancel(m);
  await m.locator('button[wire\\:click="markPurchaseCancelled"]').click();
  await m.waitForTimeout(2000);
  await m.click(`button[\\@click="tab = 'sale'"]`);
  await m.waitForTimeout(900);
  await m.locator('input[wire\\:model="sale_price_str"]').fill('500');
  await m.keyboard.press('Tab');
  await save(m, 'c5');
  await open(m, '00가1152', 'purchase');
  await toCancel(m);
  await m.screenshot({ path: raw + 'c6-before-close.png', clip: panel });
  await m.locator('button[wire\\:click="closePurchaseCancelUnpaid"]').click();
  await m.waitForTimeout(2500);
  console.log('close:', await body(m, /[^\n]*미수 마감했습니다[^\n]*/));
  await toCancel(m);
  await m.screenshot({ path: raw + 'c7-closed.png', clip: panel });
  await m.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await m.waitForTimeout(1500);

  // 목록 — 매입취소 필터
  await m.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가115'), { waitUntil: 'networkidle', timeout: 120000 });
  await m.waitForTimeout(1500);
  for (const pl of ['00가1151', '00가1152']) console.log('list', pl, (await m.locator('tr', { hasText: pl }).first().innerText()).replace(/\s+/g, ' ').slice(0, 200));
  await m.screenshot({ path: raw + 'c8-list.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
