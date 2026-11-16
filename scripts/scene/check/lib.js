// 씬 점검(화면 회귀) 공용 — dev 전용 · master 제외 · 로컬 8001 전용.
// 정본 = docs/design/scene-walkthrough.md §7·§8 / scripts/scene/README.md 「함정」.
//
// 🚫 이 파일이 막는 것 (두 겹)
//   ① 브라우저 안: 캡처 단계 click 리스너 — 금지 wire:click 메서드 · 금지 라벨이면 클릭을 삼키고 기록한다.
//      (촬영 스크립트가 쓰던 `evaluate(e => e.click())` 도 이벤트를 타므로 같이 잡힌다)
//   ② 네트워크: Livewire update 요청 본문에 금지 메서드명이 있으면 abort + 기록.
//   + 127.0.0.1:47321(jin PC 의 실제 카톡 에이전트) 은 무조건 abort.
// ⚠️ 라벨 「조회」 는 금지 목록에 넣지 않는다 — NICE 버튼(vehicle.panel.lookup)도 「조회」지만
//    채권관리 검색 버튼도 「조회」다. NICE 는 메서드명(lookupNiceApi)으로 막는다.
// ⚠️ 서버가 스스로 보내는 알림톡(예: 정산 생성 → erp_settle_pending)은 여기서 못 막는다 —
//    그래서 씬마다 「완납이 되지 않는가」 같은 사전 조건을 DB 로 먼저 본다(buyer-account).

const path = require('path');
const { execFileSync } = require('child_process');

const PW = process.env.PLAYWRIGHT_CORE
  || 'C:/Users/User/AppData/Local/Temp/claude/a6613a99d460c33c/844bed06-9f0e-4a53-a3f6-2216dbe9d57d/scratchpad/pw/node_modules/playwright-core';
const { chromium } = require(PW);

const ROOT = process.env.CAR_ERP_ROOT || path.resolve(__dirname, '../../..');
const BASE = process.env.SCENE_BASE || 'http://localhost:8001';
const CHROME = process.env.SCENE_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PASSWORD = 'password';

// grep 으로 모은 「누르면 밖으로 나가는」 메서드 (resources/views 전체, 2026-11-16)
const FORBIDDEN_METHODS = [
  'lookupNiceApi',            // NICE 조회
  'openWonbuLookup',          // 원부조회
  'sendTestAlimtalk',         // 기능설정 알림톡 테스트 발송
  'sendDeregistrationAlimtalk',
  'sendTaxInvoiceAlimtalk',
  'sendPaidNotice',           // 매입대금 입금완료 알림톡
  'sendVehicleMail',          // 서류 메일 발송
  'sendTelegramTest',
  'resendRequest',            // board 요청 다시 보내기
  'sendVerification',
];
// 라벨로만 잡을 수 있는 것(카톡 에이전트 — 로컬 47321 로 가는 버튼) + 라벨이 분명한 발송 버튼
const FORBIDDEN_LABELS = [
  '이 PC 연결하기', '이 글 열기', '지금 읽기',
  '테스트 발송', 'NICE 조회', '원부조회', '새 요청 다시 보내기',
];

