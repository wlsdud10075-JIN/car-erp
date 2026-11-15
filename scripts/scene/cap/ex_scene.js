const { chromium } = require('playwright-core');
const raw = __dirname + '/ex/';
const PLATE = '00가1113';
const panel = { x: 460, y: 0, width: 820 };
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  const top = () => p.locator('.overflow-y-auto').evaluateAll(els => els.forEach(e => e.scrollTop = 0));
  const center = async (txt) => { const l = p.getByText(txt).first(); await l.evaluate(e => e.scrollIntoView({ block: 'center' })); await p.waitForTimeout(300); return l.boundingBox(); };
  const open = async () => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
    await p.waitForTimeout(2500);
    await p.click(`button[\\@click="tab = 'export'"]`);
    await p.waitForTimeout(800);
  };
  const rowShot = async (n) => {
    await p.goto('http://localhost:8001/erp/vehicles?search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle' });
    const row = p.locator('tr', { hasText: PLATE }).first();
    console.log(n, 'row:', (await row.innerText()).replace(/\s+/g, ' ').slice(0, 90));
    await p.screenshot({ path: raw + n + '-list-full.png' });
  };
  const save = async (n) => {
    await p.getByRole('button', { name: '수정 저장' }).click();
    await p.waitForTimeout(1800);
    for (let i = 0; i < 3; i++) {
      const modal = p.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ });
      if (await modal.count()) {
        await p.screenshot({ path: raw + `${n}-modal${i}-full.png` });
        console.log(n, 'modal:', await modal.first().innerText());
        await modal.first().click(); await p.waitForTimeout(1800);
      }
    }
    await p.screenshot({ path: raw + `${n}-after-full.png` });
    console.log(n, 'after:', await body(/[^\n]*(수정됐습니다|막|차단|필요)[^\n]*/));
    await p.waitForTimeout(3000);
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // 0. 말소 (매입 탭) — 말소가 안 끝나면 수출 탭 저장이 막힌다
  await open();
  await p.click(`button[\\@click="tab = 'basic'"]`);
  await p.waitForTimeout(600);
  const rrn = p.locator('input[wire\\:model\\.blur="nice_reg_owner_rrn"]');
  await rrn.fill('000000-0000000');
  await rrn.evaluate(e => e.scrollIntoView({ block: 'center' })); await p.waitForTimeout(300);
  const rb = await rrn.boundingBox();
  await p.screenshot({ path: raw + 'z0-rrn.png', clip: { ...panel, y: Math.max(0, rb.y - 120), height: 220 } });
  await p.click(`button[\\@click="tab = 'purchase'"]`);
  await p.waitForTimeout(600);
  await p.locator('input[type=checkbox][wire\\:model="is_deregistered"]').check();
  await p.locator('input[type=file][wire\\:model="deregistrationDocFile"]').setInputFiles(__dirname + '/sample-deregistration.pdf');
  await p.waitForTimeout(2500);
  const drd = p.locator('input[wire\\:model="deregistration_date"]'); await drd.click(); await drd.pressSequentially('20261113'); await p.keyboard.press('Tab');
  await p.waitForTimeout(600);
  let b0 = await center(/^말소증명서$/);
  await p.screenshot({ path: raw + 'z1-dereg.png', clip: { ...panel, y: Math.max(0, b0.y - 30), height: 560 } });
  await save('z');
  await open();
  await p.screenshot({ path: raw + 'z2-top.png', clip: { ...panel, y: 0, height: 200 } });

  // A. 반입
  await open();
  await p.screenshot({ path: raw + 'a0-top.png', clip: { ...panel, y: 0, height: 520 } });
  await p.locator('button[wire\\:click="openQuickAdd(\'consignee\',\'bl\')"]').click();
  await p.waitForTimeout(1200);
  await p.locator('input[wire\\:model="qaName"]').fill('GUIDE SAMPLE CONSIGNEE');
  await p.screenshot({ path: raw + 'a1-cons-modal-full.png' });
  await p.locator('button[wire\\:click="saveQuickAdd"]').click();
  await p.waitForTimeout(1500);
  await p.locator('select[wire\\:model="bl_loading_location"], select[wire\\:model\\.live="bl_loading_location"]').first().selectOption({ label: 'HJIT (020-12-016)' }).catch(async () => { const s = p.locator('select[wire\\:model="bl_loading_location"], select[wire\\:model\\.live="bl_loading_location"]').first(); const o = await s.locator('option').nth(1).getAttribute('value'); await s.selectOption(o); });
  await p.locator('select[wire\\:model="forwarding_company_id_str"], select[wire\\:model\\.live="forwarding_company_id_str"]').first().selectOption({ label: '부산포워딩㈜' });
  const dd = p.locator('input[wire\\:model="document_deadline_date"]'); await dd.click(); await dd.pressSequentially('20261118'); await p.keyboard.press('Tab');
  await p.locator('select[wire\\:model="shipping_method"], select[wire\\:model\\.live="shipping_method"]').first().selectOption('RORO');
  await p.locator('select[wire\\:model="port_of_loading"], select[wire\\:model\\.live="port_of_loading"]').first().selectOption({ label: 'BUSAN, KOREA' });
  await p.locator('select[wire\\:model="incoterms"], select[wire\\:model\\.live="incoterms"]').first().selectOption('CFR');
  await p.waitForTimeout(1200);
  await top(); await p.waitForTimeout(300);
  await p.screenshot({ path: raw + 'a2-filled.png', clip: { ...panel, y: 0, height: 560 } });
  await save('a');
  await rowShot('a');

  // B. 수출신고
  await open();
  const dn = p.locator('input[wire\\:model="export_declaration_number"]'); await dn.fill('999-99-999999');
  await p.locator('input[type=file][wire\\:model="exportDeclarationDocFile"]').setInputFiles(__dirname + '/sample-export-declaration.pdf');
  await p.waitForTimeout(2500);
  let bb = await center('수출신고번호');
  await p.screenshot({ path: raw + 'b1-filled.png', clip: { ...panel, y: Math.max(0, bb.y - 20), height: 260 } });
  await save('b');
  await rowShot('b');

  // C. 선적
  await open();
  const sd = p.locator('input[wire\\:model="shipping_date"]'); await sd.click(); await sd.pressSequentially('20261113'); await p.keyboard.press('Tab');
  await p.locator('input[wire\\:model="vessel_name"]').fill('GUIDE SAMPLE VESSEL');
  await p.waitForTimeout(800);
  bb = await center(/^선적일/);
  await p.screenshot({ path: raw + 'c1-filled.png', clip: { ...panel, y: Math.max(0, bb.y - 20), height: 200 } });
  await save('c');
  await rowShot('c');

  // D. ETA
  await open();
  const et = p.locator('input[wire\\:model="eta_date"]'); await et.click(); await et.pressSequentially('20261210'); await p.keyboard.press('Tab');
  await p.waitForTimeout(800);
  bb = await center(/^ETA$/);
  await p.screenshot({ path: raw + 'd1-filled.png', clip: { ...panel, y: Math.max(0, bb.y - 90), height: 200 } });
  await save('d');
  await rowShot('d');

  // E. 통관 완료
  await open();
  await p.locator('input[type=checkbox][wire\\:model="is_export_cleared"]').check();
  await p.waitForTimeout(600);
  bb = await center(/^ETA$/);
  await p.screenshot({ path: raw + 'e1-filled.png', clip: { ...panel, y: Math.max(0, bb.y - 90), height: 200 } });
  await save('e');
  await rowShot('e');
  await open();
  await p.screenshot({ path: raw + 'f-final-top.png', clip: { ...panel, y: 0, height: 1000 } });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
