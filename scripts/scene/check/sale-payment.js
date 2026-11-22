// 씬 점검 — sale-payment (resources/guides/sale-payment). ⚠️ 촬영 스크립트가 따로 없다(scene1 계열에서 이어 찍음) —
//   가이드 본문 + vehicles/index · buyers/index 뷰에서 새로 썼다. 계정 = finance@. 차 = _loop.PLATE · 바이어 = _loop.BUYER.
// 흐름: (바이어 현금 사용 회사면) 바이어 「현금」 탭 입금 기재 USD 10,000 → 차량 판매 탭 잔금 [+ 추가] (날짜·환율 1,400·10,000) → [수정 저장]
//   ⇒ 완납 → 「판매완료」. 🚨 완납이라도 인코텀즈가 비어 있으면 정산이 아직 안 생긴다(월정산 가이드 1단계 「판매 입금 때가 아니라」).
//   그래서 이 씬에선 정산 알림(erp_settle_pending)이 안 울린다 — 울리는 건 export-tab(인코텀즈 저장) 이다.
const L = require('./lib');
const X = require('./_loop');

module.exports.run = async function run({ browser }) {
  const r = L.recorder('sale-payment');
  const { iso } = L.today();
  let v;
  let buyer;
  let cashOn = false;
  const receipt = () => L.dbOne('select id, currency, amount from buyer_cash_receipts where buyer_id = ? and note = ?', [buyer.id, X.RECEIPT_NOTE]);
  const allocated = (rid) => Number(L.dbOne('select coalesce(sum(amount),0) s from buyer_cash_allocations where receipt_id = ?', [rid]).s);

  await r.step('사전: 앞 씬(sale-register) 결과 — 판매중 · 미입금 USD 10,000 · 잔금 0건', async () => {
    v = X.vehicle();
    buyer = X.buyerRow();
    if (!v || v.p !== '판매중' || !buyer) { r.skip(`앞 씬 sale-register 가 끝나지 않았다(진행상태 ${v ? v.p : '차 없음'})`); return '건너뜀'; }
    const fp = L.dbOne('select count(*) c from final_payments where vehicle_id = ?', [v.id]);
    if (Number(fp.c)) throw new Error('판매 잔금 줄이 이미 있다 — 시더가 안 돌았다');
    const set = (L.dbOne("select value from settings where `key` = 'company_template_set'") || {}).value || 'system';
    const on = L.dbOne('select value from settings where `key` = ?', [`buyer_cash_enabled_${set}`]);
    cashOn = !!on && ['1', 'true'].includes(String(on.value));
    L.unlockVehicles([X.PLATE]);
    return `회사 세트 ${set} · 바이어 현금 ${cashOn ? '사용' : '미사용'}`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 (바이어 현금) 바이어 → 「현금」 탭 → 입금 기재 USD 10,000', async () => {
      await L.login(p, 'finance@car-erp.test');
      if (!cashOn) return '건너뜀 — 이 회사는 바이어 현금 미사용(가이드: ①을 건너뛰고 ②부터)';
      await p.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(X.BUYER)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(1200);
      await p.locator('tr', { hasText: X.BUYER }).first().click();
      await p.waitForTimeout(2000);
      await p.locator('button', { hasText: /^현금$/ }).first().click();
      await p.waitForTimeout(800);
      // 「통화별 현금」 칸은 그 바이어에 입금이 하나라도 있어야 그려진다(buyers/index.blade.php `@if(count($cashBalances))`) — 전용 바이어는 처음엔 비어 있으므로 기재 뒤에 본다.
      await p.locator('input[wire\\:model="cash_date"]').fill(iso);
      await p.locator('select[wire\\:model="cash_currency"]').selectOption('USD');
      await p.locator('input[wire\\:model="cash_amount"]').fill('10000');
      await p.locator('input[wire\\:model="cash_note"]').fill(X.RECEIPT_NOTE);
      await L.expectAfter(p, () => p.getByRole('button', { name: '입금 기재' }).click(), '입금을 기재했습니다.', '[입금 기재]');
      await L.assertNoViolations(p);
      await L.expectText(p, '통화별 현금', '「통화별 현금」');
      await L.expectText(p, '남은 현금 → 적립금', '「남은 현금 → 적립금」(누르지 않음)');
      await L.expectText(p, '10,000.00', '남은 현금 USD 10,000.00');
      await L.expectText(p, '아직 안 씀', '입금 「아직 안 씀」');
      const rc = receipt();
      if (!rc) throw new Error('DB: 입금 기재 행 없음');
      L.assertEq(Number(rc.amount), 10000, 'DB 입금액');
      L.assertEq(rc.currency, 'USD', 'DB 통화');
    });

    await r.step('2단계 차량 판매 탭 → 「잔금」 [+ 추가] → 날짜 · 환율 1,400 · 금액 10,000', async () => {
      await L.openVehicle(p, X.PLATE, 'sale');
      if (cashOn) await L.expectText(p, '판매잔금은 이 금액 안에서만 들어갑니다', '쓸 수 있는 현금 안내(초록)');
      await p.locator('button[wire\\:click="addFinalPayment"]').click();
      await p.waitForTimeout(800);
      await X.typeDate(p, 'input[wire\\:model\\.live="finalPayments.0.payment_date"]', iso);
      // 날짜를 넣으면 그날 환율이 자동으로 들어온다(외부 환율 조회 — 실패해도 회귀 아님). 정산은 실제 환율 1,400 으로 고쳐 넣는다.
      await p.waitForTimeout(2000);
      // 💱 환율 자동 기록이 켜진 회사는 입력칸이 없다(서버가 전날 마감을 적는다) — 채우지 않는다.
      if (!L.rateAutoOn()) await p.locator('input[wire\\:model="finalPayments.0.exchange_rate"]').fill('1400');
      await X.typeMoney(p, 'input[wire\\:model="finalPayments.0.amount"]', 10000);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(600);
    });

    await r.step('3단계 [수정 저장] — 재무가 넣은 잔금은 바로 확정(확인 창 없음)', async () => {
      await X.saveEdit(p, '판매 잔금 저장');
      const fp = L.dbQuery('select amount, exchange_rate, payment_date, confirmed_at is not null c from final_payments where vehicle_id = ?', [v.id]);
      L.assertEq(fp.length, 1, 'DB 잔금 줄 수');
      L.assertEq(Number(fp[0].amount), 10000, 'DB 잔금 금액');
      if (L.rateAutoOn()) L.assertEq(Number(fp[0].exchange_rate) > 0, true, `DB 잔금 환율(자동 기록, ${fp[0].exchange_rate})`);
      else L.assertEq(Number(fp[0].exchange_rate), 1400, 'DB 잔금 환율');
      L.assertEq(Number(fp[0].c), 1, 'DB 잔금 확정');
      if (cashOn) {
        const rc = receipt();
        L.assertEq(allocated(rc.id), 10000, 'DB 바이어 현금에서 쓴 금액');
      }
      v = X.vehicle();
      L.assertEq(Number(v.u), 0, 'DB 미수(원화 캐시)');
      L.assertEq(v.p, '판매완료', 'DB 진행상태');
      // 월정산 가이드 1단계 근거 — 인코텀즈가 비어 있으면 완납이라도 정산이 아직 없다.
      const st = L.dbOne('select count(*) c from settlements where vehicle_id = ? and deleted_at is null', [v.id]);
      L.assertEq(Number(st.c), 0, 'DB 정산(인코텀즈 전이라 아직 없음)');
    });

    await r.step('3단계 다시 열면 ✓ 완납 · 🔒 「채권관리에서 수정」 · 「현금에서 차감」', async () => {
      L.unlockVehicles([X.PLATE]);
      await L.openVehicle(p, X.PLATE, 'sale');
      await L.expectText(p, '✓ 완납', '「✓ 완납」');
      await L.expectText(p, '채권관리에서 수정', '「채권관리에서 수정」');
      await L.expectText(p, '전신문 첨부', '「전신문 첨부」');
      if (cashOn) await L.expectText(p, '현금에서 차감', '「현금에서 차감 … ← 입금」');
      await L.closePanel(p);
    });

    await r.step('4단계 목록 「판매완료」 · (바이어 현금) 차량에 쓴 돈 10,000.00 · 쓴 내역에 차', async () => {
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      if (!t.includes('판매완료')) throw new Error('목록 줄에 「판매완료」 없음');
      if (!cashOn) return;
      await p.goto(`${L.BASE}/erp/buyers?search=${encodeURIComponent(X.BUYER)}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(1200);
      await p.locator('tr', { hasText: X.BUYER }).first().click();
      await p.waitForTimeout(2000);
      await p.locator('button', { hasText: /^현금$/ }).first().click();
      await p.waitForTimeout(800);
      await L.expectText(p, '차량에 쓴 돈', '「차량에 쓴 돈」');
      await L.expectText(p, X.PLATE, '쓴 내역에 그 차');
      await L.assertNoViolations(p);
      // 「남은 현금 0.00」은 화면 글자로 못 가른다(「10,000.00」 안에도 「0.00」이 있다) — DB 로 본다.
      const rc = receipt();
      L.assertEq(Number(rc.amount) - allocated(rc.id), 0, 'DB 남은 현금 0');
    });
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
