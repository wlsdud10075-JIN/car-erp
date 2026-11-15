const { chromium } = require('playwright-core');
const raw = __dirname + '/bu/';
require('fs').mkdirSync(raw, { recursive: true });
const panel = { x: 460, y: 0, width: 820, height: 1000 };
const PLATE = '00가1161';
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
    await p.click(`button[\\@click="tab = 'sale'"]`);
    await p.waitForTimeout(900);
  };
  const save = async (tag) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(2500);
    for (let i = 0; i < 3; i++) { const x = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await x.count()) { await x.first().click(); await p.waitForTimeout(2000); } }
    console.log(tag, 'save:', await body(/[^\n]*(수정됐습니다|저장할 수 없|필수)[^\n]*/));
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await open();
  await p.screenshot({ path: raw + 'u1-before.png', clip: panel });
  // ① 바이어만 지우기 — 막힘
  const cleared = await p.evaluate(() => {
    const inputs = [...document.querySelectorAll('input[type=text]')].filter(i => i.offsetParent && i.value === 'GUIDE SAMPLE TRADING');
    for (const i of inputs) { const btn = i.parentElement.querySelector('button'); if (btn) { btn.click(); return true; } }
    return false;
  });
  console.log('cleared buyer:', cleared);
  await p.waitForTimeout(1000);
  await p.locator('input[wire\\:model\\.live="buyer_undecided"]').check();
  await p.waitForTimeout(800);
  await save('u2');
  await p.screenshot({ path: raw + 'u2-blocked.png' });
  const t = await p.locator('body').innerText();
  console.log('errors:', (t.match(/[^\n]*판매가를 입력하면[^\n]*/g) || []).slice(0, 2));
  // ② 판매가·판매일·운임비 비우기
  await p.locator('input[wire\\:model="sale_price_str"]').fill('');
  await p.locator('input[wire\\:model="sale_date"]').fill('');
  await p.locator('input[wire\\:model="transport_fee_str"]').fill('');
  await p.keyboard.press('Tab');
  await p.waitForTimeout(600);
  await p.locator('input[wire\\:model="sale_price_str"]').evaluate(e => e.scrollIntoView({ block: 'start' }));
  await p.mouse.wheel(0, -200);
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 'u3-cleared.png', clip: panel });
  await save('u4');
  await p.getByRole('button', { name: '취소' }).last().click().catch(() => {});
  await p.waitForTimeout(1500);
  await open();
  await p.screenshot({ path: raw + 'u5-after.png', clip: panel });
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
  console.log('row:', (await p.locator('tr', { hasText: PLATE }).first().innerText()).replace(/\s+/g, ' ').slice(0, 200));
  await p.screenshot({ path: raw + 'u6-list.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
