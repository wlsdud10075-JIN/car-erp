const { chromium } = require('playwright-core');
const raw = __dirname + '/sv/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
const BUYER = 'GUIDE SAMPLE TRADING';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const open = async (plate) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(plate), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: plate }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'sale'"]`);
    await p.waitForTimeout(900);
    await p.locator('[data-savings-use]').evaluate(e => e.scrollIntoView({ block: 'center' }));
    await p.waitForTimeout(500);
  };
  const save = async (tag) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(2500);
    for (let i = 0; i < 3; i++) { const x = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await x.count()) { await x.first().click(); await p.waitForTimeout(2000); } }
    console.log(tag, 'save:', await body(/[^\n]*(수정됐습니다|저장할 수 없|부족|잔액)[^\n]*/));
  };
  const summary = async (tag) => console.log(tag, (await p.locator('text=판매 미입금 요약').first().locator('xpath=ancestor::div[contains(@class,"rounded-lg")][1]').innerText()).replace(/\s+/g, ' ').slice(0, 200));
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 적립 — 00가1131
  await open('00가1131');
  await p.screenshot({ path: raw + 's1-before.png', clip: panel });
  await p.locator('input[wire\\:model="savings_deposit_str"]').fill('100');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 's2-deposit-filled.png', clip: panel });
  await save('s2');
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1200);
  await open('00가1131');
  await p.screenshot({ path: raw + 's3-deposit-saved.png', clip: panel });

  // ② 사용 — 00가1141
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1200);
  await open('00가1141');
  await summary('before-use');
  await p.locator('input[wire\\:model="savings_used_str"]').fill('100');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 's4-use-filled.png', clip: panel });
  await save('s4');
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1200);
  await open('00가1141');
  await summary('after-use');
  await p.screenshot({ path: raw + 's5-use-saved.png', clip: panel });
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1200);

  // ③ 바이어 적립금 탭
  await p.goto('http://localhost:8001/erp/buyers?search=' + encodeURIComponent(BUYER), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: BUYER }).first().click();
  await p.waitForTimeout(2500);
  await p.locator('button', { hasText: /^적립금$/ }).first().click();
  await p.waitForTimeout(1200);
  await p.screenshot({ path: raw + 's6-buyer-savings.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
