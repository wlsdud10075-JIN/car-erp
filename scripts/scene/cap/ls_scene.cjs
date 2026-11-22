// 업무 가이드 「목록 화면 — 찾기·조회·정렬」(list-search-sort) 촬영 — 예시 계정(manage). 읽기만 한다(저장 없음).
const { chromium } = require(process.env.PW_CORE || 'C:/Users/User/AppData/Local/Temp/claude/a6613a99d460c33c/844bed06-9f0e-4a53-a3f6-2216dbe9d57d/scratchpad/pw/node_modules/playwright-core');
const raw = __dirname + '/ls/';
const B = 'http://localhost:8001';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 900 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());   // 카톡 에이전트 차단
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto(B + '/login');
  await p.fill('input[type=email]', 'manage@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  const hideAlarm = async () => { await p.evaluate(() => { for (const el of document.querySelectorAll('*')) { if (el.children.length === 0 && /통관서류 알람/.test(el.textContent || '')) { let a = el; while (a && getComputedStyle(a).position !== 'fixed') a = a.parentElement; if (a) a.style.display = 'none'; } } }); };
  const open = async (path) => { await p.goto(B + path, { waitUntil: 'networkidle', timeout: 120000 }); await p.waitForTimeout(1200); await hideAlarm(); };
  const sort = async (key) => { await p.locator(`button[wire\\:click="clickSort('${key}')"]`).first().click(); await p.waitForTimeout(1800); };
  const shot = async (name) => { await hideAlarm(); await p.screenshot({ path: raw + name }); console.log('shot', name); };

  // 1) 재고관리 — 담당자 칸에 글자를 치면 걸러진다(아직 조회 전)
  await open('/erp/inventory');
  const sm = p.locator('input[placeholder*="담당자"]').first();
  await sm.click();
  await sm.fill('김');
  await p.waitForTimeout(600);
  await shot('01-combo.png');

  // 2) 고르고 [조회]
  await p.locator('button', { hasText: '김영업' }).first().click();
  await p.getByRole('button', { name: '조회', exact: true }).first().click();
  await p.waitForTimeout(2000);
  await shot('02-searched.png');

  // 3) 차량관리 — 담당자 ▲ 뒤에 진행상태 ▲ 로 겹친다
  await open('/erp/vehicles');
  await sort('salesman_id');
  await sort('progress_status_cache');
  await shot('03-stacked.png');

  // 4) 정산관리 — 실지급액(계산 칸) 큰 것부터 · 상태는 업무 순서
  await open('/erp/settlements');
  await sort('actual_payout');
  await shot('04-settlement.png');

  // 5) 바이어 — 적립금(통화별로 묶고 금액순)
  await open('/erp/buyers');
  await sort('savings');
  await shot('05-savings.png');
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 600)); process.exit(1); });
