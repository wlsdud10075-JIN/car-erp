// 씬 점검 — purchase-payment (resources/guides/purchase-payment). 촬영 원본 = cap/pp_scene.js · pp_c.js · pp_d.js
// 계정 = finance@ (재무 — 넣은 지급이 저장하는 순간 확정). 차 = _loop.PLATE (vehicle-register 가 만든 차).
// 🚫 ②(딜러 입금완료 알림톡)는 안 본다 — [📨 딜러알림]은 알림톡이 실제로 나갈 수 있을 때만 그려진다
//    (vehicles/index `paidNoticeEnabled` = 마스터 on + 토글 + tmplId). 러너는 마스터가 켜져 있으면 멈추므로 버튼이 원리상 없다.
//    촬영 때는 altoggle.php 로 잠깐 켰다 — 점검에서는 🚫 켜지 않는다.
const L = require('./lib');
const X = require('./_loop');

module.exports.run = async function run({ browser }) {
  const r = L.recorder('purchase-payment');
  const { iso } = L.today();
  let v;
  const pbp = () => L.dbQuery('select type, amount, payment_date, note, confirmed_at is not null c from purchase_balance_payments where vehicle_id = ? order by id', [v.id]);

  await r.step('사전: 앞 씬(vehicle-register) 결과 — 매입가 있고 지급 0건', async () => {
    v = X.vehicle();
    if (!v || Number(v.purchase_price) !== 10000000) { r.skip('앞 씬 vehicle-register 가 끝나지 않았다(차·매입가 없음)'); return '건너뜀'; }
    if (pbp().length) throw new Error('매입 지급 줄이 이미 있다 — 시더가 안 돌았다');
    L.unlockVehicles([X.PLATE]);
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 차를 열고 매입 탭 「매입 지급」 — 계약금 · 매도비 · 잔금 [+ 추가]', async () => {
      await L.login(p, 'finance@car-erp.test');
      await L.openVehicle(p, X.PLATE, 'purchase');
      for (const t of ['매입 지급', '보증금으로 매입']) await L.expectText(p, t, `「${t}」`);
      await X.typeMoney(p, 'input[wire\\:model="down_payment_str"]', 1000000);
      await X.typeMoney(p, 'input[wire\\:model="selling_fee_payment_str"]', 300000);
      await p.locator('button[wire\\:click="addPurchasePayment"]').click();
      await p.waitForTimeout(800);
      await X.typeMoney(p, 'input[wire\\:model="purchaseBalancePayments.0.amount"]', 9000000);
      await X.typeDate(p, 'input[wire\\:model="purchaseBalancePayments.0.payment_date"]', iso);   // 🚫 앞날짜 금지 — 「그날이 되어야 지급 완료」
      await p.locator('input[wire\\:model="purchaseBalancePayments.0.note"]').fill('잔금');
      await p.waitForTimeout(400);
    });

    await r.step('2단계 [수정 저장] — 재무가 넣은 지급은 바로 확정(확인 창 없음)', async () => {
      await X.saveEdit(p, '매입 지급 저장');   // 재무 = 확인 창 없이 저장(requestSave: 영업·관리만 창)
      const rows = pbp();
      const by = (t) => rows.filter((x) => x.type === t);
      L.assertEq(by('down').length, 1, 'DB 계약금 줄');
      L.assertEq(Number(by('down')[0].amount), 1000000, 'DB 계약금');
      L.assertEq(by('down')[0].note, '계약금', 'DB 계약금 비고');
      L.assertEq(by('selling_fee').length, 1, 'DB 매도비 줄');
      L.assertEq(Number(by('selling_fee')[0].amount), 300000, 'DB 매도비');
      L.assertEq(by('selling_fee')[0].note, '매도비', 'DB 매도비 비고');
      L.assertEq(by('balance').length, 1, 'DB 잔금 줄');
      L.assertEq(Number(by('balance')[0].amount), 9000000, 'DB 잔금');
      L.assertEq(String(by('balance')[0].payment_date).slice(0, 10), iso, 'DB 잔금 날짜');
      const unconfirmed = rows.filter((x) => Number(x.c) !== 1).length;
      if (unconfirmed) throw new Error(`DB: 확정 안 된 줄 ${unconfirmed}건 — 재무 저장이 바로 확정되지 않았다`);
    });

    await r.step('2단계 다시 열면 계약금·매도비도 잔금 목록에 「✓ 확정」', async () => {
      L.unlockVehicles([X.PLATE]);
      await L.openVehicle(p, X.PLATE, 'purchase');
      await L.expectText(p, '✓ 확정', '「✓ 확정」');
    });

    await r.step('3단계 「매입 미지급 요약」 ₩0 · ✓ 완납 → 「매입완료」', async () => {
      for (const t of ['매입 미지급 요약', '10,300,000', '✓ 완납']) await L.expectText(p, t, `요약 「${t}」`);
      await L.closePanel(p);
      L.assertEq(X.vehicle().p, '매입완료', 'DB 진행상태');
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      if (!t.includes('매입완료')) throw new Error('목록 줄에 「매입완료」 없음');
    });

    await r.step('4단계 「국내 딜러 알림톡」 칸이 있다', async () => {
      L.unlockVehicles([X.PLATE]);
      await L.openVehicle(p, X.PLATE, 'purchase');
      await L.expectText(p, '국내 딜러 알림톡', '「국내 딜러 알림톡」');
      await L.closePanel(p);
    });
    // 5~6단계 [📨 딜러알림]은 안 본다 — 알림톡이 꺼진 로컬에선 버튼이 안 그려진다(점검은 알림톡을 켜지 않는다).
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
