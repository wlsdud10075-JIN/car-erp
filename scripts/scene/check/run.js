// 씬 점검 러너 — 시더 → 씬 순서대로 → 결과 표 + JSON. 실패해도 다음 씬 계속. 실패가 있으면 exit 1.
// 사용: node scripts/scene/check/run.js [--only=bank-balance] [--no-seed]
// 🚫 배포 게이트 아님(로컬 8001 필요). 정본 = docs/design/scene-walkthrough.md §7·§8.
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');
const L = require('./lib');

// 순서 = 같은 예시 차(00가1131·00가1141)를 서로 다른 칸으로 쓰는 순. buyer-account 는 마지막(돈이 움직인다).
// quoted-texts = 5단계 정적 검사(브라우저 없이 몇 초, 가이드 인용 문구가 화면 코드에 있나) — 맨 앞.
// 한 바퀴(2단계)는 같은 전용 차 00가2001 을 이어 받으므로 순서 고정. 1단계와 데이터가 안 겹친다.
// 3단계 순서 제약(scene-p3 touches): gate 는 bulk-shipping-dates·savings 앞 · 현금 쓰는 savings·receivables 는 buyer-account 앞 ·
//   buyer-undecided → inventory-location · purchase-cancel·secondary → monthly-payout 뒤 · secondary → ledger-readjust · buyer-account 맨 끝(돈이 움직인다).
const SCENES = [
  'quoted-texts',
  'audit-log',   // 읽기만 — 순서 무관. 영어로 남은 이름 찾기(2026-11-22)
  'leaver-domestic-payout', 'deregistration-notice', 'sales-contract-esign', 'unpaid-gate-override',
  'vehicle-register', 'purchase-payment', 'sale-register', 'sale-payment', 'export-tab', 'monthly-payout', 'bl-issue',
  // savings·receivables 는 bulk-shipping-dates 앞 — 그 씬이 00가1131 에 수출 칸을 넣으면 입금률 60% 게이트로 판매 탭 저장이 막힌다(11-16 전체 실행).
  'savings', 'receivables',
  'bank-balance', 'documents', 'bulk-shipping-dates', 'shipment-fee',
  'buyer-undecided', 'inventory-location',
  'purchase-cancel', 'secondary-settlement', 'ledger-readjust',
  'buyer-account',
  // 4단계(board 연동·카톡) — buyer-account 뒤(shipping-requests 의 묶음 입금률 게이트가 buyer-account 확정 2,000 뒤에야 통과)
  'board-purchase-sync', 'board-payment-request', 'board-sale-payment-check', 'shipping-requests', 'buyer-claim', 'inventory-location-board', 'kakao-todo',
];

const arg = (k) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.split('=').slice(1).join('=') : null; };
const only = arg('only');
const noSeed = process.argv.includes('--no-seed');

function ping(url) {
  return new Promise((resolve) => {
    const req = http.get(url, (res) => { res.resume(); resolve(res.statusCode); });
    req.on('error', () => resolve(0));
    req.setTimeout(10000, () => { req.destroy(); resolve(0); });
  });
}

// 🔒 실행 잠금(11-16) — 도는 동안 다른 곳(board 러너·손)이 같은 로컬 DB 에 시더를 돌리면 예시 차가 통째로 지워져 뒤 씬이 무더기로 실패했다.
//   잠금 파일에 토큰을 쓰고, 시더는 「잠금이 있는데 토큰이 다르면」 거부한다(GuideSceneFixtureSeeder::guardRunLock). 2시간 넘은 잠금은 죽은 것으로 본다.
const LOCK = path.join(L.ROOT, 'storage', 'logs', 'scene-check', 'running.lock');
const TOKEN = `${process.pid}-${Date.now()}`;
function takeLock() {
  fs.mkdirSync(path.dirname(LOCK), { recursive: true });
  if (fs.existsSync(LOCK)) {
    const age = Date.now() - fs.statSync(LOCK).mtimeMs;
    if (age < 2 * 3600 * 1000) { console.error(`다른 씬 점검이 도는 중이다(${LOCK}, ${Math.round(age / 60000)}분 전) — 끝난 뒤 다시.`); console.log('SUMMARY 다른 점검이 도는 중 — 안 돌았음'); process.exit(2); }
  }
  fs.writeFileSync(LOCK, TOKEN, 'utf8');
  const release = () => { try { if (fs.readFileSync(LOCK, 'utf8') === TOKEN) fs.unlinkSync(LOCK); } catch { /* 이미 없음 */ } };
  process.on('exit', release);
  process.on('SIGINT', () => { release(); process.exit(130); });
}

function seed() {
  // 셸 없이 부른다 — 백슬래시가 그대로 간다(Git Bash 경로 변환도 안 탄다. 그래도 MSYS_NO_PATHCONV 는 넘긴다).
  const out = execFileSync('php', ['artisan', 'db:seed', '--class=Database\\Seeders\\GuideSceneFixtureSeeder', '--no-interaction'], {
    cwd: L.ROOT, env: { ...process.env, MSYS_NO_PATHCONV: '1', SCENE_RUN_TOKEN: TOKEN }, timeout: 180000,
  });
  return out.toString().trim();
}

