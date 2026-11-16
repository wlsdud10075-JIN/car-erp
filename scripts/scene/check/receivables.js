// 씬 점검 — receivables (resources/guides/receivables). 촬영 원본 = cap/rv_scene.js · rv_mgr.js · rv_confirm.js · mk_rv.php
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = finance@. 차 = 00가1131(USD 10,000 · 계약금 3,000 확정 → 미납 7,000).
// 흐름: 목록 탭 → 패널 · 채권담당자 [지정] → 「입금」 2,000 @1,450(미수 그대로·재무 확정 대기) → 「현금」 500(7,000 → 6,500)
//      → 재무 처리 [재무 처리 완료](6,500 → 4,500).
// 💳 현금 원장이 켜진 회사(로컬 heyman = 켜짐, 2026-11-16 실측)는 확정 전에 「현금」 탭 입금 기재가 있어야 한다 —
//    먼저 확정을 눌러 「현금이 부족합니다」(가이드 알아 둘 것)를 보고, 2,000 만 기재한 뒤 다시 확정한다.
// ⚠️ buyer-account(1단계) 보다 먼저 — 그 씬이 같은 차에 2,000 을 더 확정한다.
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1131';
const BUYER = 'GUIDE SAMPLE TRADING';
const DEP_NOTE = '11/14 송금 확인';
const CASH_NOTE = '현지 대리인 현금 수령';
const RCPT_NOTE = '채권관리 예시 입금 (씬 점검)';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('receivables');
  let veh;
  let financeId;
  let u0;
  const cache = () => Number(L.dbOne('select sale_unpaid_amount_krw_cache u from vehicles where id = ?', [veh.id]).u);
  const hist = (method, note) => L.dbOne('select h.id, h.amount, h.final_payment_id fp, f.confirmed_at c from receivable_histories h left join final_payments f on f.id = h.final_payment_id where h.vehicle_id = ? and h.method = ? and h.note = ?', [veh.id, method, note]);
  const ledger = H.cashLedgerOn();

  await r.step('사전: 1131 미납 USD 7,000 · 채권담당 미지정 · 씬 이력 없음(시더)', async () => {
    veh = L.dbOne('select id, currency, exchange_rate, sale_price, receivable_manager_id from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
    if (!veh) throw new Error('예시 차량 00가1131 없음');
    L.assertEq(veh.currency, 'USD', '통화');
    const paid = Number(L.dbOne('select coalesce(sum(amount),0) s from final_payments where vehicle_id = ? and confirmed_at is not null', [veh.id]).s);
    L.assertEq(Number(veh.sale_price) - paid, 7000, '판매가 − 확정 잔금(USD)');
    if (hist('deposit', DEP_NOTE) || hist('cash', CASH_NOTE)) throw new Error('씬 회수 이력이 남아 있다 — 시더가 안 돌았다');
    if (veh.receivable_manager_id) throw new Error('채권담당자가 지정돼 있다 — 시더가 안 돌았다');
    financeId = (L.dbOne("select id from users where email = 'finance@car-erp.test'") || {}).id;
    if (!financeId) throw new Error('finance@ 계정 없음');
    u0 = cache();
    return `현금 원장 ${ledger.on ? '켜짐' : '꺼짐'}(${ledger.set})`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const openRow = async () => {
    await p.goto(`${L.BASE}/erp/receivables`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
    await p.getByRole('button', { name: '조회', exact: true }).click();   // 채권관리 검색 [조회] — NICE 아님
    await p.waitForTimeout(1800);
  };
  try {
    await r.step('1단계 「채권관리」 탭 · 숫자 칸 · 위험도', async () => {
      await L.login(p, 'finance@car-erp.test');
      await p.goto(`${L.BASE}/erp/receivables`, { waitUntil: 'networkidle', timeout: 120000 });
      for (const t of ['채권 전체', '결제대기', '선적전 미수', '선적후 미수', '완납', '위험도']) await L.expectText(p, t, `「${t}」`);
    });

    await r.step('2단계 줄을 누르면 「회수 이력」 · 채권담당자 [지정]', async () => {
      await openRow();
      await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2200);
      for (const t of ['회수 이력', '채권담당자', '영업담당자와 같게', '미납금', '회수 이력 추가']) await L.expectText(p, t, `패널 「${t}」`);
      await p.locator('select[wire\\:model="managerIdInput"]').selectOption(String(financeId));
      await L.expectAfter(p, () => p.getByRole('button', { name: '지정', exact: true }).click(), '채권담당자가 지정됐습니다.', '[지정] 토스트');
      await L.assertNoViolations(p);
      L.assertEq(L.dbOne('select receivable_manager_id m from vehicles where id = ?', [veh.id]).m, financeId, 'DB 채권담당자');
    });

    await r.step('3단계 「입금」 2,000 → 판매 잔금 생성 · 미수 그대로 · 「재무 확정 대기」', async () => {
      await L.expectText(p, '입금 (판매 잔금 자동 생성)', '회수 방법 「입금 (판매 잔금 자동 생성)」');
      await p.locator('input[wire\\:model="hAmount"]').fill('2000');
      await p.locator('input[wire\\:model="hExchangeRate"]').fill('1450');
      await p.locator('textarea[wire\\:model="hNote"]').fill(DEP_NOTE);
      await L.expectAfter(p, () => p.getByRole('button', { name: '추가', exact: true }).click(), '회수 이력이 추가됐습니다.', '[추가] 토스트');
      await L.assertNoViolations(p);
      const h = hist('deposit', DEP_NOTE);
      if (!h || !h.fp) throw new Error('DB: 입금 → 판매 잔금 연결 없음');
      if (h.c) throw new Error('DB: 잔금이 벌써 확정돼 있다');
      L.assertEq(cache(), u0, 'DB 미수(원화 캐시) 그대로');
      await L.expectText(p, 'USD 7,000', '패널 미납금 USD 7,000 그대로');
      await openRow();
      // 뱃지는 서랍 안 「회수 이력」 목록의 그 줄에 붙는다(receivables/index.blade.php `pending_confirm`) — 줄을 눌러 서랍을 연다
      await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2200);
      await L.expectText(p, '재무 확정 대기', '회수 이력 줄 「재무 확정 대기」');
    });

    await r.step('4단계 「현금」 500 → 바로 줄어듦(7,000 → 6,500)', async () => {
      await openRow();   // 3단계가 서랍을 열어 둔 채라 목록 줄이 가려진다 — 페이지를 새로 연다
      await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2200);
      await p.locator('select[wire\\:model="hMethod"]').first().selectOption('cash');
      await p.waitForTimeout(800);
      await p.locator('input[wire\\:model="hAmount"]').fill('500');
      await p.locator('input[wire\\:model="hExchangeRate"]').fill('1450').catch(() => {});
      await p.locator('textarea[wire\\:model="hNote"]').fill(CASH_NOTE);
      await L.expectAfter(p, () => p.getByRole('button', { name: '추가', exact: true }).click(), '회수 이력이 추가됐습니다.', '[추가] 토스트(현금)');
      await L.assertNoViolations(p);
      const h = hist('cash', CASH_NOTE);
      if (!h) throw new Error('DB: 현금 회수 이력 없음');
      if (h.fp) throw new Error('DB: 현금인데 판매 잔금이 생겼다');
      const ratio = cache() / u0;
      if (Math.abs(ratio - 6500 / 7000) > 0.002) throw new Error(`DB 미수 캐시가 7,000→6,500 비율로 안 줄었다(${ratio.toFixed(4)})`);
      await L.expectText(p, 'USD 6,500', '패널 미납금 USD 6,500');
    });

    await r.step('5단계 「재무 처리」 → [재무 처리 완료] → 6,500 → 4,500', async () => {
      const goConfirm = async () => {
        await p.goto(`${L.BASE}/erp/transfers?tabType=sale_payment&search=${encodeURIComponent(PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
        await p.waitForTimeout(1200);
        await p.locator('tr', { hasText: PLATE }).first().locator('button', { hasText: '재무 처리 완료' }).first().click();
        await L.expectText(p, '판매 잔금 재무 확정', '확정 창 제목');
      };
      if (ledger.on) {
        // 가이드 알아 둘 것 — 현금을 먼저 기재하지 않으면 확정이 막힌다
        await goConfirm();
        await L.expectAfter(p, () => p.locator('button[wire\\:click="confirmPayment"]').first().click(), '현금이 부족합니다', '현금 미기재 확정 실패 문구');
        if (hist('deposit', DEP_NOTE).c) throw new Error('DB: 현금 없이 확정됐다');
        await H.addCashReceipt(p, BUYER, { amount: 2000, note: RCPT_NOTE });
      }
      await goConfirm();
      await L.expectAfter(p, () => p.locator('button[wire\\:click="confirmPayment"]').first().click(), '판매 잔금 재무 확정 완료', '확정 토스트');
      await L.assertNoViolations(p);
      if (!hist('deposit', DEP_NOTE).c) throw new Error('DB: 잔금이 확정되지 않았다');
      const ratio = cache() / u0;
      if (Math.abs(ratio - 4500 / 7000) > 0.002) throw new Error(`DB 미수 캐시가 4,500 비율이 아니다(${ratio.toFixed(4)})`);
      const st = L.dbOne("select count(*) c from settlements where vehicle_id = ? and deleted_at is null", [veh.id]);
      if (Number(st.c) > 0) throw new Error('정산이 생겼다 — 완납 진입(예시 차 상태 확인)');
      await openRow();
      await p.locator('tr', { hasText: PLATE }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2200);
      await L.expectText(p, 'USD 4,500', '패널 미납금 USD 4,500');
      await L.expectNoText(p, '재무 확정 대기', '확정 뒤 「재무 확정 대기」 사라짐');
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
