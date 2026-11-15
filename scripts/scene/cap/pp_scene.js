const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const raw = __dirname + '/pp/';
require('fs').mkdirSync(raw, { recursive: true });
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
const toggle = (s) => console.log(execSync(`php "${__dirname}/altoggle.php" ${s}`, { cwd: 'C:/xampp/htdocs/car-erp' }).toString().trim());
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  const shot = (n, h = 1000, y = 0) => p.screenshot({ path: raw + n + '.png', clip: { ...panel, y, height: Math.min(h, 1000 - y) } });
  const toast = async () => { const t = await p.locator('body').innerText(); const m = t.match(/[^\n]*(수정됐습니다|확정|발송|저장)[^\n]*/g); return m ? m.slice(0, 4).join(' | ') : ''; };
  const openVehicle = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'purchase'"]`);
    await p.waitForTimeout(600);
  };
  const toPay = async () => { const l = p.getByText(/^매입 지급$/).first(); await l.scrollIntoViewIfNeeded(); await p.waitForTimeout(300); const bb = await l.boundingBox(); return Math.max(0, bb.y - 16); };
  try {
    await p.goto('http://localhost:8001/login');
    await p.fill('input[type=email]', 'finance@car-erp.test');
    await p.fill('input[type=password]', 'password');
    await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

    // ① 지급 넣기
    await openVehicle();
    let y = await toPay();
    await shot('a1-empty', 420, y);
    const down = p.locator('input[wire\\:model="down_payment_str"]'); await down.click(); await down.pressSequentially('1000000');
    const sfp = p.locator('input[wire\\:model="selling_fee_payment_str"]'); await sfp.click(); await sfp.pressSequentially('300000');
    await p.locator('button[wire\\:click="addPurchasePayment"]').click();
    await p.waitForTimeout(800);
    const amt = p.locator('input[wire\\:model="purchaseBalancePayments.0.amount"]'); await amt.click(); await amt.pressSequentially('9000000');
    const dt = p.locator('input[wire\\:model="purchaseBalancePayments.0.payment_date"]'); await dt.click(); await dt.pressSequentially('20261113'); await p.keyboard.press('Tab');
    await p.locator('input[wire\\:model="purchaseBalancePayments.0.note"]').fill('잔금');
    await p.waitForTimeout(500);
    y = await toPay();
    await shot('a2-filled', 420, y);
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'a3-after-save-full.png' });
    console.log('save toast:', await toast());
    await p.waitForTimeout(3000);
    await openVehicle();
    y = await toPay();
    await shot('a4-pending', 520, y);

    // ② 재무 처리에서 확정
    await p.goto('http://localhost:8001/erp/transfers?tabType=purchase_payment', { waitUntil: 'networkidle' });
    await p.locator('input[placeholder*="차량번호"]').first().fill(PLATE);
    await p.getByRole('button', { name: '조회', exact: true }).click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'b1-list.png', clip: { x: 220, y: 0, width: 1060, height: 720 } });
    for (let i = 0; i < 3; i++) {
      const row = p.locator('tr', { hasText: PLATE }).filter({ has: p.getByRole('button', { name: '재무 처리 완료' }) });
      const n = await row.count();
      if (n === 0) break;
      // 첫 번째로 잔금(9,000,000) 행의 모달을 찍는다
      const target = i === 0 ? row.filter({ hasText: '9,000,000' }).first() : row.first();
      await target.getByRole('button', { name: '재무 처리 완료' }).click();
      await p.waitForTimeout(1200);
      if (i === 0) {
        await p.screenshot({ path: raw + 'b2-modal-full.png' });
        console.log('modal buttons', JSON.stringify(await p.$$eval('button:visible', e => e.map(x => x.innerText.trim()).filter(Boolean).slice(-8))));
      }
      const btns = p.locator('button:visible', { hasText: /확정|완료/ });
      const last = btns.last();
      console.log('click', await last.innerText());
      await last.click();
      await p.waitForTimeout(1500);
      console.log('confirm toast:', await toast());
    }
    await p.goto('http://localhost:8001/erp/transfers?tabType=purchase_payment&statusFilter=executed', { waitUntil: 'networkidle' });
    await p.locator('input[placeholder*="차량번호"]').first().fill(PLATE);
    await p.getByRole('button', { name: '조회', exact: true }).click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'b3-done.png', clip: { x: 220, y: 0, width: 1060, height: 720 } });

    // ③ 딜러 알림 (캡처 동안만 켠다 — 발송 버튼은 누르지 않는다)
    toggle('on');
    await openVehicle();
    y = await toPay();
    await shot('c1-confirmed', 560, y);
    const ph = p.locator('input[wire\\:model\\.blur="deregistrationBuyerPhone"]');
    await ph.scrollIntoViewIfNeeded(); await ph.fill('010-1234-5678'); await p.keyboard.press('Tab'); await p.waitForTimeout(800);
    const pb = await p.getByText('국내 딜러 알림톡').first().boundingBox();
    await shot('c2-phone', 200, Math.max(0, pb.y - 16));
    y = await toPay();
    await p.locator('button[wire\\:click^="openPaidNotice(\'balance\'"]').first().click();
    await p.waitForTimeout(1500);
    await p.screenshot({ path: raw + 'c3-modal-full.png' });
    console.log('paid modal buttons', JSON.stringify(await p.$$eval('button:visible', e => e.map(x => x.innerText.trim()).filter(Boolean).slice(-6))));
    await p.locator('button[wire\\:click="cancelPaidNotice"]').first().click();
    await p.waitForTimeout(800);
  } finally {
    toggle('off');
  }
  // ④ 목록
  await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
  const row = p.locator('tr', { hasText: PLATE }).first();
  const hb = await p.locator('thead').first().boundingBox(), rb = await row.boundingBox();
  console.log('row', (await row.innerText()).replace(/\s+/g, ' '));
  await p.screenshot({ path: raw + 'd1-row.png', clip: { x: 220, y: hb.y - 8, width: 700, height: rb.y + rb.height - hb.y + 16 } });
  await b.close();
})().catch(e => { console.error(e); try { toggle('off'); } catch (x) {} process.exit(1); });
