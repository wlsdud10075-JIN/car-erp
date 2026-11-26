// exchange-rate-closing 씬 (2026-11-26) — 재무(finance@)로 업무 대시보드 맨 위 「실시간 환율」·「마감환율」 두 줄 → [받아오기].
// [받아오기]는 로컬 DB 에 네이버 확정 환율만 다시 넣는다(다른 데이터 안 바뀜).
// 실행: NODE_PATH=<playwright-core 가 있는 node_modules> node fxc_scene.js <출력 폴더>
const { chromium } = require('playwright-core');
const out = (process.argv[2] || __dirname + '/fxc') + '/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());   // 🚫 jin PC 의 실제 카톡 에이전트
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(2500);

  const shot = async (name) => {
    const a = await p.locator('[data-fx-line]').first().boundingBox().catch(() => null);
    const c = await p.locator('[data-fx-closing]').first().boundingBox();
    const top = Math.min(a ? a.y : c.y, c.y) - 10;
    const bottom = c.y + c.height + 10;
    const left = Math.min(a ? a.x : c.x, c.x) - 10;
    await p.screenshot({ path: out + name, clip: { x: Math.max(0, left), y: Math.max(0, top), width: 1300, height: bottom - top } });
  };
  await shot('f1-lines.png');
  console.log((await p.locator('[data-fx-closing]').first().innerText()).replace(/\s+/g, ' '));

  await p.locator('[data-fx-closing-fetch]').first().evaluate(e => e.click());
  await p.waitForTimeout(6000);
  // 위쪽(제목·환율 두 줄·알림)만 — 아래 카드엔 샘플 DB 의 바이어·담당자 이름이 있다
  await p.screenshot({ path: out + 'f2-after.png', clip: { x: 235, y: 0, width: 1365, height: 195 } });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
