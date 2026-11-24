// 업무 가이드 「은행 서류」 씬 촬영 — 재무 계정, 예시 차 00가1151·00가1152(GUIDE SAMPLE TRADING), 연습용 전신문(가짜 이름).
// 🚫 [은행에 보내기]는 누르지 않는다. 🚫 127.0.0.1:47321(카톡 에이전트) 차단.
const { chromium } = require('playwright-core');
const out = __dirname + '/bank/';
require('fs').mkdirSync(out, { recursive: true });
const TIF = __dirname + '/bank/sample_wire_advice.tif';   // 먼저 python bank_make_tif.py bank/sample_wire_advice.tif

(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 목록에서 2대 체크 → 막대의 [은행 서류 일괄]
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가115'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  for (const pl of ['00가1151', '00가1152']) await p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]').check();
  await p.waitForTimeout(1500);
  const bulk = p.locator('[data-bank-bulk]');
  await bulk.scrollIntoViewIfNeeded();
  const bb = await bulk.boundingBox();
  console.log('bulk box', bb);
  await p.screenshot({ path: out + '01-bar.png', clip: { x: 0, y: Math.max(0, bb.y - 120), width: 1280, height: 260 } });

  // ② 모달 열기 → 전신문 올리기 → 읽는 중 가림막
  await bulk.click();
  await p.waitForSelector('[data-bank-modal]');
  await p.waitForTimeout(800);
  await p.screenshot({ path: out + '02-modal-empty.png' });
  await p.locator('[data-bank-modal] input[type=file]').setInputFiles(TIF);
  await p.waitForSelector('[data-bank-reading]', { state: 'visible', timeout: 15000 }).catch(() => console.log('reading overlay (x-show) not caught'));
  await p.waitForTimeout(400);
  await p.screenshot({ path: out + '03-reading.png' });

  // ③ 읽기 끝 — 이름 채워짐 + 쪽 그림
  await p.waitForSelector('[data-bank-ocr]', { timeout: 90000 });
  await p.waitForTimeout(1200);
  console.log('ocr status', await p.locator('[data-bank-ocr]').getAttribute('data-bank-ocr'), 'name', await p.locator('[data-bank-name]').inputValue());
  await p.screenshot({ path: out + '04-read.png' });

  // ④ 3쪽 그림을 눌러 보기 창 → 휠로 확대
  await p.locator('[data-bank-previews] button').nth(2).click();
  await p.waitForSelector('[data-bank-lightbox]', { state: 'visible' });
  await p.waitForTimeout(600);
  await p.screenshot({ path: out + '05-viewer.png' });
  const img = await p.locator('[data-bank-lb-img]').boundingBox();
  await p.mouse.move(img.x + img.width * 0.35, img.y + img.height * 0.2);
  for (let i = 0; i < 5; i++) { await p.mouse.wheel(0, -200); await p.waitForTimeout(120); }
  await p.waitForTimeout(500);
  await p.screenshot({ path: out + '06-viewer-zoom.png' });
  await p.keyboard.press('Escape');
  await p.waitForTimeout(500);

  // ⑤ 만들기 → 받는 사람 드롭박스 · 내려받기 버튼
  await p.locator('[data-bank-create]').click();
  await p.waitForSelector('[data-bank-done]', { timeout: 90000 });
  await p.waitForTimeout(1000);
  await p.locator('[data-bank-to] input').click();
  await p.waitForTimeout(600);
  await p.screenshot({ path: out + '07-done-recipient.png' });
  await p.keyboard.press('Escape').catch(() => {});

  // ⑥ 나중에 → 차 창 서류탭 「은행」 영역
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent('00가1151'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('tr', { hasText: '00가1151' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.click(`button[\\@click="tab = 'docs'"]`);
  await p.waitForTimeout(1000);
  const area = p.locator('[data-bank-open]');
  await area.scrollIntoViewIfNeeded();
  await p.waitForTimeout(500);
  await p.screenshot({ path: out + '08-docs-tab.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 600)); process.exit(1); });
