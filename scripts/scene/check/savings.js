// 씬 점검 — savings (resources/guides/savings). 촬영 원본 = cap/sv_scene.js (+ cashtoggle.php — 🚫 점검에서는 토글을 안 바꾼다)
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = finance@. 바이어 GUIDE SAMPLE TRADING · 적립 00가1131 · 사용 00가1141 (USD 100).
// 💳 가이드 그림 = 현금 원장을 쓰지 않는 회사. 로컬(heyman)은 켜져 있어(2026-11-16 실측) 외화 적립이 남은 현금에서 나온다 —
//    켜져 있으면 「현금」 탭에 100 만 먼저 기재한다(비고 표식). 꺼져 있으면 그대로 적립.
// ⚠️ unpaid-gate-override 보다 뒤(1141 입금률이 바뀐다) · buyer-account 보다 앞(현금 FIFO).
const L = require('./lib');
const H = require('./p3-helpers');

const BUYER = 'GUIDE SAMPLE TRADING';
const EARN = '00가1131';
const USE = '00가1141';
const RCPT_NOTE = '적립금 예시 입금 (씬 점검)';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('savings');
  let buyer;
  const ledger = H.cashLedgerOn();
  const rows = () => L.dbQuery("select s.transaction_type t, s.savings, s.balance, v.vehicle_number pl from savings_statuses s left join vehicles v on v.id = s.vehicle_id where s.buyer_id = ? and s.currency = 'USD' order by s.id", [buyer.id]);
  const used = () => Number(L.dbOne('select savings_used u from vehicles where vehicle_number = ? and deleted_at is null', [USE]).u);

  await r.step('사전: 이 바이어 USD 적립금 이력 없음 · 1141 적립금 사용 0(시더)', async () => {
    buyer = L.dbOne('select id from buyers where name = ? and deleted_at is null', [BUYER]);
    if (!buyer) throw new Error('예시 바이어 없음');
    if (rows().length) throw new Error('USD 적립금 이력이 남아 있다 — 시더가 안 돌았다');
    L.assertEq(used(), 0, '1141 적립금 사용');
    L.unlockVehicles([EARN, USE]);
    return `현금 원장 ${ledger.on ? '켜짐 — 100 먼저 기재' : '꺼짐'}`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const openSale = async (plate) => {
    await L.openVehicle(p, plate, 'sale');
    await p.locator('[data-savings-use]').evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await p.waitForTimeout(400);
  };
  try {
    await r.step('1단계 1131 판매 탭 「적립금 적립」 100 → [수정 저장]', async () => {
      await L.login(p, 'finance@car-erp.test');
      if (ledger.on) await H.addCashReceipt(p, BUYER, { amount: 100, note: RCPT_NOTE });
      await openSale(EARN);
      await L.expectText(p, '적립금 적립', '「적립금 적립」');
      await L.expectText(p, '누적 적립금', '「누적 적립금」');
      await p.locator('input[wire\\:model="savings_deposit_str"]').fill('100');
      await p.keyboard.press('Tab');
      await H.saveVehicle(p, '적립 저장');
      const rs = rows();
      L.assertEq(rs.length, 1, 'DB 적립금 이력 행 수');
      L.assertEq(rs[0].t, 'EARNED', 'DB 종류');
      L.assertEq(Number(rs[0].savings), 100, 'DB 적립액');
      L.assertEq(rs[0].pl, EARN, 'DB 적립 차량');
      // [수정 저장]은 패널을 닫는다 — 다시 열어 칸이 비었는지 본다
      await openSale(EARN);
      L.assertEq(await p.locator('input[wire\\:model="savings_deposit_str"]').inputValue(), '', '저장 뒤 칸 비워짐');
      if (ledger.on) {
        const fee = L.dbOne("select amount from buyer_cash_fees where buyer_id = ? and kind = 'savings' order by id desc", [buyer.id]);
        if (!fee || Number(fee.amount) !== 100) throw new Error('DB: 현금 원장 적립 전환 행(kind=savings) 100 없음');
      }
      await L.closePanel(p);
    });
    L.unlockVehicles([EARN, USE]);

    await r.step('2단계 1141 「적립금 사용」 100 → 미수 감소 · 「입금 외 정리」', async () => {
      const u0 = Number(L.dbOne('select sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ?', [USE]).u);
      await openSale(USE);
      await p.locator('input[wire\\:model="savings_used_str"]').fill('100');
      await p.keyboard.press('Tab');
      await H.saveVehicle(p, '사용 저장');
      L.assertEq(used(), 100, 'DB 1141 적립금 사용');
      const rs = rows();
      const u = rs.find((x) => x.t === 'USED');
      if (!u || Number(u.savings) !== -100) throw new Error('DB: USED −100 행 없음');
      L.assertEq(Number(rs[rs.length - 1].balance), 0, 'DB 남은 적립금');
      const u1 = Number(L.dbOne('select sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ?', [USE]).u);
      if (!(u1 < u0)) throw new Error(`DB 미수가 안 줄었다(${u0} → ${u1})`);
      await L.closePanel(p);
      L.unlockVehicles([USE]);
      await openSale(USE);
      await L.expectText(p, '입금 외 정리', '「입금 외 정리」');
      await L.expectText(p, '적립금 사용 USD 100', '「적립금 사용 USD 100」');
      await L.closePanel(p);
    });

    await r.step('3단계 바이어 관리 → 「적립금」 탭 — 통화별 잔액 · 이력 · 선입선출', async () => {
      await p.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(BUYER)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(1200);
      await p.locator('tr', { hasText: BUYER }).first().click();
      await p.waitForTimeout(2000);
      await p.locator('button', { hasText: /^적립금$/ }).first().click();
      await p.waitForTimeout(800);
      for (const t of ['거래 추가', EARN, USE]) await L.expectText(p, t, `적립금 탭 「${t}」`);
      // 「선입선출」은 사용 줄을 눌러야 열리는 상세 안에 있다(11-17 — 한 건 한 줄 + 누르면 상세).
      await p.locator('[data-savings-row]', { hasText: USE }).first().click();
      await p.waitForTimeout(600);
      await L.expectText(p, '선입선출', '적립금 사용 줄 상세 「선입선출」');
      await L.assertNoViolations(p);
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles([EARN, USE]);
  return r.result();
};
