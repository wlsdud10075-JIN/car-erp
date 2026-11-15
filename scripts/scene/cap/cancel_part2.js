const { chromium } = require('playwright-core');
const raw = __dirname + '/cancel/';
const panel = { x: 460, y: 0, width: 820, height: 1000 };
const PLATE = '00가1151';
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
  const open = async (p, tab) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = '${tab}'"]`);
    await p.waitForTimeout(900);
  };
  const toCancel = async (p) => { await p.locator('text=위약금은 판매 탭에').first().evaluate(e => e.scrollIntoView({ block: 'center' })).catch(() => {}); await p.waitForTimeout(400); };

  const m = await login('guide.manager@car-erp.test');
  await open(m, 'purchase');
  await m.locator('button[wire\\:click="markPurchaseCancelled"]').click();
  await m.waitForTimeout(2500);
  console.log('toasts:', (await m.locator('body').innerText()).match(/다른 창[^\n]*/g) || 'none');
  await toCancel(m);
  await m.screenshot({ path: raw + 'p2-marked.png', clip: panel });
  await m.click(`button[\\@click="tab = 'sale'"]`);
  await m.waitForTimeout(900);
  await m.locator('input[wire\\:model="sale_price_str"]').fill('500');
  await m.keyboard.press('Tab');
  await m.waitForTimeout(500);
  await m.screenshot({ path: raw + 'p3-penalty.png', clip: panel });
  await m.getByRole('button', { name: '수정 저장' }).click();
  await m.waitForTimeout(2500);
  for (let i = 0; i < 3; i++) { const x = m.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await x.count()) { console.log('modal text:', (await m.locator('body').innerText()).match(/[^\n]*(확인|맞습니까|저장할까)[^\n]*/g)?.slice(0, 4)); await m.screenshot({ path: raw + 'p3b-modal.png' }); await x.first().click(); await m.waitForTimeout(2000); } }
  console.log('save:', await body(m, /[^\n]*(수정됐습니다|저장할 수 없)[^\n]*/));
  await m.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await m.waitForTimeout(1500);
  await m.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });

  // 재무 — 위약금 입금 → 확정
  const f = await login('finance@car-erp.test');
  await f.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
  await f.locator('input[wire\\:model="search"]').first().fill(PLATE);
  await f.getByRole('button', { name: '조회', exact: true }).click();
  await f.waitForTimeout(2000);
  await f.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await f.waitForTimeout(2500);
  await f.locator('input[wire\\:model="hAmount"]').fill('500');
  await f.locator('input[wire\\:model="hExchangeRate"]').fill('1400');
  await f.locator('textarea[wire\\:model="hNote"]').fill('위약금 입금');
  await f.getByRole('button', { name: '추가', exact: true }).click();
  await f.waitForTimeout(2500);
  await f.goto('http://localhost:8001/erp/transfers?tabType=sale_payment&search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
  await f.waitForTimeout(1500);
  await f.locator('tr', { hasText: PLATE }).first().locator('button', { hasText: '재무 처리 완료' }).first().click();
  await f.waitForTimeout(2000);
  await f.locator('button[wire\\:click="confirmPayment"]').first().click();
  await f.waitForTimeout(2500);
  console.log('confirm:', await body(f, /[^\n]*(확정|실패|부족)[^\n]*/));

  // 다시 열어 취소완료
  await open(m, 'purchase');
  await toCancel(m);
  await m.screenshot({ path: raw + 'p4-done.png', clip: panel });
  await m.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가115'), { waitUntil: 'networkidle', timeout: 120000 });
  await m.waitForTimeout(1500);
  for (const pl of ['00가1151', '00가1152']) console.log('list', pl, (await m.locator('tr', { hasText: pl }).first().innerText()).replace(/\s+/g, ' ').slice(0, 160));
  await m.screenshot({ path: raw + 'p5-list.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
