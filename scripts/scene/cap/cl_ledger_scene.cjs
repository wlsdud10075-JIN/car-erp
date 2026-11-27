// 바이어 클레임 탭 — 발생·사용 원장(2026-11-27). 01 = 기록 보기(묶음 포함), 05 = 사용 줄 펼침.
// 실행: NODE_PATH=<playwright-core 가 있는 node_modules> node scripts/scene/cap/cl_ledger_scene.cjs <출력 폴더>
const { chromium } = require('playwright-core');
const out = process.argv[2] + '/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1700 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  const openTab = async (name) => {
    await p.goto('http://localhost:8001/erp/buyers?search=' + encodeURIComponent(name), { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(1500);
    await p.locator('tr', { hasText: name }).first().locator('td').first().click();
    await p.waitForTimeout(2500);
    await p.locator('[data-buyer-claims]').waitFor({ state: 'attached' });
    await p.evaluate(() => { const t = [...document.querySelectorAll('button')].find(x => /클레임/.test(x.textContent) && /claims/.test(x.getAttribute('@click') || '')); if (t) t.click(); });
    await p.waitForTimeout(1200);
  };
  const shot = async (file, sel) => {
    const tab = await p.locator('[data-buyer-claims]').boundingBox();
    const end = sel ? await p.locator(sel).boundingBox() : tab;
    await p.screenshot({ path: out + file, clip: { x: tab.x - 20, y: 0, width: tab.width + 40, height: end.y + end.height + 16 } });
  };

  await openTab('GUIDE SAMPLE TRADING');
  console.log((await p.locator('[data-buyer-claims]').innerText()).slice(0, 1500));
  await p.locator('[data-buyer-claims] tr', { hasText: '묶음 3대' }).first().click();
  await p.waitForTimeout(600);
  await shot('01.png');

  await openTab('CLAIMDEMO');
  await p.locator('[data-claim-ledger-row="out"]').first().click();
  await p.waitForTimeout(600);
  await shot('05.png', '[data-claim-ledger]');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 600)); process.exit(1); });
