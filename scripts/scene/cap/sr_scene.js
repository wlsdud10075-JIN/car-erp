const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const raw = __dirname + '/sr/';
const send = (method, path, body) => console.log('API', execFileSync('php', ['C:/Users/User/AppData/Local/Temp/claude/a6613a99d460c33c/3f118698-9561-417d-bf77-e8317e3a2e7a/scratchpad/scene/board_send.php', method, path, body], { cwd: 'C:/xampp/htdocs/car-erp' }).toString().trim().slice(0, 200));
const batch = '3d26a4c0-726f-4fcf-bcbd-98bf858da0c8';
console.log('batch', batch);
(async () => {
  const b = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'ko-KR', deviceScaleFactor: 1.5 });
  await ctx.route(/127\.0\.0\.1:47321/, r => r.abort());
  const p = await ctx.newPage(); p.setDefaultTimeout(60000);
  const hide = () => p.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'fixed' && /통관서류 알람/.test(e.innerText || '')) e.style.display = 'none'; }); });
  const go = async () => { await p.goto('http://localhost:8001/erp/shipping-requests', { waitUntil: 'networkidle' }); await p.waitForTimeout(2000); await hide(); };
  const card = () => p.locator('div', { hasText: '00가1141' }).filter({ hasText: '요청 영업' }).last();
  await p.goto('http://localhost:8001/login');
  await p.fill('input[type=email]', 'clear@car-erp.test'); await p.fill('input[type=password]', 'password');
  await Promise.all([p.waitForLoadState('networkidle'), p.keyboard.press('Enter')]);
  await go();
  await p.screenshot({ path: raw + 's1-list.png', fullPage: true });
  await p.goto('http://localhost:8001/erp/shipping-requests', { waitUntil: 'networkidle' }); await p.waitForTimeout(1500); await hide();
  // ⋯ → 착수
  const c = card();
  await c.locator('button[title]').filter({ hasText: '⋯' }).first().click(); await p.waitForTimeout(600);
  await p.screenshot({ path: raw + 's2-menu.png', fullPage: true });
  if (await p.getByRole('button', { name: '진행중으로' }).count()) await p.getByRole('button', { name: '진행중으로' }).first().click(); else await p.keyboard.press('Escape'); await p.waitForTimeout(2000);
  await p.screenshot({ path: raw + 's3-started.png', fullPage: true });
  console.log('body:', (await p.locator('main').innerText()).slice(0, 600));
  // 신고번호·컨테이너
  await card().getByRole('button', { name: '수출신고번호·컨테이너 기입' }).click(); await p.waitForTimeout(1200);
  await p.locator('input.input-base.w-56').first().fill('12345-26-1234567');
  await p.locator('input.input-base.w-48').first().fill('ABCU1234567');
  await p.waitForTimeout(400);
  await p.screenshot({ path: raw + 's4-decl.png', fullPage: true });
  await p.getByRole('button', { name: '일괄 기입' }).last().click(); await p.waitForTimeout(2000);
  // board B/L 요청
  send('POST', '/api/internal/board/bundles/' + batch + '/bl-request', '{"salesman_email":"sales@car-erp.test","bl_type":"original"}');
  // board 변경요청 (00가1131)
  send('POST', '/api/internal/board/shipping-requests/change-request', '{"salesman_email":"sales@car-erp.test","vehicle_id":304,"note":"00가1131 은 다음 배로 빼 주세요"}');
  await go();
  await p.screenshot({ path: raw + 's5-blreq.png', fullPage: true });
  console.log('body2:', (await p.locator('main').innerText()).slice(0, 900));
  await b.close();
})().catch(e => { console.error(e.message.slice(0, 500)); process.exit(1); });
