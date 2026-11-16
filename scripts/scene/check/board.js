// 씬 점검 4단계 공용 — board 가 ERP 로 보내는 요청을 로컬에서 흉내(scripts/scene/board_send.php 를 그대로 부른다).
// dev 전용 · master 제외 · 로컬 8001 전용. 정본 = scripts/scene/README.md 「board_send.php」.
//
// 🚫 이 파일로 보내지 않는 것
//   - /api/internal/board/shipping-requests/sync — 선언형이라 같은 영업(sales@ = 영업 #1)의 다른 열린 요청(99무0001~3 · 11가1001 RORO)이
//     자동 취소된다. 선적 묶음은 시더가 「요청」 상태로 되돌려 둔다(seeder-additions.php restoreDemoShippingBundle).
//   - owner_name(소유자명) — 매입 확정에 실으면 NICE 가 불린다(실호출).
// ⚠️ 본문은 \uXXXX 로 ASCII 화해서 넘긴다 — Windows 에서 한글 인자가 UTF-8 로 안 올 수 있다(db.php 주석과 같은 이유).
//    서명은 board_send.php 가 「받은 문자열 그대로」로 하므로 서명·검증이 어긋나지 않는다.
const path = require('path');
const { execFileSync } = require('child_process');
const L = require('./lib');

const SALES = 'sales@car-erp.test';   // board 쪽 영업 신원(로컬 데모). 🚫 실명 영업 이메일 금지.
const NOTE_MARK = '[씬점검]';          // 시더가 이 표식으로 지운다(board_requests.note)

function asciiJson(obj) {
  return JSON.stringify(obj).replace(/[\u007f-￿]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
}

/** board_send.php 호출 → { status, body } (body 는 JSON 이면 객체, 아니면 문자열). 출력에 시크릿은 없다. */
function boardSend(method, apiPath, obj, query = '') {
  const args = [path.join(L.ROOT, 'scripts', 'scene', 'board_send.php'), method, apiPath, obj === null ? '' : asciiJson(obj)];
  if (query) args.push(query);
  let out;
  try {
    out = execFileSync('php', args, { cwd: L.ROOT, env: { ...process.env, MSYS_NO_PATHCONV: '1' }, timeout: 90000 }).toString().trim();
  } catch (e) {
    throw new Error(`board_send 실패: ${String((e.stdout || '') + (e.stderr || '') || e.message).slice(0, 200)}`);
  }
  const m = out.match(/^(\d{3}) ([\s\S]*)$/);
  if (!m) throw new Error(`board_send 응답 형식 아님: ${out.slice(0, 120)}`);
  let body = m[2];
  try { body = JSON.parse(m[2]); } catch { /* 문자열 그대로 */ }
  return { status: Number(m[1]), body };
}

/** 응답 코드 확인 — 실패 문구에 본문 앞부분만(실명 없음: ERP 응답은 차량번호·사유뿐). */
function expectStatus(res, codes, what) {
  const list = Array.isArray(codes) ? codes : [codes];
  if (!list.includes(res.status)) {
    throw new Error(`${what}: HTTP ${res.status} (기대 ${list.join('/')}) ${JSON.stringify(res.body).slice(0, 160)}`);
  }
  return res.body;
}

const vehicleId = (plate) => {
  const r = L.dbOne('select id from vehicles where vehicle_number = ? and deleted_at is null', [plate]);
  if (!r) throw new Error(`예시 차량 ${plate} 없음`);
  return Number(r.id);
};
const buyerId = (name) => {
  const r = L.dbOne('select id from buyers where name = ? and deleted_at is null', [name]);
  if (!r) throw new Error(`예시 바이어 ${name} 없음`);
  return Number(r.id);
};

/** lib 가드 위에 한 겹 더 — 씬마다 추가로 막을 Livewire 메서드·주소. Playwright 는 나중에 건 route 가 먼저 돈다 → 아닌 건 fallback. */
async function extraGuard(ctx, methods = [], urlPatterns = []) {
  for (const pat of urlPatterns) {
    await ctx.route(pat, (r) => { ctx.violations.push(`network:${pat}`); return r.abort(); });
  }
  if (!methods.length) return;
  await ctx.route(/\/livewire[^/]*\/update/, (r) => {
    const body = r.request().postData() || '';
    const hit = methods.find((m) => new RegExp(`"method"\\s*:\\s*"${m}"`).test(body));
    if (hit) { ctx.violations.push(`network:${hit}`); return r.abort(); }
    return r.fallback();
  });
}

module.exports = { SALES, NOTE_MARK, asciiJson, boardSend, expectStatus, vehicleId, buyerId, extraGuard };
