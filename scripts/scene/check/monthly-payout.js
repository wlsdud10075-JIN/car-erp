// 씬 점검 — monthly-payout (resources/guides/monthly-payout). 촬영 원본 = cap/mp_scene2.js · mp_final.js · st_probe.js
// ① 재무 finance@ — 정산 처리에서 그 차 줄 [확정]
// ② 업무관리자 guide.manager@ — [월정산 제출] → 결재선(부장 holddemo.bujang · 상무 holddemo.sangmu · 대표 boss) → 제출
// ③ 결재 — 부장 → 상무 → 대표 [승인] (/erp/payout-batches)
// ④ 제출자 guide.manager@ — 카드 펼쳐 [✅ 월정산 최종 확정]
//
// 🚨 [월정산 제출]은 그 달의 **확정 정산 전부** + 미청산 이월(전원) + 대기 중 보류·추가분 + 매입취소 손실(기본 체크)을 같이 올린다
//    (SettlementPayoutBatch::submitForMonth · openSubmitModal). 예시 차 한 대만 올라가는 게 확인될 때만 제출한다:
//    제출 전 DB(대기 보류 0 · 그 달 제출 대상 = 이 차 정산 1건) + 창(「대상 정산 1건」 · 최종 지급총액 = 대상 합계) — 하나라도 어긋나면 멈춘다(제출 안 함).
//    🚫 [이 달 일괄 확정]은 누르지 않는다(남의 대기 정산까지 확정한다). 줄의 [확정]만.
// 🚨 제출·승인은 결재자 알림톡(erp_payout_request)을 시도한다 — 러너가 알림톡 마스터 꺼짐을 먼저 확인한다. [📨 재전송]은 lib 가드(resendRequest)가 막는다.
// 달은 하드코딩하지 않는다 — 그 정산의 attributed_month(귀속월)로 monthFilter 를 만든다(「2026-11 정산 → 2026-12-10 지급」 문구는 날짜에 따라 바뀌어 안 본다).
// ⚠️ 숫자(총마진 2,858,400 · 건당 200,000)는 회사 설정(기본 말소비 24,000 · 건당 구간)에 달렸다 — 설정이 바뀌면 여기서 실패한다(§7-1 「의도한 변경」).
const L = require('./lib');
const X = require('./_loop');

const APPROVERS = [['부장', 'holddemo.bujang@car-erp.test'], ['상무', 'holddemo.sangmu@car-erp.test'], ['대표', 'boss@car-erp.test']];

