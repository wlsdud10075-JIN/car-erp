// 업무 가이드 「은행 서류」 씬 — 담아 두기 · 수정본 올리기 · 모아 보내기 (2026-11-26) · PDF 합치기 · 진행 띠 (11-27). 재무 계정, 예시 차 00가1151·00가1152.
// 🚫 [보내기]는 누르지 않는다. 🚫 127.0.0.1:47321(카톡 에이전트) 차단.
// 🔐 로컬에 예시가 아닌 은행 기록(실제 이름)이 있으면 찍기 전에 차에서 떼어 두고, 받는 사람 목록도 가짜로 바꿔 둔다(찍은 뒤 되돌린다).
//    새로 만든 기록의 송금인 이름은 중간에 호출 쪽이 가짜 다른 이름으로 바꾼다(MID 명령) — 목록에 두 건이 서로 다르게 보이게.
// 실행: NODE_PATH=<playwright-core 가 있는 node_modules> MID="<php 명령>" node bankbatch_scene.cjs <출력 폴더> <전신문 tif>
const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const fs = require('fs');
const out = process.argv[2] + '/', TIF = process.argv[3];
const B = 'http://localhost:8001';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  const noPopups = () => p.evaluate(() => document.querySelectorAll('body *').forEach(el => { const cs = getComputedStyle(el); if (cs.position === 'fixed' && /알람|알림/.test(el.innerText || '') && el.getBoundingClientRect().width < 700 && !el.closest('[data-bank-batch],[data-bank-modal],[data-bank-replace]')) el.remove(); }));
  await p.goto(B + '/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 2대 체크 → [은행 서류 일괄] → 전신문 → 이름 → 만들기 → 받는 사람 드롭박스 + 담아 두기 안내
  await p.goto(B + '/erp/vehicles?search=' + encodeURIComponent('00가115'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  for (const pl of ['00가1151', '00가1152']) await p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]').check();
  await p.waitForTimeout(1500);
  await p.locator('[data-bank-bulk]').click();
  await p.waitForSelector('[data-bank-modal]');
  console.log('dup notice:', await p.locator('[data-bank-dup]').count());
  await p.locator('[data-bank-modal] input[type=file]').setInputFiles(TIF);
  await p.waitForSelector('[data-bank-ocr]', { timeout: 120000 });
  await p.waitForTimeout(1200);
  console.log('ocr', await p.locator('[data-bank-ocr]').getAttribute('data-bank-ocr'));
  await p.locator('[data-bank-name]').fill('GOLDEN BRIDGE TRADING LLC');
  await p.locator('[data-bank-create]').click();
  await p.waitForSelector('[data-bank-done]', { timeout: 90000 });
  await p.waitForTimeout(1000);
  await p.locator('[data-bank-to] input').click();
  await p.waitForTimeout(700);
  await noPopups();
  await p.locator('[data-bank-modal] .card').first().screenshot({ path: out + '05.png' });
  await p.keyboard.press('Escape').catch(() => {});
  await p.waitForTimeout(500);
  if (await p.locator('[data-bank-modal]').count()) { await p.locator('[data-bank-modal] button[wire\\:click="closeBank"]').last().click(); }
  await p.waitForTimeout(1500);
  if (process.env.MID) console.log(execSync(process.env.MID).toString().trim());

  // ② 차 창 서류탭 → 새 기록의 [수정본 올리기] → 받은 파일 이름만 바꿔 올리기 → 은행 영역
  await p.goto(B + '/erp/vehicles?search=' + encodeURIComponent('00가1151'), { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('tr', { hasText: '00가1151' }).first().locator('td').nth(1).click();
  await p.waitForTimeout(3000);
  await p.click(`button[\\@click="tab = 'docs'"]`);
  await p.waitForTimeout(1200);
  const area = p.locator('[data-bank-area]').first();
  await area.scrollIntoViewIfNeeded();
  const href = await area.locator('a[data-bank-file]').nth(1).getAttribute('href');
  const res = await p.request.get(href);
  const fixed = out + 'BANK_Invoice_2대_금액수정.xlsx';
  fs.writeFileSync(fixed, await res.body());
  await area.locator('[data-bank-replace-open]').first().click();
  await p.waitForSelector('[data-bank-replace]');
  await p.waitForTimeout(800);
  await p.locator('[data-bank-replace] .card').first().screenshot({ path: out + '07.png' });
  await p.locator('[data-bank-replace] input[type=file]').setInputFiles(fixed);
  await p.waitForSelector('[data-bank-replace]', { state: 'detached', timeout: 60000 });
  await p.waitForSelector('[data-bank-area] [data-bank-revised]', { timeout: 60000 });
  await p.waitForTimeout(1500);
  await noPopups();
  const open = await p.locator('[data-bank-open]').boundingBox(), ab = await area.boundingBox();
  await p.screenshot({ path: out + '06.png', clip: { x: ab.x - 8, y: open.y - 70, width: ab.width + 16, height: ab.y + ab.height - open.y + 80 } });

  // ③ 목록 위 [은행 서류 보내기] → 모아 보내기 창
  await p.goto(B + '/erp/vehicles', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(2000);
  await noPopups();
  const btn = p.locator('[data-bank-batch-open]').first();
  await btn.scrollIntoViewIfNeeded();
  const bb = await btn.boundingBox();
  await p.mouse.move(5, 5);
  await p.screenshot({ path: out + '08.png', clip: { x: Math.max(0, bb.x - 520), y: bb.y - 24, width: Math.min(1280 - Math.max(0, bb.x - 520), 900), height: bb.height + 48 } });
  await btn.click();
  await p.waitForSelector('[data-bank-batch]');
  await p.waitForTimeout(1500);
  // ④ PDF 한 파일로 합치기 → 「합침」 + [내려받아 확인] (2026-11-27)
  await p.locator('[data-bank-merge-btn]').click();
  await p.waitForSelector('[data-bank-merge-ready]', { timeout: 180000 });
  await p.waitForTimeout(1500);
  await noPopups();
  await p.locator('[data-bank-batch] .card').first().screenshot({ path: out + '09.png' });
  console.log('rows', await p.locator('[data-bank-batch-row]').count(), '|', await p.locator('[data-bank-merge-ready]').innerText());
  // ⑤ 다시 합치는 동안 창을 닫으면 화면 위 가운데에 진행 띠 — 찍고 나서 취소
  await p.locator('[data-bank-merge-btn]').click();
  await p.waitForSelector('[data-bank-merge-cancel]', { state: 'visible' });
  await p.keyboard.press('Escape');
  await p.waitForSelector('[data-bank-batch]', { state: 'detached' });
  await p.waitForTimeout(700);
  await noPopups();
  const chip = await p.locator('[data-bank-merge-chip]').boundingBox();
  await p.screenshot({ path: out + '10.png', clip: { x: Math.max(0, chip.x - 260), y: 0, width: chip.width + 520, height: chip.y + chip.height + 28 } });
  await p.locator('[data-bank-merge-chip-cancel]').click();
  await p.waitForFunction(() => ! Alpine.store('bankMerge').merging, null, { timeout: 60000 });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 700)); process.exit(1); });
