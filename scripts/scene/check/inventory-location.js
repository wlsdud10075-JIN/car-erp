// 씬 점검 — inventory-location (resources/guides/inventory-location) ERP 단계만. 촬영 원본 = cap/inv_scene.js
// ⚠️ 초안 — 한 번도 실행하지 않았다. 4단계(board 탁송 배차가 바꾼 위치)는 board 씬이라 뺐다(inv_board.js).
// 계정 = clear@. 차 = 00가1161 — buyer-undecided 결과(바이어 미정 · 매입 완납 = 일반재고)를 그대로 쓴다.
// 흐름: 탭 5개 → [야드] + 비고 「A-3 구역」 → [출고일 적용] → 출고일 8자리 → [출고일 적용] → 「출고완료」 탭.
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1161';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('inventory-location');
  const { iso, ymd } = L.today();
  const v = () => L.dbOne('select id, stock_location, stock_location_note, warehouse_out_date, buyer_undecided, sale_price from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);

  await r.step('사전: 1161 = 일반재고(바이어 미정) · 위치·출고일 없음', async () => {
    const x = v();
    if (!x) throw new Error('예시 차량 00가1161 없음');
    if (!Number(x.buyer_undecided) || Number(x.sale_price) > 0) throw new Error('1161 이 바이어 미정이 아니다 — buyer-undecided 씬이 먼저 돌아야 한다');
    if (x.stock_location || x.stock_location_note || x.warehouse_out_date) throw new Error('보관위치·출고일이 남아 있다 — 시더가 안 돌았다');
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const go = async (cat) => {
    await p.goto(`${L.BASE}/erp/inventory${cat ? `?category=${cat}` : ''}`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
    await p.getByRole('button', { name: '조회', exact: true }).click();   // 재고 검색 [조회] — NICE 아님
    await p.waitForTimeout(1800);
    await H.hideAlarm(p);   // 촬영(inv_scene.js)도 숨겼다 — 줄 버튼·[출고일 적용]을 가린다
  };
  const row = () => p.locator('tr', { hasText: PLATE }).first();
  try {
    await r.step('① 탭 — 전체 · 지급대기 · 일반재고 · 선적전 재고 · 출고완료', async () => {
      await L.login(p, 'clear@car-erp.test');
      await go('');
      for (const t of ['전체', '지급대기', '일반재고', '선적전 재고', '출고완료', '출고일 적용']) await L.expectText(p, t, `「${t}」`);
      await go('general');
      if (!(await row().count())) throw new Error('「일반재고」 탭에 1161 없음');
    });

    await r.step('1·2단계 [야드] + 비고 → [출고일 적용]', async () => {
      await go('');
      await row().locator('button', { hasText: '야드' }).first().evaluate((e) => e.click());
      await p.waitForTimeout(600);
      await row().locator('input[wire\\:model^="stockLocationNote"]').fill('A-3 구역');
      await p.waitForTimeout(400);
      // 위치·비고만 바꿔도 적용 건수로 센다(applyWarehouseOut — $applied++) → 「출고일 1건 적용됨」
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyWarehouseOut"]').first().evaluate((e) => e.click()), '건 적용됨', '[출고일 적용] 토스트');
      await L.assertNoViolations(p);
      const x = v();
      L.assertEq(x.stock_location, '야드', 'DB 보관위치');
      L.assertEq(x.stock_location_note, 'A-3 구역', 'DB 비고');
      if (x.warehouse_out_date) throw new Error('DB: 출고일이 찍혔다(위치만 바꿨는데)');
      await go('');
      if (!(await row().count())) throw new Error('위치만 바꿨는데 재고에서 빠졌다');
    });

    await r.step('3단계 출고일 8자리 → [출고일 적용] → 「출고완료」 탭', async () => {
      const out = row().locator('input[wire\\:model^="warehouseOut"]').first();
      await out.click();
      await out.pressSequentially(ymd);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(500);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyWarehouseOut"]').first().evaluate((e) => e.click()), '건 적용됨', '출고일 [출고일 적용] 토스트');
      await L.assertNoViolations(p);
      L.assertEq(String(v().warehouse_out_date || '').slice(0, 10), iso, 'DB 출고일(8자리 → 날짜)');
      await go('');
      if (await row().count()) throw new Error('출고일을 찍었는데 재고(전체)에 남아 있다');
      await go('shipped_out');
      if (!(await row().count())) throw new Error('「출고완료」 탭에 1161 없음');
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