module.exports.run = async function run({ browser }) {
  const r = L.recorder('monthly-payout');
  let v;
  let st;
  let month;
  let approverIds = {};
  let batchId = null;
  const stRow = () => L.dbOne('select id, settlement_status s, secondary_status s2, payout_batch_id b, attributed_month m from settlements where id = ?', [st.id]);
  const batch = () => L.dbOne('select id, kind, month, status, total_payout, settlement_count, finalized_at from settlement_payout_batches where id = ?', [batchId]);

  await r.step('사전: 앞 씬(export-tab) 결과 — 통관완료 · 정산 「대기」 · 결재 계정', async () => {
    v = X.vehicle();
    st = v && L.dbOne("select id, settlement_status s, attributed_month m, payout_batch_id b from settlements where vehicle_id = ? and deleted_at is null order by id desc limit 1", [v.id]);
    if (!v || v.p !== '통관완료' || !st) { r.skip(`앞 씬 export-tab 이 끝나지 않았다(진행상태 ${v ? v.p : '차 없음'} · 정산 ${st ? st.s : '없음'})`); return '건너뜀'; }
    if (st.s !== 'pending' || st.b) throw new Error(`정산이 대기가 아니다(${st.s} · 배치 ${st.b}) — 시더가 안 돌았다`);
    if (!st.m) throw new Error('정산 귀속월(attributed_month)이 비어 있다 — 달을 정할 수 없다');
    month = String(st.m).slice(0, 7);
    for (const [title, email] of APPROVERS) {
      const u = L.dbOne("select id, approval_title t from users where email = ? and permission = 'admin'", [email]);
      if (!u || u.t !== title) { r.skip(`결재 계정 ${email}(${title})이 없다 — 로컬 데모 시더(PayoutHoldDemoSeeder 등, 미커밋)가 만든 계정`); return '건너뜀'; }
      approverIds[title] = u.id;
    }
    L.unlockVehicles([X.PLATE]);
    return `귀속월 ${month}`;
  });

  const ctx = await L.newContext(browser);
  let p = null;
  const as = async (email) => {
    if (p) await p.close();
    await ctx.clearCookies();
    p = await L.newPage(ctx);
    await L.login(p, email);
  };
  const openSettlements = async () => {
    await p.goto(`${L.BASE}/erp/settlements?monthFilter=${month}`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"]').first().fill(X.PLATE);
    await p.locator('button[wire\\:click="searchNow"]').first().click();   // 정산 처리 검색 [조회](common.search) — NICE 아님
    await p.waitForTimeout(1500);
  };
  const openBatches = async () => {
    await p.goto(`${L.BASE}/erp/payout-batches`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(800);
  };
  try {
    await r.step('1단계 재무 — 정산 처리에서 그 차 줄(건당 · 총마진 ₩2,858,400 · 「대기」)', async () => {
      await as('finance@car-erp.test');
      await openSettlements();
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      for (const k of ['건당', '2,858,400', '대기']) if (!t.includes(k)) throw new Error(`정산 줄에 「${k}」 없음`);
    });

    await r.step('2단계 줄을 눌러 「정산 수정」 — 판매금원화 ~ 총마진', async () => {
      await p.locator('tr', { hasText: X.PLATE }).first().locator('td').nth(1).click();
      await L.expectText(p, '정산 수정', '창 제목');
      for (const n of ['12,600,000', '12,576,000', '2,276,000', '900,000', '2,858,400']) await L.expectText(p, n, `계산 ₩${n}`);
      await X.clickSafe(p.locator('button[wire\\:click="close"]').first());
      await p.waitForTimeout(800);
    });

    await r.step('3단계 [확정] → 「정산이 확정됐습니다.」 → 「확정」', async () => {
      const btn = p.locator('tr', { hasText: X.PLATE }).first().locator(`button[wire\\:click\\.stop="confirmSettlement(${st.id})"]`).first();
      await L.expectAfter(p, () => X.clickSafe(btn), '정산이 확정됐습니다.', '확정 토스트');   // wire:confirm 「이 정산을 확정하시겠습니까?」 = 자동 수락
      await L.assertNoViolations(p);
      L.assertEq(stRow().s, 'confirmed', 'DB 정산 상태');
    });

    await r.step('사전: 이 달 제출 대상 = 이 차 1건뿐 · 대기 보류 0 (남의 정산을 올리지 않게)', async () => {
      const queued = Number(L.dbOne("select count(*) c from payout_holds where status = 'queued'").c);
      if (queued) throw new Error(`중단(제출 안 함): 대기(queued) 지급보류·추가 ${queued}건이 이 월정산에 따라 올라간다`);
      // eligibleSettlementIds 의 1차 조건(귀속월 · confirmed · 미배치)과 같은 식 — NULL 귀속월 폴백(10일 경계)까지.
      const [y, m] = month.split('-').map(Number);
      const p2 = (n) => String(n).padStart(2, '0');
      const start = `${y}-${p2(m)}-10`;
      const end = m === 12 ? `${y + 1}-01-10` : `${y}-${p2(m + 1)}-10`;
      const others = Number(L.dbOne(
        `select count(*) c from settlements where deleted_at is null and settlement_status = 'confirmed' and payout_batch_id is null and id <> ?
           and (attributed_month between ? and ? or (attributed_month is null and coalesce(confirmed_at, created_at) >= ? and coalesce(confirmed_at, created_at) < ?))`,
        [st.id, `${month}-01 00:00:00`, `${month}-01 23:59:59`, start, end]).c);
      if (others) throw new Error(`중단(제출 안 함): 이 달에 다른 확정 정산 ${others}건이 같이 올라간다 — 로컬 데이터 확인`);
    });

    await r.step('4~5단계 업무관리자 [월정산 제출] → 창(대상 1건 · 결재선 · 최종 지급총액)', async () => {
      await as('guide.manager@car-erp.test');
      await openSettlements();
      await X.clickSafe(p.locator('button[wire\\:click="openSubmitModal"]').first());
      const modal = 'div[wire\\:key="submit-modal"]';
      await L.expectText(p, `${month} 월정산 제출`, '창 제목', { scope: modal });
      await L.expectText(p, '대상 정산 1건', '「대상 정산 1건」', { scope: modal });
      await L.expectText(p, '₩200,000', '대상 합계 ₩200,000(건당)', { scope: modal });
      await L.expectText(p, '결재선', '「결재선」', { scope: modal });
      // 매입취소 손실 차감은 기본 전부 체크 — 남의 차 손실이 이 월정산에 붙지 않게 다 푼다(가이드 예시엔 없음).
      for (const cb of await p.locator(`${modal} input[wire\\:model\\.live="submitLossChecked"]`).all()) if (await cb.isChecked()) await cb.uncheck();
      for (const [title] of APPROVERS) await p.locator(`${modal} select[wire\\:model="submitLine.${title}"]`).selectOption(String(approverIds[title]));
      await p.waitForTimeout(800);
      const txt = await p.locator(modal).innerText();
      const fin = (txt.match(/최종 지급총액\s*₩([\d,]+)/) || [])[1];
      if (fin !== '200,000') throw new Error(`중단(제출 안 함): 최종 지급총액 ₩${fin || '?'} ≠ 대상 합계 ₩200,000 — 이월·보류 줄이 섞였다`);
    });

    await r.step('5단계 [월정산 제출] → 「월정산 제출됨 (1건). 승인큐에서 진행됩니다.」', async () => {
      await L.expectAfter(p, () => p.locator('div[wire\\:key="submit-modal"] button[wire\\:click="submitPayoutBatch"]').click(),
        '월정산 제출됨 (1건). 승인큐에서 진행됩니다.', '제출 토스트', { timeout: 45000 });
      await L.assertNoViolations(p);
      batchId = stRow().b;
      if (!batchId) throw new Error('DB: 정산이 배치에 안 들어갔다');
      const b = batch();
      L.assertEq(b.kind, 'month', 'DB 배치 종류');
      L.assertEq(b.month, month, 'DB 배치 달');
      L.assertEq(b.status, 'pending', 'DB 배치 상태');
      L.assertEq(Number(b.settlement_count), 1, 'DB 배치 건수');
      L.assertEq(Number(b.total_payout), 200000, 'DB 배치 총액');
      const steps = L.dbQuery('select status from settlement_payout_batch_steps where batch_id = ? order by id', [batchId]);
      L.assertEq(steps.length, 3, 'DB 결재선 단계 수');
      return `배치 #${batchId}`;
    });

    // 이 배치 카드만 — 화면엔 촬영 때 배치(#11, 역시 1건 ₩200,000 · 최종 확정)도 있어 본문 전체로 찾으면 그쪽이 대신 맞춘다.
    const card = () => p.locator('div.card-tight').filter({ has: p.locator(`button[wire\\:click="toggle(${batchId})"]`) }).first();
    const cardHas = async (t, what) => {
      await p.waitForFunction(([id, s]) => {
        const b = document.querySelector(`button[wire\\:click="toggle(${id})"]`);
        const c = b && b.closest('.card-tight');
        return !!c && c.innerText.includes(s);
      }, [batchId, t], { timeout: 15000 }).catch(() => { throw new Error(`[${what}] 배치 #${batchId} 카드에 「${t}」 없음`); });
    };

    await r.step('6단계 「사내직원 월정산」 카드 — 대기 · 1건 · ₩200,000 · 다음 결재: 부장', async () => {
      await openBatches();
      if (!(await card().count())) throw new Error(`배치 #${batchId} 카드가 안 보인다(열람 권한 — staff_payout_viewers)`);
      for (const t of ['대기', '1건', '₩200,000', '다음 결재: 부장']) await cardHas(t, `카드 「${t}」`);
    });

    for (const [title, email] of APPROVERS) {
      await r.step(`6~7단계 ${title} [승인] → 「승인 처리됐습니다.」`, async () => {
        await as(email);
        await openBatches();
        const btn = p.locator(`button[wire\\:click="approve(${batchId})"]`).first();
        if (!(await btn.count())) throw new Error(`${title} 화면에 이 배치 [승인]이 없다(차례·열람 권한)`);
        // wire:confirm 「이 배치를 승인합니까? (대표 최종 승인이면 …)」 = 자동 수락
        await L.expectAfter(p, () => X.clickSafe(btn), '승인 처리됐습니다.', `${title} 승인 토스트`);
        await L.assertNoViolations(p);
        const s = L.dbQuery('select status from settlement_payout_batch_steps where batch_id = ? order by id', [batchId]);
        const done = s.filter((x) => x.status === 'approved').length;
        L.assertEq(done, APPROVERS.findIndex((a) => a[0] === title) + 1, `DB 승인된 단계 수(${title} 뒤)`);
      });
    }

    await r.step('7단계 대표 승인 뒤 — 배치 승인 · 정산 「지급완료」 · 2차 「비용 대기」', async () => {
      L.assertEq(batch().status, 'approved', 'DB 배치 상태');
      const s = stRow();
      L.assertEq(s.s, 'paid', 'DB 정산 상태(지급완료)');
      L.assertEq(s.s2, 'pending', 'DB 2차 정산(비용 대기)');
    });

    await r.step('8~9단계 제출자 — 카드 펼침 → [✅ 월정산 최종 확정] → 「최종 확정했습니다. …」', async () => {
      await as('guide.manager@car-erp.test');
      await openBatches();
      await X.clickSafe(p.locator(`button[wire\\:click="toggle(${batchId})"]`).first());
      await p.waitForTimeout(1500);
      for (const t of ['총마진', '실지급액', '2,858,400']) await cardHas(t, `사람별 줄 「${t}」`);
      const fb = p.locator(`button[wire\\:click="finalizeBatch(${batchId})"]`).first();
      if (!(await fb.count())) throw new Error('[✅ 월정산 최종 확정] 버튼 없음');
      if (!(await fb.innerText()).includes('월정산 최종 확정')) throw new Error('최종 확정 버튼 글자가 다르다');
      // wire:confirm 「이 월정산을 최종 확정할까요? … 되돌릴 수 없습니다.」 = 자동 수락
      await L.expectAfter(p, () => X.clickSafe(fb), '최종 확정했습니다. 엑셀을 받아 재무에 넘기세요.', '최종 확정 토스트');
      await L.assertNoViolations(p);
      if (!batch().finalized_at) throw new Error('DB: finalized_at 비어 있음');
      await cardHas('✅ 최종 확정', '카드 제목 「✅ 최종 확정 …」');
    });
    // 안 보는 가이드 단계(결재자 알림톡 버튼 · 카드 [엑셀] 내용)는 notes 에만 적는다 — 단계로 기록하면 run.js 가 매주 「일부 건너뜀」으로 센다.
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
