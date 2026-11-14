const { chromium } = require('playwright-core');
const raw = __dirname + '/ba/';
require('fs').mkdirSync(raw, { recursive: true });
const BUYER = 'GUIDE SAMPLE TRADING';
const PLATE = '00가1131';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 })).newPage();
  p.setDefaultTimeout(90000);
  p.on('dialog', d => { console.log('dialog:', d.message().slice(0, 200)); d.accept(); });
  const body = async (re) => { const t = await p.locator('body').innerText(); const m = t.match(re); return m ? m[0] : '(없음)'; };
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'finance@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);

  // ① 바이어 → 현금 탭 → 입금 기재
  await p.goto('http://localhost:8001/erp/buyers?search=' + encodeURIComponent(BUYER), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: BUYER }).first().click();
  await p.waitForTimeout(2500);
  await p.locator('button', { hasText: /^현금$/ }).first().click();
  await p.waitForTimeout(1000);
  await p.screenshot({ path: raw + 'a1-cash-empty.png' });
  await p.locator('input[wire\\:model="cash_date"]').fill('2026-11-14');
  await p.locator('select[wire\\:model="cash_currency"]').selectOption('USD');
  await p.locator('input[wire\\:model="cash_amount"]').fill('3000');
  await p.locator('input[wire\\:model="cash_note"]').fill('11/14 송금 (가이드 예시)');
  await p.screenshot({ path: raw + 'a2-cash-filled.png' });
  await p.getByRole('button', { name: '입금 기재' }).click();
  await p.waitForTimeout(2500);
  console.log('cash:', await body(/[^\n]*(기재|추가)했[^\n]*/));
  await p.screenshot({ path: raw + 'a3-cash-saved.png' });

  // ② 채권관리 — 입금 2,000 (판매 잔금 생성)
  await p.goto('http://localhost:8001/erp/receivables', { waitUntil: 'networkidle', timeout: 120000 });
  await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
  await p.getByRole('button', { name: '조회', exact: true }).click();
  await p.waitForTimeout(2000);
  await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
  await p.waitForTimeout(2500);
  await p.locator('input[wire\\:model="hAmount"]').fill('2000');
  await p.locator('input[wire\\:model="hExchangeRate"]').fill('1460');
  await p.locator('textarea[wire\\:model="hNote"]').fill('11/14 송금분 일부');
  await p.getByRole('button', { name: '추가', exact: true }).click();
  await p.waitForTimeout(2500);

  // ③ 재무 처리 확정 — 현금에서 배분
  await p.goto('http://localhost:8001/erp/transfers?tabType=sale_payment&search=' + encodeURIComponent(PLATE), { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1500);
  await p.locator('tr', { hasText: PLATE }).first().locator('button', { hasText: '재무 처리 완료' }).first().click();
  await p.waitForTimeout(2000);
  await p.locator('button[wire\\:click="confirmPayment"]').first().click();
  await p.waitForTimeout(2500);
  console.log('confirm:', await body(/[^\n]*(확정|실패|부족)[^\n]*/));
  await p.screenshot({ path: raw + 'a4-confirmed.png' });

  // ④ 바이어 정산현황
  await p.goto('http://localhost:8001/erp/buyer-account', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(1200);
  await p.screenshot({ path: raw + 'a5-account-empty.png', fullPage: true });
  const cb = p.locator('input[placeholder]').filter({ hasText: '' }).first();
  await p.locator('[x-data] input[type=text]').first().click().catch(() => {});
  await p.keyboard.type('GUIDE SAMPLE');
  await p.waitForTimeout(1200);
  await p.locator('li, button, div[role=option]', { hasText: BUYER }).first().click().catch(e => console.log('pick err', e.message.slice(0, 80)));
  await p.waitForTimeout(2500);
  await p.screenshot({ path: raw + 'a6-account.png', fullPage: true });
  const t = await p.locator('body').innerText();
  const i = t.indexOf('남은 현금');
  console.log(t.slice(i, i + 1500));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
