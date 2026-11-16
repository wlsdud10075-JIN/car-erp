// 씬 점검 — board-sale-payment-check (resources/guides/board-sale-payment-check). 촬영 원본 = cap/sp_scene.js
// 계정 = finance@. 예시 = GUIDE SAMPLE TRADING 의 00가1141 · 00가1131 두 대가 한 묶음으로 「판매대금 확인해 주세요」.
// 00가1141 만 [입금 확인] → 00가1131 은 남는다(가이드 「묶음 중 들어온 차만 확인하고 나머지는 남겨 둘 수 있습니다」).
//
// 🚫 판매 탭에 입금을 넣지 않는다 — 3단계는 「입금 현황」 칸이 거기 있다는 것만 본다(입금은 buyer-account·sale-payment 씬 몫).
//    [입금 확인]은 금액을 기록하지 않는다(가이드) → 미수 캐시가 그대로인지 DB 로 본다.
const L = require('./lib');
const B = require('./board');

const A = '00가1141';
const Bv = '00가1131';
const BUYER = 'GUIDE SAMPLE TRADING';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('board-sale-payment-check');
  const unpaid = (plate) => L.dbOne('select sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ? and deleted_at is null', [plate]).u;
  const reqOf = (plate) => L.dbOne(
    "select b.id, b.status, b.batch_id from board_requests b join vehicles v on v.id = b.vehicle_id where v.vehicle_number = ? and b.type = 'sale_payment_confirm' and b.note like '[씬점검]%' order by b.id desc limit 1", [plate]);
  let uA = null;
  let batch = null;

  await r.step('사전: 두 차에 열린 판매대금확인 없음(시더)', async () => {
    const open = L.dbQuery(
      "select v.vehicle_number n from board_requests b join vehicles v on v.id = b.vehicle_id where b.status = 'open' and b.type = 'sale_payment_confirm' and v.vehicle_number in (?, ?)", [A, Bv]);
    if (open.length) throw new Error(`열린 판매대금확인이 남아 있다(${open.map((x) => x.n).join(', ')}) — 시더가 안 돌았다`);
    uA = unpaid(A);
    L.unlockVehicles([A, Bv]);
  });

  await r.step('board → ERP: 한 바이어 2대 판매대금확인(금액 없음)', async () => {
    const res = B.expectStatus(B.boardSend('POST', '/api/internal/board/requests', {
      salesman_email: B.SALES, type: 'sale_payment_confirm', buyer_id: B.buyerId(BUYER),
      vehicle_ids: [B.vehicleId(A), B.vehicleId(Bv)], note: `${B.NOTE_MARK} 바이어가 어제 USD 10,300 송금했다고 합니다`,
    }), 201, '판매대금확인 요청');
    for (const pl of [A, Bv]) if (!(res.created || []).includes(pl)) throw new Error(`created 에 ${pl} 없음 (skipped=${JSON.stringify(res.skipped)})`);
    if (!res.batch_id) throw new Error('batch_id 없음');
    batch = res.batch_id;
    L.assertEq(reqOf(A).batch_id, batch, 'DB 00가1141 묶음');
    L.assertEq(reqOf(Bv).batch_id, batch, 'DB 00가1131 묶음(같은 묶음)');
    const amt = L.dbOne("select amount_krw a from board_requests where batch_id = ? limit 1", [batch]).a;
    if (amt !== null) throw new Error('판매대금확인에 금액이 저장됐다(표시 자리가 없는 유령 데이터)');
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  try {
    await r.step('1단계 차량 관리 「📩 대금확인 (N)」 → 확인할 차만', async () => {
      await L.login(p, 'finance@car-erp.test');
      await p.goto(`${L.BASE}/erp/vehicles`, { waitUntil: 'networkidle', timeout: 120000 });
      const chip = p.locator('button', { hasText: /📩 대금확인 \(\d+\)/ }).first();
      await chip.waitFor({ timeout: 30000 });
      await chip.click();
      await p.waitForTimeout(2500);
      for (const pl of [A, Bv]) await p.locator('tr', { hasText: pl }).first().waitFor({ timeout: 30000 });
    });

    await r.step('2단계 차를 열면 보라색 「대금확인」 카드 — 보낸 영업 · 요청일 · 메모', async () => {
      await p.locator('tr', { hasText: A }).first().locator('td').nth(1).click();
      await p.waitForTimeout(2500);
      const id = reqOf(A).id;
      const card = p.locator(`[wire\\:key="panel-brq-${id}"]`);
      await card.waitFor({ timeout: 20000 });
      const cls = await card.getAttribute('class');
      if (!/bg-purple-50/.test(cls || '')) throw new Error('카드가 보라색이 아님');
      const t = await card.innerText();
      for (const s of ['대금확인', B.SALES, '입금 확인', '거절', 'USD 10,300']) if (!t.includes(s)) throw new Error(`카드에 「${s}」 없음`);
    });

    await r.step('3단계 판매 탭 「입금 현황」이 있다(입금 기록 자리)', async () => {
      await p.click('button[\\@click="tab = \'sale\'"]');
      await p.waitForTimeout(800);
      await L.expectText(p, '입금 현황', '「입금 현황」');
    });

    await r.step('4단계 [입금 확인] → 회신 토스트 · 그 차만 닫힘 · 미수 그대로', async () => {
      const id = reqOf(A).id;
      const card = p.locator(`[wire\\:key="panel-brq-${id}"]`);
      await L.expectAfter(p, () => card.getByRole('button', { name: '입금 확인' }).click(), '확인했습니다 — board 에 회신됩니다.', '확인 토스트');
      await L.assertNoViolations(p);
      await card.waitFor({ state: 'detached', timeout: 15000 });
      L.assertEq(reqOf(A).status, 'done', 'DB 00가1141');
      L.assertEq(reqOf(Bv).status, 'open', 'DB 00가1131 은 남음');
      L.assertEq(unpaid(A), uA, 'DB 00가1141 미수 캐시(입금 확인은 금액을 안 바꾼다)');
    });
    await L.closePanel(p);
  } finally {
    await c.close();
  }
  L.unlockVehicles([A, Bv]);
  return r.result();
};
