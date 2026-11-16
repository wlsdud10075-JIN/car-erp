// 씬 점검 — board-payment-request (resources/guides/board-payment-request). 촬영 원본 = cap/br_scene.js · br_explore.js
// 계정 = finance@(재무). 예시 = 11가1001 「매입잔금」 13,500,000 → 금액 고쳐 재전송 → [입금 확인] / 00가1131 「계약금」 1,000,000 → [거절] → 사유 → [거절 회신]
//
// board 요청 = POST /api/internal/board/requests (서명 요청, board_send.php). note 에 「[씬점검]」 표식 → 시더가 지운다.
// 🚫 알림톡: 요청이 들어오면 erp_board_request 가 나가는 길이다 — 러너가 알림톡 마스터 스위치 꺼짐을 먼저 보고 멈춘다(run.js).
//    그래도 이 씬은 「사전」 단계에서 한 번 더 본다(--only 로 돌릴 때도 안전하게).
const { execFileSync } = require('child_process');
const L = require('./lib');
const B = require('./board');

const BAL = '11가1001';
const DEP = '00가1131';
const REASON = '계약서 사본이 아직 안 와서 송금 보류 — 받으면 다시 요청해 주세요';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('board-payment-request');
  const req = (plate, type) => L.dbQuery(
    "select b.id, b.status, b.amount_krw, b.resent_count, b.reject_reason, b.confirmed_at from board_requests b join vehicles v on v.id = b.vehicle_id where v.vehicle_number = ? and b.type = ? and b.note like '[씬점검]%' order by b.id desc", [plate, type]);
  let balId = null;
  let depId = null;

  await r.step('사전: 알림톡 꺼짐 · 두 차에 열린 같은 종류 요청 없음(시더)', async () => {
    const g = JSON.parse(execFileSync('php', ['scripts/scene/check/alimtalk-guard.php'], { cwd: L.ROOT, timeout: 60000 }).toString());
    if (g.error || g.enabled) throw new Error('알림톡이 켜져 있다 — 요청 수신이 실제 카톡을 보낸다. 멈춘다.');
    const open = L.dbQuery(
      "select v.vehicle_number n, b.type t from board_requests b join vehicles v on v.id = b.vehicle_id where b.status = 'open' and ((v.vehicle_number = ? and b.type = 'purchase_balance') or (v.vehicle_number = ? and b.type = 'purchase_deposit'))", [BAL, DEP]);
    if (open.length) throw new Error(`열린 요청이 남아 있다(${open.map((x) => x.n + ':' + x.t).join(', ')}) — 시더가 안 돌았다. 새 요청이 already_open 으로 건너뛰어진다`);
    L.unlockVehicles([BAL, DEP]);
  });

  await r.step('board → ERP: 매입잔금 · 계약금 요청 수신', async () => {
    const b1 = B.expectStatus(B.boardSend('POST', '/api/internal/board/requests', {
      salesman_email: B.SALES, type: 'purchase_balance', vehicle_ids: [B.vehicleId(BAL)], amount_krw: 13500000, note: `${B.NOTE_MARK} 딜러 인수 전 잔금`,
    }), 201, '매입잔금 요청');
    if (!(b1.created || []).includes(BAL)) throw new Error(`매입잔금 created 에 ${BAL} 없음 (skipped=${JSON.stringify(b1.skipped)})`);
    const b2 = B.expectStatus(B.boardSend('POST', '/api/internal/board/requests', {
      salesman_email: B.SALES, type: 'purchase_deposit', vehicle_ids: [B.vehicleId(DEP)], amount_krw: 1000000, note: `${B.NOTE_MARK} 계약금`,
    }), 201, '계약금 요청');
    if (!(b2.created || []).includes(DEP)) throw new Error(`계약금 created 에 ${DEP} 없음 (skipped=${JSON.stringify(b2.skipped)})`);
    balId = Number(req(BAL, 'purchase_balance')[0].id);
    depId = Number(req(DEP, 'purchase_deposit')[0].id);
  });

  await r.step('알아 둘 것: 금액을 고쳐 다시 보내면 같은 요청 · 금액만 바뀜(+ 재전송 1회)', async () => {
    const b = B.expectStatus(B.boardSend('POST', '/api/internal/board/requests', {
      salesman_email: B.SALES, type: 'purchase_balance', vehicle_ids: [B.vehicleId(BAL)], amount_krw: 13600000, note: `${B.NOTE_MARK} 딜러 인수 전 잔금`, resend: true,
    }), 201, '재전송');
    if (!(b.updated || []).includes(BAL)) throw new Error('재전송 updated 에 없음(금액 수정이 아님)');
    const rows = req(BAL, 'purchase_balance');
    L.assertEq(rows.length, 1, 'DB 요청 행 수(새 요청이 생기면 안 됨)');
    L.assertEq(Number(rows[0].amount_krw), 13600000, 'DB 금액');
    L.assertEq(Number(rows[0].resent_count), 1, 'DB 재전송 횟수');
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  try {
    await r.step('1단계 차량 관리 필터 줄 「📩 매입잔금 (N)」 → 그 요청이 걸린 차만', async () => {
      await L.login(p, 'finance@car-erp.test');
      await p.goto(`${L.BASE}/erp/vehicles`, { waitUntil: 'networkidle', timeout: 120000 });
      const chip = p.locator('button', { hasText: /📩 매입잔금 \(\d+\)/ }).first();
      await chip.waitFor({ timeout: 30000 });
      await L.expectText(p, '📩 계약금', '「📩 계약금」 버튼');
      // 건수는 로컬의 다른 열린 요청과 섞이고, 목록 기본 기간(최근 2개월)·필터 안에서만 센다(boardRequestCounts) — 1 이상만 본다
      const n = Number((await chip.innerText()).match(/\((\d+)\)/)[1]);
      if (!(n >= 1)) throw new Error(`「매입잔금」 건수 ${n}`);
      await chip.click();
      await p.waitForTimeout(2500);
      await p.locator('tr', { hasText: BAL }).first().waitFor({ timeout: 30000 });
      if (await p.locator('tr', { hasText: '00가1113' }).count()) throw new Error('필터 뒤에 요청 없는 차(00가1113)가 보인다');
    });

    await r.step('2단계 차를 열면 맨 위 요청 카드 — 종류 · 금액 · 보낸 영업 · 요청일 · 「재전송 1회」', async () => {
      await p.locator('tr', { hasText: BAL }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2500);
      const card = p.locator('[wire\\:key^="panel-brq-"]', { hasText: '매입잔금' }).first();
      await card.waitFor({ timeout: 20000 });
      const t = await card.innerText();
      for (const s of ['13,600,000원', B.SALES, '재전송 1회', '입금 확인', '거절']) if (!t.includes(s)) throw new Error(`카드에 「${s}」 없음`);
    });

    await r.step('3단계 [입금 확인] → 「확인했습니다 — board 에 회신됩니다」 · 카드 사라짐', async () => {
      const card = p.locator(`[wire\\:key="panel-brq-${balId}"]`);
      await L.expectAfter(p, () => card.getByRole('button', { name: '입금 확인' }).click(), '확인했습니다 — board 에 회신됩니다.', '확인 토스트');
      await L.assertNoViolations(p);
      await card.waitFor({ state: 'detached', timeout: 15000 });
      const row = L.dbOne('select status, confirmed_at, confirmed_by_id from board_requests where id = ?', [balId]);
      L.assertEq(row.status, 'done', 'DB 상태');
      if (!row.confirmed_at || !row.confirmed_by_id) throw new Error('DB confirmed_at/by 비어 있음');
      L.assertEq(Number(L.dbOne("select count(*) n from task_alarms where type = 'board_purchase_balance' and vehicle_id = ? and resolved_at is null", [B.vehicleId(BAL)]).n), 0, 'DB 열린 알람');
    });
    await L.closePanel(p);

    await r.step('4단계 00가1131 계약금 [거절] → 사유 → [거절 회신]', async () => {
      L.unlockVehicles([DEP]);
      await L.openVehicle(p, DEP);
      const card = p.locator(`[wire\\:key="panel-brq-${depId}"]`);
      await card.waitFor({ timeout: 20000 });
      if (!(await card.innerText()).includes('1,000,000원')) throw new Error('계약금 카드에 1,000,000원 없음');
      await card.getByRole('button', { name: '거절', exact: true }).click();
      await L.expectText(p, '거절 사유', '거절 창 「거절 사유」');
      await L.expectText(p, '알림톡은 가지 않습니다', '거절 창 안내');
      await p.getByPlaceholder('예: 바이어 입금이').fill(REASON);
      await L.expectAfter(p, () => p.getByRole('button', { name: '거절 회신' }).click(), '거절했습니다 — board 에 회신됩니다.', '거절 토스트');
      await L.assertNoViolations(p);
      const row = L.dbOne('select status, reject_reason, rejected_by_id from board_requests where id = ?', [depId]);
      L.assertEq(row.status, 'rejected', 'DB 상태');
      L.assertEq(row.reject_reason, REASON, 'DB 사유');
      L.assertEq(Number(L.dbOne("select count(*) n from audit_logs where auditable_id = ? and action = 'board_request_rejected'", [depId]).n), 1, 'DB 감사로그 board_request_rejected');
    });

    await r.step('알아 둘 것: 거절 뒤 같은 차로 다시 요청할 수 있다(새 요청으로 받음)', async () => {
      // board 응답만 본다 — 만든 줄은 시더가 지운다(표식). 화면 확인은 생략.
      const b = B.expectStatus(B.boardSend('POST', '/api/internal/board/requests', {
        salesman_email: B.SALES, type: 'purchase_deposit', vehicle_ids: [B.vehicleId(DEP)], amount_krw: 1000000, note: `${B.NOTE_MARK} 계약금 재요청`,
      }), 201, '재요청');
      if (!(b.created || []).includes(DEP)) throw new Error('거절 뒤 재요청이 created 에 없음');
      L.assertEq(req(DEP, 'purchase_deposit').filter((x) => x.status === 'open').length, 1, 'DB 열린 계약금 요청');
    });
    await L.closePanel(p);
  } finally {
    await c.close();
  }
  L.unlockVehicles([BAL, DEP]);
  return r.result();
};
