const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const unlock = () => console.log(execSync('php "' + __dirname + '/gate_prep.php" unlock', { cwd: 'C:/xampp/htdocs/car-erp' }).toString().trim());
const raw = __dirname + '/gate/';
require('fs').mkdirSync(raw, { recursive: true });
const PLATE = '00가1141';
const panel = { x: 460, y: 0, width: 820, height: 1000 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const login = async (email) => {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
    const p = await ctx.newPage();
    p.setDefaultTimeout(90000);
    p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', email);
    await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
    return p;
  };
  const open = async (p, tab) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    if (tab) { await p.click(`button[\\@click="tab = '${tab}'"]`); await p.waitForTimeout(900); }
  };
  const body = async (p, re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const save = async (p, tag) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(2500);
    for (let i = 0; i < 3; i++) { const m = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await m.count()) { console.log(tag, 'modal:', await m.first().innerText()); await m.first().click(); await p.waitForTimeout(2000); } }
    console.log(tag, 'msg:', await body(p, /[^\n]*(수정됐습니다|입금률|잠|차단|우회|필요)[^\n]*/));
    await p.screenshot({ path: raw + tag + '.png' });
  };

  // ① 수출통관 — 막힘
  const c = await login('clear@car-erp.test');
  await open(c, 'export');
  await c.screenshot({ path: raw + 'g1-export-blocked.png', clip: panel });
  const t1 = await c.locator('body').innerText();
  const i1 = t1.indexOf('입금');
  console.log('export text:', t1.slice(Math.max(0, t1.indexOf('수출 진입') - 100), t1.indexOf('수출 진입') + 400).replace(/\n+/g, ' | '));
  console.log('loading disabled:', await c.locator('select[wire\\:model="bl_loading_location"]').isDisabled());
  const opts = await c.locator('select[wire\\:model="bl_loading_location"] option').allInnerTexts();
  console.log('ports:', opts.slice(0, 5));
  if (!(await c.locator('select[wire\\:model="bl_loading_location"]').isDisabled())) {
    await c.locator('select[wire\\:model="bl_loading_location"]').selectOption({ index: 1 });
    await save(c, 'g2-save-blocked');
  }

  // 수출통관 창을 닫아 편집 잠금을 푼다(안 닫으면 관리가 읽기 전용으로 연다)
  await c.getByRole('button', { name: '취소' }).click().catch(() => {});
  await c.waitForTimeout(2000);
  await c.goto('http://localhost:8001/erp/dashboard', { waitUntil: 'networkidle', timeout: 120000 });
  unlock();
  // ② 관리 — 우회 승인
  const m = await login('manage@car-erp.test');
  await open(m, 'export');
  await m.getByText('관리/관리자 — 미입금 우회 승인').click();
  await m.waitForTimeout(600);
  await m.locator('select[wire\\:model="overrideStage"]').selectOption('shipping');
  await m.locator('input[wire\\:model="overrideReason"]').fill('바이어 잔금 11/20 송금 예정 확인(메일). 선박 일정상 반입 먼저 진행.');
  const ob = m.locator('button[wire\\:click="approveUnpaidOverride"]');
  await ob.scrollIntoViewIfNeeded();
  await m.screenshot({ path: raw + 'g3-override-filled.png' });
  await ob.click();
  await m.waitForTimeout(2500);
  console.log('override msg:', await body(m, /[^\n]*(승인|기록)[^\n]*/));
  await m.screenshot({ path: raw + 'g4-override-done.png' });

  unlock();
  // ③ 수출통관 — 다시
  await open(c, 'export');
  await c.screenshot({ path: raw + 'g5-export-open.png', clip: panel });
  console.log('loading disabled after:', await c.locator('select[wire\\:model="bl_loading_location"]').isDisabled());
  await c.locator('select[wire\\:model="bl_loading_location"]').selectOption({ index: 1 });
  await c.waitForTimeout(500);
  await save(c, 'g6-save-ok');
  await open(c, null);
  await c.screenshot({ path: raw + 'g7-reopen.png', clip: panel });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
