const { chromium } = require('playwright-core');
const raw = __dirname + '/ss/';
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 300)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const list = async (tag) => {
    await p.goto('http://localhost:8001/erp/settlements?monthFilter=2026-11', { waitUntil: "networkidle", timeout: 120000 });
    await p.locator('button:visible', { hasText: '✕' }).last().click().catch(() => {});
    await p.locator('input[wire\\:model="search"], input[wire\\:model\\.live="search"]').first().fill(PLATE).catch(() => {});
    await p.getByRole('button', { name: '조회', exact: true }).click().catch(() => {});
    await p.waitForTimeout(1500);
    const row = p.locator('tr', { hasText: PLATE }).first();
    console.log(tag, 'row:', (await row.innerText()).replace(/\s+/g, ' '));
    await p.screenshot({ path: raw + tag + '-list.png', fullPage: true });
    return row;
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 처음 — 비용 대기
  await list('s1');

  // ② 차량 매입 탭에 탁송비
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: "networkidle", timeout: 120000 });
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'purchase'"]`);
  await p.waitForTimeout(800);
  const tow = p.locator('input[wire\\:model="cost_towing_str"]');
  await tow.evaluate(e => e.scrollIntoView({ block: 'center' }));
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 's2-costs-before.png', clip: panel });
  await tow.click(); await tow.pressSequentially('150000'); await p.keyboard.press('Tab');
  await p.waitForTimeout(600);
  await p.screenshot({ path: raw + 's3-costs-filled.png', clip: panel });
  await p.getByRole('button', { name: '수정 저장' }).click();
  await p.waitForTimeout(2200);
  for (let i = 0; i < 3; i++) { const m = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await m.count()) { console.log('modal:', await m.first().innerText()); await m.first().click(); await p.waitForTimeout(2000); } }
  console.log('save:', await body(/[^\n]*(수정됐습니다|막|차단|잠|필요)[^\n]*/));
  await p.screenshot({ path: raw + 's4-after-save-full.png' });

  // ③ 정산 처리 — 2차 가능 + 드로어
  let row = await list('s5');
  await row.locator('td').nth(1).click();
  await p.waitForTimeout(2200);
  await p.screenshot({ path: raw + 's6-drawer.png' });
  const drawer = p.locator('text=2차 차액 (예상').first();
  if (await drawer.count()) { await drawer.evaluate(e => e.scrollIntoView({ block: 'center' })); await p.waitForTimeout(400); await p.screenshot({ path: raw + 's7-drawer-diff.png' }); }
  const costs = p.locator('text=적용 비용 내역').first();
  if (await costs.count()) { await costs.evaluate(e => e.scrollIntoView({ block: 'start' })); await p.waitForTimeout(400); await p.screenshot({ path: raw + 's7b-drawer-costs.png' }); }
  await p.locator('button[wire\\:click="close"]').first().click().catch(() => {});
  await p.waitForTimeout(1000);

  // ④ [2차 완료]
  row = p.locator('tr', { hasText: PLATE }).first();
  await row.locator('button[wire\\:click^="closeSecondarySettlement"]').click();
  await p.waitForTimeout(2500);
  console.log('close toast:', await body(/[^\n]*(2차 정산|마감|환율)[^\n]*/));
  await p.screenshot({ path: raw + 's8-after-close-full.png' });
  await list('s9');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
