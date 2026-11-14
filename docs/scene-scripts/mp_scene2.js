const { chromium } = require('playwright-core');
const raw = __dirname + '/mp/';
const PLATE = '00가1113';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  let p;
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const login = async (email) => {
    if (p) await p.close();
    await ctx.clearCookies();
    p = await ctx.newPage();
    p.on('dialog', d => { console.log('  dialog:', d.message().slice(0, 120)); d.accept(); });
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', email);
    await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
    console.log('login', email, p.url());
  };
  const btns = async () => JSON.stringify(await p.$$eval('button:visible', e => e.map(x => [x.innerText.trim().replace(/\s+/g, ' ').slice(0, 30), x.getAttribute('wire:click')]).filter(x => x[1])));

  // ① 재무 — 정산 확정
  await login('finance@car-erp.test');
  await p.goto('http://localhost:8001/erp/settlements?monthFilter=2026-11', { waitUntil: 'networkidle' });
  await p.locator('input[wire\\:model="search"], input[wire\\:model\\.live="search"]').first().fill(PLATE).catch(() => {});
  await p.getByRole('button', { name: '조회', exact: true }).click().catch(() => {});
  await p.waitForTimeout(1500);
  await p.screenshot({ path: raw + 'f1-list-full.png', fullPage: true });
  console.log('f1 buttons', await btns());
  const row = p.locator('tr', { hasText: PLATE }).first();
  console.log('row:', (await row.innerText()).replace(/\s+/g, ' '));
  await row.locator('td').nth(1).click().catch(() => {});
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'f2-drawer-full.png' });
  await p.locator('button[wire\\:click="close"]').first().click().catch(() => {});
  await p.waitForTimeout(1000);
  const cbtn = p.locator('tr', { hasText: PLATE }).first().locator('button[wire\\:click\\.stop^="confirmSettlement"]').first();
  console.log('confirm buttons', await cbtn.count());
  if (await cbtn.count()) { await cbtn.click(); await p.waitForTimeout(1800); console.log('confirm toast:', await body(/[^\n]*확정[^\n]*/)); }
  else console.log('no confirmSettlement button visible');
  await p.screenshot({ path: raw + 'f3-after-full.png', fullPage: true });

  // ② 업무관리자 — 월정산 제출
  await login('guide.manager@car-erp.test');
  await p.goto('http://localhost:8001/erp/settlements?monthFilter=2026-11', { waitUntil: 'networkidle' });
  await p.screenshot({ path: raw + 'm1-list-full.png', fullPage: true });
  console.log('m1 buttons', await btns());
  await p.locator('button[wire\\:click="openSubmitModal"]').first().click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'm2-modal-full.png', fullPage: true });
  console.log('m2 selects', JSON.stringify(await p.$$eval('select:visible', e => e.map(x => [x.getAttribute('wire:model') || x.getAttribute('wire:model.live'), x.value, [...x.options].map(o => o.text).slice(0, 5)]))));
  console.log('m2 buttons', await btns());
  await p.locator('button[wire\\:click="submitPayoutBatch"]').first().click();
  await p.waitForTimeout(2500);
  console.log('submit toast:', await body(/[^\n]*(제출|올렸|월정산)[^\n]*/));
  await p.screenshot({ path: raw + 'm3-after-full.png' });

  // ③ 결재 — 부장 → 상무 → 대표
  for (const [email, tag] of [['holddemo.bujang@car-erp.test', 'a1'], ['holddemo.sangmu@car-erp.test', 'a2'], ['boss@car-erp.test', 'a3']]) {
    await login(email);
    await p.goto('http://localhost:8001/erp/payout-batches', { waitUntil: 'networkidle' });
    await p.screenshot({ path: raw + tag + '-before-full.png', fullPage: true });
    console.log(tag, 'buttons', await btns());
    const ap = p.locator('button[wire\\:click^="approve("]').first();
    if (await ap.count()) { await ap.click(); await p.waitForTimeout(2500); console.log(tag, 'toast:', await body(/[^\n]*(승인|결재)[^\n]*/)); }
    else console.log(tag, 'no approve button');
    await p.screenshot({ path: raw + tag + '-after-full.png', fullPage: true });
  }

  // ④ 최종 확정 — 제출자
  await login('guide.manager@car-erp.test');
  await p.goto('http://localhost:8001/erp/payout-batches', { waitUntil: 'networkidle' });
  await p.screenshot({ path: raw + 'z1-before-full.png', fullPage: true });
  console.log('z1 buttons', await btns());
  const fb = p.locator('button[wire\\:click^="finalizeBatch"]').first();
  if (await fb.count()) { await fb.click(); await p.waitForTimeout(2500); console.log('finalize toast:', await body(/[^\n]*확정[^\n]*/)); }
  else console.log('no finalize button');
  await p.screenshot({ path: raw + 'z2-after-full.png', fullPage: true });

  // ⑤ 재무 — 정산 상태
  await login('finance@car-erp.test');
  await p.goto('http://localhost:8001/erp/settlements?monthFilter=2026-11', { waitUntil: 'networkidle' });
  await p.locator('input[wire\\:model="search"], input[wire\\:model\\.live="search"]').first().fill(PLATE).catch(() => {});
  await p.getByRole('button', { name: '조회', exact: true }).click().catch(() => {});
  await p.waitForTimeout(1500);
  console.log('final row:', (await p.locator('tr', { hasText: PLATE }).first().innerText()).replace(/\s+/g, ' '));
  await p.screenshot({ path: raw + 'f9-final-full.png', fullPage: true });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
