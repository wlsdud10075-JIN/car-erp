// b2b-partner-memo 씬 (2026-11-26) — 업무관리자(김업무)로 B2B 파트너스 → 데모 바이어 → B2B 메모 → 저장 → 목록 열.
// 데모 바이어 = 「B2B데모 파트너 LLC」(id 32, 연락처 전부 가짜). 원래 메모는 촬영 뒤 되돌린다(호출 쪽에서 DB 로).
// 실행: NODE_PATH=<playwright-core 가 있는 node_modules> node b2bm_scene.js <출력 폴더>
const { chromium } = require('playwright-core');
const out = (process.argv[2] || __dirname + '/b2bm') + '/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());   // 🚫 jin PC 의 실제 카톡 에이전트
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  await p.goto('http://localhost:8001/erp/buyers?b2b=1', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: 'B2B데모 파트너 LLC' }).first().click();
  await p.waitForTimeout(2500);
  const box = p.locator('[data-buyer-b2b]').first();
  await box.scrollIntoViewIfNeeded();
  const ta = box.locator('textarea[wire\\:model="b2b_memo"]').first();
  await ta.fill('2027 연간 약정 갱신 협의 중 — 12월 둘째 주 미팅\n선적 서류 원본은 DHL 로 사무실 주소에 보냄');
  await p.waitForTimeout(500);
  await box.screenshot({ path: out + 'm1-drawer.png' });

  await p.locator('button[wire\\:click="save"]').first().evaluate(e => e.click());
  await p.waitForTimeout(3000);
  await p.keyboard.press('Escape');
  await p.goto('http://localhost:8001/erp/buyers?b2b=1', { waitUntil: 'networkidle' });
  await p.waitForTimeout(4000);
  // 알람 팝업(고정 위치 토스트)·행 hover 색이 메모를 가린다 → 팝업은 지우고 마우스는 표 밖으로
  await p.evaluate(() => document.querySelectorAll('body *').forEach(el => { const cs = getComputedStyle(el); if (cs.position === 'fixed' && /알람|알림/.test(el.innerText || '') && el.getBoundingClientRect().width < 700) el.remove(); }));
  await p.mouse.move(5, 995);
  await p.waitForTimeout(500);
  await p.locator('table').first().screenshot({ path: out + 'm2-list.png' });
  console.log((await p.locator('[data-buyer-b2b-memo-row]').first().innerText()).slice(0, 200));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
