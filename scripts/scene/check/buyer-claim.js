// 씬 점검 — buyer-claim (resources/guides/buyer-claim). 촬영 원본 = cap/cl_scene.js
// 계정 = manage@. 예시 = GUIDE SAMPLE TRADING 에 board 결재 끝난 클레임 2건(board_claim_id 9001 · 9002 — 가짜 번호).
//   9001 = 00가1141 인정 · 송금 · USD 300
//   9002 = 00가1131 1회차 인정 USD 200 → 2회차 기각(0원) — 「board 에서 판정을 고쳐 다시 보내면 같은 줄이 새 내용으로」
// 시더가 9001·9002 를 지워 둔다(그 바이어의 다른 클레임 — board_claim_id 4 — 는 그대로 → 건수는 DB 로 계산해 대조).
// 담당·결재자 이름은 가짜 「[예시] …」만 쓴다.
const L = require('./lib');
const B = require('./board');

const BUYER = 'GUIDE SAMPLE TRADING';
const A = '00가1141';
const Bv = '00가1131';
const NO_BUYER = '00가1201';   // board 매입 확정으로 생긴 바이어미정 차

module.exports.run = async function run({ browser }) {
  const r = L.recorder('buyer-claim');
  const today = L.today().iso;
  const claim = (id) => L.dbOne('select id, revision, result, confirmed_amount a, currency, compensation_type t, buyer_id from vehicle_claims where board_claim_id = ?', [id]);
  let buyer = null;
  const base = (over) => ({
    salesman_email: B.SALES, currency: 'USD', approved_at: `${today} 10:00:00`,
    handler_name: '[예시] 클레임담당', approved_by_name: '[예시] 결재자', board_url: null, ...over,
  });

  let before = null;
  const snap = () => {
    const v = L.dbQuery('select vehicle_number n, sale_unpaid_amount_krw_cache u from vehicles where vehicle_number in (?, ?) order by vehicle_number', [A, Bv]);
    return {
      [`미수 ${v[0].n}`]: v[0].u, [`미수 ${v[1].n}`]: v[1].u,
      '적립금 거래 수': Number(L.dbOne('select count(*) n from savings_statuses where buyer_id = ?', [buyer]).n),
      '정산 행 수(두 차)': Number(L.dbOne('select count(*) n from settlements s join vehicles v on v.id = s.vehicle_id where v.vehicle_number in (?, ?)', [A, Bv]).n),
    };
  };

  await r.step('사전: 예시 클레임 9001·9002 없음(시더)', async () => {
    buyer = B.buyerId(BUYER);
    if (claim(9001) || claim(9002)) throw new Error('예시 클레임 9001/9002 가 남아 있다 — 시더가 안 돌았다');
    before = snap();
  });

  await r.step('board → ERP: 결재 끝난 클레임 2건 · 2회차 정정 · 멱등', async () => {
    const c1 = B.expectStatus(B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9001, revision: 1, vehicle_id: B.vehicleId(A), result: 'accepted', compensation_type: 'refund', confirmed_amount: 300,
      result_reason: '[예시] 도착 후 범퍼 긁힘 — 사진 확인', buyer_claim_count: 2,
    })), 201, '9001 1회차');
    L.assertEq(c1.status, 'created', '9001 응답');
    B.expectStatus(B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9002, revision: 1, vehicle_id: B.vehicleId(Bv), result: 'accepted', compensation_type: 'credit', confirmed_amount: 200,
      result_reason: '[예시] 열쇠 1개 누락', buyer_claim_count: 3,
    })), 201, '9002 1회차');
    const up = B.expectStatus(B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9002, revision: 2, vehicle_id: B.vehicleId(Bv), result: 'declined', confirmed_amount: 200,
      result_reason: '[예시] 선적 전 사진에 이미 없음 — 기각', buyer_claim_count: 3,
    })), 200, '9002 2회차');
    L.assertEq(up.status, 'updated', '9002 2회차 응답');
    const same = B.expectStatus(B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9002, revision: 2, vehicle_id: B.vehicleId(Bv), result: 'declined', confirmed_amount: 200, buyer_claim_count: 3,
    })), 200, '같은 회차 재전송');
    L.assertEq(same.status, 'unchanged', '같은 회차 = 멱등');
    B.expectStatus(B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9002, revision: 1, vehicle_id: B.vehicleId(Bv), result: 'accepted', confirmed_amount: 200,
    })), 409, '옛 회차 재전송 = stale');
    const k1 = claim(9001);
    const k2 = claim(9002);
    L.assertEq(Number(k1.buyer_id), buyer, 'DB 9001 바이어(차의 바이어)');
    L.assertEq(Number(k1.a), 300, 'DB 9001 확정 금액');
    L.assertEq(k2.result, 'declined', 'DB 9002 결과');
    L.assertEq(Number(k2.a), 0, 'DB 9002 기각 = 0원');
    L.assertEq(Number(k2.revision), 2, 'DB 9002 회차');
    L.assertEq(Number(L.dbOne('select count(*) n from vehicle_claims where board_claim_id = 9002').n), 1, 'DB 9002 줄 수(늘지 않음)');
  });

  await r.step('알아 둘 것: 바이어를 안 정한 차의 클레임은 받지 않는다(422)', async () => {
    const res = B.boardSend('POST', '/api/internal/board/claims', base({
      board_claim_id: 9003, revision: 1, vehicle_id: B.vehicleId(NO_BUYER), result: 'accepted', confirmed_amount: 1,
    }));
    B.expectStatus(res, 422, '바이어 없는 차');
    if (!String(JSON.stringify(res.body)).includes('바이어를 먼저 정해 주세요')) throw new Error('422 안내 문구가 다르다');
    if (claim(9003)) throw new Error('DB 에 9003 이 생겼다');
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  try {
    await r.step('1단계 바이어 → 「클레임」 탭 — 네 칸 · board 누적 · 표', async () => {
      await L.login(p, 'manage@car-erp.test');
      await p.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(BUYER)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.locator('tr', { hasText: BUYER }).first().locator('td').first().click();
      await p.waitForTimeout(2500);
      await p.locator('button', { hasText: '클레임' }).last().click();
      const box = p.locator('[data-buyer-claims]');
      await box.waitFor({ state: 'visible', timeout: 20000 });
      const db = L.dbOne(`select count(*) n, sum(result = 'accepted') a, sum(result = 'declined') d,
                                 sum(case when result = 'accepted' and currency = 'USD' then confirmed_amount else 0 end) usd,
                                 max(board_buyer_claim_count) bc from vehicle_claims where buyer_id = ?`, [buyer]);
      const cards = await box.locator('.grid > div').allInnerTexts();
      const val = (label) => { const x = cards.find((t) => t.startsWith(label)); return x ? x.replace(label, '').trim() : null; };
      L.assertEq(val('클레임 횟수'), Number(db.n), '「클레임 횟수」');
      L.assertEq(val('인정'), Number(db.a), '「인정」');
      L.assertEq(val('기각'), Number(db.d), '「기각」');
      const sum = val('인정 확정 금액 합') || '';
      const usd = `USD ${Number(db.usd).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      if (!sum.includes(usd)) throw new Error(`「인정 확정 금액 합」 기대 ${usd} / 실제 ${sum}`);
      await L.expectText(p, `board 에서 센 누적 클레임 ${Number(db.bc)}건`, 'board 누적 문구', { scope: '[data-buyer-claims]' });
      const tr1 = await box.locator('tr', { hasText: A }).first().innerText();
      for (const s of ['인정', '송금', 'USD 300.00', '[예시] 클레임담당']) if (!tr1.includes(s)) throw new Error(`9001 줄에 「${s}」 없음`);
      const tr2 = await box.locator('tr', { hasText: Bv }).first().innerText();
      for (const s of ['기각', 'USD 0.00']) if (!tr2.includes(s)) throw new Error(`9002 줄에 「${s}」 없음`);
      // 묶음(board 로컬 시험분 — 00가1131 이 들어 있을 수 있다) 줄은 빼고 센다
      L.assertEq(await box.locator('tbody:not([data-claim-bundle]) tr', { hasText: Bv }).count(), 1, '00가1131 클레임 줄 수(정정이 줄을 늘리지 않음)');
      await L.assertNoViolations(p);
    });

    await r.step('2단계 클레임은 기록만 — 미수 · 적립금 · 정산 숫자를 안 바꾼다', async () => {
      const now = snap();
      for (const k of Object.keys(before)) L.assertEq(now[k], before[k], `DB ${k} (클레임 수신 전후)`);
    });
  } finally {
    await c.close();
  }
  return r.result();
};
