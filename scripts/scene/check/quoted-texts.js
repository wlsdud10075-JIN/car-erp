// 🔤 씬 점검 5단계 — 가이드 인용 문구 정적 검사 (jin 2026-11-16 «마지막 단계까지»). 브라우저 없이 몇 초.
//   가이드(resources/guides/{slug}/index.html)가 「」·[ ] 로 인용한 화면 문구가 지금 화면 코드(lang/ko · resources/views · app)에 아직 있나.
//   화면 문구를 바꾸고 가이드를 안 고치면 여기서 「씬 · 문구」로 잡힌다(§7-1 「의도한 변경」 → 가이드도 고칠 것).
//   빼는 것: 다른 가이드 씬 제목(config/guides.php) · 예외 목록 quoted-allow.json(예시 데이터·화면 밖 설명).
//   정규화: 숫자·N·:placeholder·{{ }}·$변수 = 같은 것, 공백 무시.
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..', '..');

function norm(s) {
  return unescapeHtml(String(s))
    .replace(/\{\{[^}]*\}\}/g, '#').replace(/:[a-z_]+/g, '#').replace(/\$\w+/g, '#')
    .replace(/○+|\(이름\)|\(name\)/g, '#')
    .replace(/(^|[^A-Za-z])[NM](?![A-Za-z])/g, '$1#').replace(/[0-9][0-9,.]*/g, '#')
    // 아이콘·기호·공백은 무시한다(✓ ⚠ 🔎 📩 · › 등) — 글자(한글·영문)와 자리표시(#)만 비교
    .replace(/[^\p{L}#]+/gu, '');
}

// 주석은 화면 문구가 아니다 — 주석에 같은 문장이 적혀 있으면 문구를 바꿔도 「있다」로 판정된다(11-16 깨뜨리기 확인에서 실제로 그랬다).
function stripComments(src) {
  return src
    .replace(/\{\{--[\s\S]*?--\}\}/g, ' ')
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[\s;{}(),])\/\/[^\n]*/g, '$1');
}

function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(stripComments(fs.readFileSync(p, 'utf8')));
  }
  return out;
}

function unescapeHtml(s) {
  return s.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&nbsp;/g, ' ');
}

function check() {
  const corpus = norm(['lang/ko', 'resources/views', 'app'].flatMap((d) => walk(path.join(ROOT, d), [])).join('\n'));
  const titles = new Set();
  const cfg = fs.readFileSync(path.join(ROOT, 'config', 'guides.php'), 'utf8');
  for (const m of cfg.matchAll(/'[a-z0-9-]+' => \['[a-z]+', '([^']+)'/g)) titles.add(norm(m[1]));
  const allowFile = path.join(__dirname, 'quoted-allow.json');
  const allow = fs.existsSync(allowFile) ? JSON.parse(fs.readFileSync(allowFile, 'utf8')) : {};

  const results = [];
  const dir = path.join(ROOT, 'resources', 'guides');
  for (const slug of fs.readdirSync(dir).sort()) {
    const f = path.join(dir, slug, 'index.html');
    if (!fs.existsSync(f)) continue;
    const text = unescapeHtml(fs.readFileSync(f, 'utf8').replace(/<[^>]+>/g, ''));
    const quotes = new Set([...text.matchAll(/\[([^\]\n]{1,30})\]/g), ...text.matchAll(/「([^」\n]{1,30})」/g)].map((m) => m[1].trim()));
    const ok = new Set((allow[slug] || []).map((x) => norm(x.text)).concat((allow['*'] || []).map((x) => norm(x.text))));
    const missing = [...quotes].filter((q) => q && !corpus.includes(norm(q)) && !titles.has(norm(q)) && !ok.has(norm(q)));
    results.push({ slug, total: quotes.size, missing });
  }

  return results;
}

/** 러너용 — 가이드 하나 = 단계 하나 */
async function run() {
  const steps = check().map((r) => ({
    name: `${r.slug} 인용 ${r.total}개`,
    ok: r.missing.length === 0,
    detail: r.missing.length ? '화면 코드에 없음: ' + r.missing.map((x) => `「${x}」`).join(', ') : '',
  }));

  return { slug: 'quoted-texts', steps };
}

module.exports = { run, check };

if (require.main === module) {
  const res = check();
  const bad = res.filter((r) => r.missing.length);
  for (const r of bad) console.log(`${r.slug}: ${r.missing.map((x) => `「${x}」`).join(', ')}`);
  console.log(`가이드 ${res.length}개 · 인용 ${res.reduce((a, r) => a + r.total, 0)}개 · 못 찾음 ${bad.reduce((a, r) => a + r.missing.length, 0)}개`);
  process.exit(bad.length ? 1 : 0);
}
