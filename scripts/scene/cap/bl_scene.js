const { chromium } = require('playwright-core');
const raw = __dirname + '/bl/';
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const open = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'bl'"]`);
    await p.waitForTimeout(800);
  };
  const save = async (n) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(2000);
    for (let i = 0; i < 3; i++) {
      const modal = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ });
      if (await modal.count()) { console.log(n, 'modal:', await modal.first().innerText()); await modal.first().click(); await p.waitForTimeout(2000); }
    }
    console.log(n, 'after:', await body(/[^\n]*(수정됐습니다|저장|막|차단|필요|발행)[^\n]*/));
    await p.screenshot({ path: raw + n + '-after-full.png' });
  };

  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 체크빌 먼저
  await open();
  await p.screenshot({ path: raw + 's1-tab-full.png', clip: { ...panel, height: 1000 } });
  await p.locator('input[type=file][wire\\:model="checkbillFile"]').setInputFiles(__dirname + '/sample-checkbill.pdf');
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 's2-checkbill-picked-full.png', clip: { ...panel, height: 1000 } });
  await save('s3');

  // ② 최종 B/L
  await open();
  await p.screenshot({ path: raw + 's4-reopen-full.png', clip: { ...panel, height: 1000 } });
  await p.locator('input[wire\\:model="bl_number"]').fill('GSBL1113001');
  const d = p.locator('input[wire\\:model="bl_issue_date"]');
  await d.click(); await d.pressSequentially('20261113'); await p.keyboard.press('Tab');
  await p.waitForTimeout(500);
  await p.locator('select[wire\\:model\\.live="bl_type"]').selectOption('surrender');
  await p.waitForTimeout(1200);
  await p.locator('input[type=file][wire\\:model="blDocFile"]').setInputFiles(__dirname + '/sample-bl.pdf');
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 's5-filled-full.png', clip: { ...panel, height: 1000 } });
  await save('s6');

  // ③ 결과 — 다시 열어 진행 줄, 목록 줄
  await open();
  await p.screenshot({ path: raw + 's7-done-panel-full.png', clip: { ...panel, height: 1000 } });
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  console.log('row:', (await p.locator('tr', { hasText: PLATE }).first().innerText()).replace(/\s+/g, ' ').slice(0, 200));
  await p.screenshot({ path: raw + 's8-list-full.png' });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