function stamp() {
  const d = new Date(); const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`;
}

(async () => {
  const t0 = Date.now();
  const list = only ? SCENES.filter((s) => s === only) : SCENES;
  if (!list.length) { console.error(`모르는 씬: ${only} (가능: ${SCENES.join(', ')})`); process.exit(2); }

  const code = await ping(`${L.BASE}/login`);
  if (code !== 200) { console.error(`8001 이 응답하지 않는다(${code || '연결 실패'}) — jin 이 띄운 서버를 확인. 🚫 php artisan serve 를 여기서 띄우지 않는다.`); process.exit(2); }
  takeLock();

  // 🚫 로컬 BizM 은 실발송 계정이다 — 알림톡이 켜져 있으면 점검이 실제 카톡을 보낼 수 있다(판매 잔금 확정 → 정산 대기 알림 등). 켜져 있으면 멈춘다.
  try {
    const g = JSON.parse(execFileSync('php', ['scripts/scene/check/alimtalk-guard.php'], { cwd: L.ROOT, timeout: 60000 }).toString());
    if (g.error || g.enabled) { console.error(`알림톡이 켜져 있다(회사 설정 ${g.set}) — 점검을 멈춘다. 기능설정에서 끄고 다시.`); console.log('SUMMARY 알림톡 켜짐 — 안 돌았음'); process.exit(1); }
  } catch (e) { console.error('알림톡 상태 확인 실패 — 안전하게 멈춘다: ' + e.message); console.log('SUMMARY 알림톡 상태 확인 실패'); process.exit(1); }

  const report = { started_at: new Date().toISOString(), base: L.BASE, seed: null, scenes: [] };
  if (!noSeed) {
    try { report.seed = { ok: true, output: seed() }; } catch (e) {
      const msg = ((e.stdout || '') + (e.stderr || '')).toString().trim().slice(-500) || String(e.message);
      report.seed = { ok: false, output: msg };
      console.error('시더 실패 — 씬을 돌리지 않는다:\n' + msg);
    }
  }

  if (!report.seed || report.seed.ok) {
    const browser = await L.launch();
    try {
      for (const slug of list) {
        const s0 = Date.now();
        let res;
        try {
          res = await require(`./${slug}.js`).run({ browser });
        } catch (e) {
          res = { slug, steps: [{ name: '씬 실행', ok: false, detail: String(e && e.message || e).slice(0, 300) }] };
        }
        res.ms = Date.now() - s0;
        res.status = res.steps.some((x) => x.ok === false) ? 'failed' : (res.steps.every((x) => x.ok === null) ? 'skipped' : (res.steps.some((x) => x.ok === null) ? 'partial' : 'passed'));
        report.scenes.push(res);
      }
    } finally {
      await browser.close();
    }
  }
  report.ms = Date.now() - t0;

  // ── 결과 표 ──
  const mark = { passed: '통과', failed: '실패', skipped: '건너뜀', partial: '일부 건너뜀' };
  console.log('\n씬 점검 결과');
  console.log('씬'.padEnd(22) + '결과'.padEnd(10) + '단계'.padEnd(10) + '시간');
  for (const s of report.scenes) {
    const ok = s.steps.filter((x) => x.ok === true).length;
    console.log(s.slug.padEnd(22) + (mark[s.status] || s.status).padEnd(10) + `${ok}/${s.steps.length}`.padEnd(10) + `${(s.ms / 1000).toFixed(1)}s`);
    for (const st of s.steps.filter((x) => x.ok === false)) console.log(`   ✗ ${st.name} — ${st.detail}`);
    const sk = s.steps.find((x) => x.ok === null && x.detail.startsWith('건너뜀:'));
    if (sk) console.log(`   – ${sk.detail}`);
  }
  const failed = report.scenes.filter((s) => s.status === 'failed').length + (report.seed && !report.seed.ok ? 1 : 0);
  const passed = report.scenes.filter((s) => s.status === 'passed').length;
  console.log(`\n통과 ${passed} / 실패 ${failed} / 전체 ${list.length} · ${(report.ms / 1000).toFixed(0)}초`);

  const dir = path.join(L.ROOT, 'storage', 'logs', 'scene-check');
  // 주말 실행기(scripts/weekly/weekend.ps1)가 읽는 줄 — 관제 기록·텔레그램 본문
  for (const sc of report.scenes.filter((x) => x.status === 'failed')) {
    for (const st of sc.steps.filter((x) => x.ok === false)) console.log(`FAIL ${sc.slug} · ${st.name} — ${String(st.detail).slice(0, 120)}`);
  }
  if (report.seed && !report.seed.ok) console.log('FAIL 시더 — ' + String(report.seed.output).slice(-120));
  console.log(`SUMMARY 통과 ${passed} / 실패 ${failed} / 전체 ${list.length}`);
  fs.mkdirSync(dir, { recursive: true });
  const file = path.join(dir, `result-${stamp()}.json`);
  fs.writeFileSync(file, JSON.stringify(report, null, 2), 'utf8');
  console.log(`결과 파일: ${file}`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