function today() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return { iso: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`, ymd: `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}` };
}
function addDays(n) {
  const d = new Date(); d.setDate(d.getDate() + n);
  const p = (x) => String(x).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

async function launch() {
  return chromium.launch({ executablePath: CHROME, headless: process.env.SCENE_HEADED ? false : true });
}

/** 컨텍스트 + 가드. ctx.violations 에 막은 것이 쌓인다(있으면 그 씬은 실패). */
async function newContext(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'ko-KR', acceptDownloads: true, ...opts });
  ctx.violations = [];
  await ctx.route(/127\.0\.0\.1:47321|localhost:47321/, (r) => r.abort());
  await ctx.route(/\/livewire[^/]*\/update/, (r) => {
    const body = r.request().postData() || '';
    // Livewire calls = [{"path":"","method":"lookupNiceApi","params":[]}] — 스냅샷 데이터에 같은 글자가 있어도 오탐하지 않게 method 키만 본다.
    const hit = FORBIDDEN_METHODS.find((m) => new RegExp(`"method"\\s*:\\s*"${m}"`).test(body));
    if (hit) { ctx.violations.push(`network:${hit}`); return r.abort(); }
    return r.continue();
  });
  await ctx.addInitScript(({ methods, labels }) => {
    window.__sceneForbidden = [];
    document.addEventListener('click', (e) => {
      const el = e.target && e.target.closest ? e.target.closest('button, a, [wire\\:click], [role=button]') : null;
      if (!el) return;
      const wc = el.getAttribute('wire:click') || '';
      const text = (el.innerText || el.textContent || '').trim();
      const m = methods.find((x) => wc.startsWith(x));
      const l = labels.find((x) => text.includes(x));
      if (m || l) {
        e.preventDefault(); e.stopImmediatePropagation(); e.stopPropagation();
        window.__sceneForbidden.push(m || l);
      }
    }, true);
  }, { methods: FORBIDDEN_METHODS, labels: FORBIDDEN_LABELS });
  return ctx;
}

async function newPage(ctx) {
  const p = await ctx.newPage();
  p.setDefaultTimeout(60000);
  // wire:confirm(브라우저 confirm) 은 수락 — 금지 버튼은 위 가드가 confirm 전에 삼킨다.
  p.on('dialog', (d) => d.accept().catch(() => {}));
  return p;
}

async function login(page, email) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[type=email]', email);
  await page.fill('input[type=password]', PASSWORD);
  await Promise.all([page.waitForLoadState('networkidle'), page.keyboard.press('Enter')]);
  if (/\/login/.test(page.url())) throw new Error(`로그인 실패: ${email}`);
}

/** 막힌 클릭·요청이 있었으면 던진다 — 각 단계 끝에서 부른다. */
async function assertNoViolations(page) {
  const inPage = await page.evaluate(() => window.__sceneForbidden || []).catch(() => []);
  const all = [...(page.context().violations || []), ...inPage];
  if (all.length) throw new Error(`금지 버튼/요청을 눌렀다(막음): ${[...new Set(all)].join(', ')}`);
}

/**
 * 화면에 문구가 「보일 때까지」 기다린다(innerText = 숨은 요소 제외 — 폰 카드/표 이중 렌더 대응).
 * 토스트처럼 사라지는 문구는 expectAfter(동작과 동시에 대기)로.
 */
async function expectText(page, text, stepName, { timeout = 15000, scope = 'body' } = {}) {
  try {
    await page.waitForFunction(([sel, t]) => {
      const root = document.querySelector(sel);
      return !!root && root.innerText.includes(t);
    }, [scope, text], { timeout });
  } catch {
    throw new Error(`[${stepName}] 화면에 「${text}」 없음`);
  }
}
async function expectNoText(page, text, stepName, { scope = 'body', settle = 800 } = {}) {
  await page.waitForTimeout(settle);
  const has = await page.evaluate(([sel, t]) => { const r = document.querySelector(sel); return !!r && r.innerText.includes(t); }, [scope, text]);
  if (has) throw new Error(`[${stepName}] 사라져야 할 「${text}」가 남아 있음`);
}
/**
 * 동작과 문구 대기를 함께 — 토스트가 동작 직후 떴다 사라져도 놓치지 않는다.
 * ⚠️ 같은 문구가 이미 떠 있으면(앞 동작의 토스트) 즉시 통과해 버리므로 먼저 사라질 때까지 기다리고,
 *    동작 뒤 Livewire update 응답까지 기다린다 — 그다음 DB 를 읽어야 서버 처리 전 값을 안 읽는다.
 */
async function expectAfter(page, action, text, stepName, opts = {}) {
  const scope = opts.scope || 'body';
  await page.waitForFunction(([sel, t]) => { const r = document.querySelector(sel); return !r || !r.innerText.includes(t); }, [scope, text], { timeout: 15000 })
    .catch(() => { throw new Error(`[${stepName}] 앞 동작의 「${text}」가 사라지지 않아 판정할 수 없음`); });
  const resp = page.waitForResponse((r) => /\/livewire[^/]*\/update/.test(r.url()), { timeout: opts.timeout || 30000 }).catch(() => null);
  await Promise.all([expectText(page, text, stepName, opts), action()]);
  await resp;
}

/** 읽기 전용 SELECT. 한글 값은 bindings 로 — SQL 은 stdin(JSON) 으로 넘긴다. */
function dbQuery(sql, bindings = []) {
  let out;
  try {
    out = execFileSync('php', [path.join(__dirname, 'db.php')], {
      input: JSON.stringify({ sql, bindings }), cwd: ROOT, env: { ...process.env, CAR_ERP_ROOT: ROOT, MSYS_NO_PATHCONV: '1' }, timeout: 60000,
    }).toString();
  } catch (e) {
    out = (e.stdout || '').toString() || String(e.message);
  }
  let j;
  try { j = JSON.parse(out); } catch { throw new Error(`dbQuery 응답이 JSON 아님: ${out.slice(0, 200)}`); }
  if (j && !Array.isArray(j) && j.error) throw new Error(`dbQuery 실패: ${j.error}`);
  return j;
}
const dbOne = (sql, b) => dbQuery(sql, b)[0] || null;

/** 편집 잠금 풀기(로컬 전용 unlock.php) — 계정을 바꿔 같은 차를 열 때. */
function unlockVehicles(plates) {
  execFileSync('php', [path.join(__dirname, 'unlock.php')], {
    input: JSON.stringify({ plates }), cwd: ROOT, env: { ...process.env, CAR_ERP_ROOT: ROOT, MSYS_NO_PATHCONV: '1' }, timeout: 60000,
  });
}

/** 차량 목록에서 차를 검색해 편집 패널을 연다(촬영 스크립트와 같은 방식). */
async function openVehicle(page, plate, tab) {
  await page.goto(`${BASE}/erp/vehicles?search=${encodeURIComponent(plate)}`, { waitUntil: 'networkidle', timeout: 120000 });
  await page.locator('tr', { hasText: plate }).first().locator('td').nth(1).click();
  await page.waitForTimeout(2000);
  if (tab) { await page.click(`button[\\@click="tab = '${tab}'"]`); await page.waitForTimeout(800); }
}
async function closePanel(page) {
  await page.getByRole('button', { name: '취소' }).last().click({ timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(1000);
}

/**
 * 단계 기록기 — 한 단계가 실패하면 뒤 단계는 「앞 단계 실패로 건너뜀」(ok:null).
 * detail 에 본문 덤프·실명 금지(로컬 DB 에 실무자 이름이 섞여 있다).
 */
function recorder(slug) {
  const steps = [];
  let broken = false;
  let skipAll = null;
  return {
    steps,
    skip(reason) { skipAll = reason; },
    async step(name, fn) {
      const t0 = Date.now();
      if (skipAll) { steps.push({ name, ok: null, detail: `건너뜀: ${skipAll}`, ms: 0 }); return; }
      if (broken) { steps.push({ name, ok: null, detail: '앞 단계 실패로 건너뜀', ms: 0 }); return; }
      try {
        const detail = await fn();
        steps.push({ name, ok: true, detail: detail || '', ms: Date.now() - t0 });
      } catch (e) {
        broken = true;
        steps.push({ name, ok: false, detail: String(e && e.message || e).slice(0, 300), ms: Date.now() - t0 });
      }
    },
    result() { return { slug, steps }; },
  };
}

function assertEq(actual, expected, what) {
  if (String(actual) !== String(expected)) throw new Error(`${what}: 기대 ${expected} / 실제 ${actual}`);
}
const num = (v) => (v === null || v === undefined ? null : Number(v));

module.exports = {
  BASE, ROOT, today, addDays, launch, newContext, newPage, login, assertNoViolations,
  expectText, expectNoText, expectAfter, dbQuery, dbOne, unlockVehicles, openVehicle, closePanel,
  recorder, assertEq, num, FORBIDDEN_METHODS, FORBIDDEN_LABELS,
};
