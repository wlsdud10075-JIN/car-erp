// 씬 점검 — buyer-undecided (resources/guides/buyer-undecided). 촬영 원본 = cap/bu_scene.js · mk_undecided.php
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = guide.manager@. 차 = 00가1161(USD 판매가 9,000 + 운임비 1,000 · 바이어 GUIDE SAMPLE TRADING · 매입 완납).
// ① 바이어 × → 「바이어 미정 (투기 매입)」 체크 → 저장 막힘(이유) ② 판매일·판매가·운임비 비우고 저장 → 「바이어미정」 · 매입 단계.
// ⚠️ inventory-location 보다 먼저(그 씬은 이 결과 = 일반재고 1161 을 쓴다).
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1161';
const BUYER = 'GUIDE SAMPLE TRADING';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('buyer-undecided');
  const v = () => L.dbOne('select v.id, v.buyer_id, b.name bname, v.buyer_undecided, v.sale_price, v.transport_fee, v.sale_date, v.progress_status_cache, v.sale_unpaid_amount_krw_cache u from vehicles v left join buyers b on b.id = v.buyer_id where v.vehicle_number = ? and v.deleted_at is null', [PLATE]);

  await r.step('사전: 1161 = 바이어 있음 · 판매가 9,000 · 운임비 1,000(시더)', async () => {
    const x = v();
    if (!x) throw new Error('예시 차량 00가1161 없음');
    L.assertEq(x.bname, BUYER, '바이어');
    L.assertEq(Number(x.sale_price), 9000, '판매가');
    L.assertEq(Number(x.transport_fee), 1000, '운임비');
    L.assertEq(Number(x.buyer_undecided), 0, '바이어 미정 플래그');
    L.unlockVehicles([PLATE]);
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 바이어 × → 「바이어 미정 (투기 매입)」 체크 → 저장 막힘', async () => {
      await L.login(p, 'guide.manager@car-erp.test');
      await L.openVehicle(p, PLATE, 'sale');
      // 바이어 콤보 칸의 × — 값이 바이어명인 보이는 입력칸 옆 버튼(촬영 스크립트와 같은 방식)
      const cleared = await p.evaluate((name) => {
        const inputs = [...document.querySelectorAll('input[type=text]')].filter((i) => i.offsetParent && i.value === name);
        for (const i of inputs) { const btn = i.parentElement.querySelector('button'); if (btn) { btn.click(); return true; } }
        return false;
      }, BUYER);
      if (!cleared) throw new Error('바이어 칸의 × 를 못 찾았다');
      await L.expectText(p, '바이어 미정 (투기 매입)', '「바이어 미정 (투기 매입)」 체크 나타남');
      await p.locator('input[wire\\:model\\.live="buyer_undecided"]').check();
      await p.waitForTimeout(600);
      await p.getByRole('button', { name: '수정 저장' }).first().click();
      await H.confirmUntilSettled(p, { stepName: '바이어 미정 저장' });   // 확인 창이 늦게 떠도 기다린다(11-16)
      await L.expectText(p, '판매가를 입력하면 바이어도 필수입니다', '막힌 이유(sale_buyer_required)');
      await L.assertNoViolations(p);
      const x = v();
      if (!x.buyer_id || Number(x.sale_price) !== 9000) throw new Error('DB: 막혀야 할 저장이 들어갔다');
    });

    await r.step('2단계 판매일 · 판매가 · 운임비 비우고 저장 → 「바이어미정」 · 매입 단계', async () => {
      await p.locator('input[wire\\:model="sale_price_str"]').fill('');
      await p.locator('input[wire\\:model="sale_date"]').fill('');
      await p.locator('input[wire\\:model="transport_fee_str"]').fill('');
      await p.keyboard.press('Tab');
      await p.waitForTimeout(600);
      const ck = p.locator('input[wire\\:model\\.live="buyer_undecided"]');
      if (await ck.count() && !(await ck.isChecked())) await ck.check();
      await H.saveVehicle(p, '판매 무르기 저장');
      const x = v();
      if (x.buyer_id) throw new Error('DB: 바이어가 남아 있다');
      L.assertEq(Number(x.buyer_undecided), 1, 'DB 바이어 미정');
      L.assertEq(Number(x.sale_price), 0, 'DB 판매가');
      L.assertEq(Number(x.transport_fee), 0, 'DB 운임비(안 비우면 바이어 없는 미수가 남는다)');
      if (x.sale_date) throw new Error('DB: 판매일이 남아 있다');
      if (!['매입중', '매입완료', '말소완료'].includes(x.progress_status_cache)) throw new Error(`DB 진행상태가 매입 단계가 아니다(${x.progress_status_cache})`);
      if (Number(x.u || 0) > 0) throw new Error('DB: 미수가 남아 있다');
      await L.closePanel(p);
      L.unlockVehicles([PLATE]);
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const row = await p.locator('tr', { hasText: PLATE }).first().innerText();
      if (!row.includes('바이어미정')) throw new Error('목록 줄에 「바이어미정」 없음');
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles([PLATE]);
  return r.result();
};
