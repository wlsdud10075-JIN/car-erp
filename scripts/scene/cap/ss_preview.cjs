// secondary-settlement 03.png 재촬영 (2026-11-26 — 명세서 기입이 전 칸 「더하기」로 바뀌어 미리보기 열이 「이번 파일 · 결과」가 됐다).
// 미리보기까지만 찍는다 — 🚫 [일괄 기입]은 누르지 않는다. 00가1121·1122 는 2차 마감된 데모라 마감 안 된 00가1501·00가1401 을 쓴다(1401 만 기존 80,000 을 잠깐 넣고 되돌림 — 「결과 = 기존+이번」이 보이게).
// 실행: NODE_PATH=<playwright-core 가 있는 node_modules> node ss_preview.cjs <출력 폴더>
const { chromium } = require('playwright-core');
const out = (process.argv[2] || __dirname + '/ss2') + '/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());   // 🚫 jin PC 의 실제 카톡 에이전트
  const p = await ctx.newPage();
  p.setDefaultTimeout(60000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/vehicles', { waitUntil: 'networkidle', timeout: 120000 });
  await p.getByRole('button', { name: '명세서 기입' }).click();
  await p.waitForTimeout(1500);
  await p.locator('textarea[wire\\:model="costImportRaw"]').fill('00가1501 탁송 150,000\n00가1401 탁송 120,000');
  await p.locator('button[wire\\:click="parseCostImport"]').click();
  await p.waitForTimeout(2000);
  const modal = p.locator('[wire\\:click="applyCostImport"]').first().locator('xpath=ancestor::div[contains(@class,"rounded")][1]/..');
  await p.screenshot({ path: out + 'b4-preview-full.png' });
  console.log((await p.locator('body').innerText()).match(/매칭[\s\S]{0,300}/)?.[0]?.replace(/\s+/g, ' '));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
