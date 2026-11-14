const { chromium } = require('playwright-core');
const raw = __dirname + '/rv/';
const PLATE = '00가1131';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(60000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const panelText = async (tag) => {
    const t = await p.locator('body').innerText();
    const m = t.match(/미납금\s*\n\s*USD [\d,]+[^\n]*/);
    console.log(tag, '미납:', m ? m[0].replace(/\s+/g, ' ') : '(없음)');
  };
  const open = async () => {
    await p.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
    await p.getByRole('button', { name: '조회', exact: true }).click();
    await p.waitForTimeout(2000);
  };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 목록
  await p.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
  await p.screenshot({ path: raw + 'r1-top-all.png', fullPage: true });
  await open();
  await p.screenshot({ path: raw + 'r2-list.png', fullPage: true });

  // ② 패널 + 채권담당자 지정
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'r3-panel.png' });
  await p.locator('select[wire\\:model="managerIdInput"]').selectOption({ label: '재무담당 (user)' });
  await p.getByRole('button', { name: '지정', exact: true }).click();
  await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 'r4-assigned.png' });

  // ③ 입금 추가
  await p.locator('input[wire\\:model="hAmount"]').fill('2000');
  await p.locator('input[wire\\:model="hExchangeRate"]').fill('1450');
  await p.locator('textarea[wire\\:model="hNote"]').fill('11/14 송금 확인');
  await p.screenshot({ path: raw + 'r5-deposit-filled.png' });
  await p.getByRole('button', { name: '추가', exact: true }).click();
  await p.waitForTimeout(2500);
  await panelText('after-deposit');
  await p.screenshot({ path: raw + 'r6-deposit-saved.png' });

  // ④ 현금 추가
  await p.locator('select[wire\\:model="hMethod"], select[wire\\:model\\.live="hMethod"]').first().selectOption('cash');
  await p.waitForTimeout(800);
  await p.locator('input[wire\\:model="hAmount"]').fill('500');
  await p.locator('input[wire\\:model="hExchangeRate"]').fill('1450').catch(() => {});
  await p.locator('textarea[wire\\:model="hNote"]').fill('현지 대리인 현금 수령');
  await p.screenshot({ path: raw + 'r7-cash-filled.png' });
  await p.getByRole('button', { name: '추가', exact: true }).click();
  await p.waitForTimeout(2500);
  await panelText('after-cash');
  await p.screenshot({ path: raw + 'r8-cash-saved.png' });
  const t = await p.locator('body').innerText();
  const i = t.indexOf('회수 이력 목록');
  console.log(t.slice(i, i + 700));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
