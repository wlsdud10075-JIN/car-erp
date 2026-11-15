const { chromium } = require('playwright-core');
const raw = __dirname + '/kk/';
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1600, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  // 🚫 jin PC 의 실제 카톡 에이전트와 말하지 않는다
  await ctx.route(/127\.0\.0\.1:47321|localhost:47321/, r => r.abort());
  const p = await ctx.newPage();
  p.setDefaultTimeout(90000);
  const hide = () => p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'guide.manager@car-erp.test');
  await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await p.goto('http://localhost:8001/erp/kakao', { waitUntil: 'networkidle', timeout: 120000 });
  await p.waitForTimeout(2500); await hide();
  const picker = p.locator('[data-kakao-topic-picker] > div').first();
  if (await picker.count()) {
    await p.locator('[data-topic-pick="전체"]').click();
    await p.waitForTimeout(400);
    await picker.screenshot({ path: raw + 'k1-picker.png' });
    await p.locator('[data-topic-save]').click();
    await p.waitForTimeout(2500); await hide();
  }
  await p.screenshot({ path: raw + 'k2-page.png', fullPage: true });
  const bucket = k => p.locator(`[data-bucket="${k}"]`).first();
  for (const k of ['missed', 'now']) { await bucket(k).locator('button').first().click(); await p.waitForTimeout(500); }
  await p.screenshot({ path: raw + 'k3-now.png', fullPage: true });
  // 미루기 폼
  const row = bucket('now').locator('[data-row]').nth(1);
  await row.getByRole('button', { name: '미루기' }).click();
  await p.waitForTimeout(2000); await hide();
  await bucket('now').screenshot({ path: raw + 'k4-snooze.png' });
  console.log((await bucket('now').innerText()).slice(0, 1200));
  await p.reload({ waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide();
  for (const k of ['waiting', 'teammate', 'done']) { await bucket(k).locator('button').first().click(); await p.waitForTimeout(500); }
  await p.screenshot({ path: raw + 'k5-wait.png', fullPage: true });
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 400)); process.exit(1); });
