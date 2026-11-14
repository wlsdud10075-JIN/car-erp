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

  // B. 수출신고
  await open();
  const dn = p.locator('input[wire\\:model="export_declaration_number"]'); await dn.fill('999-99-999999');
  await p.locator('input[type=file][wire\\:model="exportDeclarationDocFile"]').setInputFiles(__dirname + '/sample-export-declaration.pdf');
  await p.waitForTimeout(2500);
  let bb = await (async()=>{ await dn.evaluate(e=>e.scrollIntoView({block:'center'})); await p.waitForTimeout(300); const x=await dn.boundingBox(); return {y:x.y-30}; })();
  await p.screenshot({ path: raw + 'b1-filled.png', clip: { ...panel, y: Math.max(0, bb.y), height: 260 } });
  await save('b');
  await rowShot('b');

  // C. 선적
  await open();
  const sd = p.locator('input[wire\\:model="shipping_date"]'); await sd.click(); await sd.pressSequentially('20261113'); await p.keyboard.press('Tab');
  await p.locator('input[wire\\:model="vessel_name"]').fill('GUIDE SAMPLE VESSEL');
  await p.waitForTimeout(800);
  bb = await (async()=>{ await sd.evaluate(e=>e.scrollIntoView({block:'center'})); await p.waitForTimeout(300); const x=await sd.boundingBox(); return {y:x.y-30}; })();
  await p.screenshot({ path: raw + 'c1-filled.png', clip: { ...panel, y: Math.max(0, bb.y), height: 200 } });
  await save('c');
  await rowShot('c');

  // D. ETA
  await open();
  const et = p.locator('input[wire\\:model="eta_date"]'); await et.click(); await et.pressSequentially('20261210'); await p.keyboard.press('Tab');
  await p.waitForTimeout(800);
  bb = await center(/^ETA$/);
  await p.screenshot({ path: raw + 'd1-filled.png', clip: { ...panel, y: Math.max(0, bb.y), height: 200 } });
  await save('d');
  await rowShot('d');

  // E. 통관 완료
  await open();
  await p.locator('input[type=checkbox][wire\\:model="is_export_cleared"]').check();
  await p.waitForTimeout(600);
  bb = await center(/^ETA$/);
  await p.screenshot({ path: raw + 'e1-filled.png', clip: { ...panel, y: Math.max(0, bb.y), height: 200 } });
  await save('e');
  await rowShot('e');
  await open();
  await p.screenshot({ path: raw + 'f-final-top.png', clip: { ...panel, y: 0, height: 1000 } });
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
