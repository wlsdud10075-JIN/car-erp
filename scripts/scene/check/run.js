// 씬 점검 러너 — 시더 → 씬 순서대로 → 결과 표 + JSON. 실패해도 다음 씬 계속. 실패가 있으면 exit 1.
// 사용: node scripts/scene/check/run.js [--only=bank-balance] [--no-seed]
// 🚫 배포 게이트 아님(로컬 8001 필요). 정본 = docs/design/scene-walkthrough.md §7·§8.
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');
const L = require('./lib');

// 순서 = 같은 예시 차(00가1131·00가1141)를 서로 다른 칸으로 쓰는 순. buyer-account 는 마지막(돈이 움직인다).
const SCENES = ['bank-balance', 'documents', 'bulk-shipping-dates', 'shipment-fee', 'buyer-account'];

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

function seed() {
  // 셸 없이 부른다 — 백슬래시가 그대로 간다(Git Bash 경로 변환도 안 탄다. 그래도 MSYS_NO_PATHCONV 는 넘긴다).
  const out = execFileSync('php', ['artisan', 'db:seed', '--class=Database\\Seeders\\GuideSceneFixtureSeeder', '--no-interaction'], {
    cwd: L.ROOT, env: { ...process.env, MSYS_NO_PATHCONV: '1' }, timeout: 180000,
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
