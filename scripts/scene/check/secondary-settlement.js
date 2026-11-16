// 씬 점검 — secondary-settlement (resources/guides/secondary-settlement). 촬영 원본 = cap/ss_bulk.js · ss_scene.js · mk_ss2.php
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = finance@. 예시 = 00가1121 · 00가1122(KRW 판매 13,000,000 · 프리랜서 50% · 지급완료 · 2차 대기 · 귀속월 2026-11).
// 흐름: 「비용 대기」 → (참고) 1121 매입 탭 「⏳ 2차 정산 대기」 띠 → [명세서 기입] 붙여넣기 탁송 150,000/120,000 → [일괄 기입]
//      → 「2차 가능」 · 이월 −67,500/−54,000 → [이 달 2차 일괄 마감 (2)] → [2건 마감] → 「최종 마무리」.
// 🚨 [N건 마감] 은 그 달 「2차 가능」 정산을 **전부** 닫는다(되돌릴 수 없다). 미리보기 대상이 1121·1122 두 건이 아니면 누르지 않는다.
// ⚠️ ledger-readjust 보다 먼저(그 씬은 1121 이 마감돼 있어야 한다).
// ⚠️ 이 씬이 만드는 이월(−121,500, 이프리)은 그 사람의 다음 월정산에 들어간다 — 2단계 monthly-payout 보다 뒤에 둘 것.
const L = require('./lib');

const PLATES = ['00가1121', '00가1122'];
const MONTH = '2026-11';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('secondary-settlement');
  const st = () => L.dbQuery(
    "select v.vehicle_number pl, s.id, s.settlement_status ss, s.secondary_status sec, s.secondary_ready_at ready, s.carryover_out_krw co, v.cost_towing ct from settlements s join vehicles v on v.id = s.vehicle_id where v.vehicle_number in (?, ?) and s.deleted_at is null order by v.vehicle_number", PLATES);
  let ids = [];

  await r.step('사전: 두 정산 = 지급완료 · 2차 대기 · 비용 대기 · 탁송비 0(시더) · 그 달 다른 「2차 가능」 0건', async () => {
    const rows = st();
    if (rows.length !== 2) throw new Error('00가1121·00가1122 정산 2건이 없다');
    for (const x of rows) {
      L.assertEq(x.ss, 'paid', `${x.pl} 정산 상태`);
      L.assertEq(x.sec, 'pending', `${x.pl} 2차 상태`);
      if (x.ready) throw new Error(`${x.pl} 이 이미 「2차 가능」 — 시더가 안 돌았다`);
      L.assertEq(Number(x.ct), 0, `${x.pl} 탁송비`);
    }
    ids = rows.map((x) => x.id);
    const other = L.dbOne(`select count(*) c from settlements where deleted_at is null and secondary_status = 'pending' and secondary_ready_at is not null and attributed_month = ? and id not in (${ids.map(() => '?').join(',')})`, [`${MONTH}-01`, ...ids]);
    if (Number(other.c) > 0) throw new Error(`중단: ${MONTH} 에 예시 밖 「2차 가능」 정산이 ${other.c}건 — 일괄 마감이 그것까지 닫는다`);
    L.unlockVehicles(PLATES);
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const list = async () => {
    await p.goto(`${L.BASE}/erp/settlements?monthFilter=${MONTH}`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.locator('input[wire\\:model="search"]').first().fill('00가112');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(1800);
  };
  const rowText = async (pl) => (await p.locator('tr', { hasText: pl }).first().innerText()).replace(/\s+/g, ' ');
  try {
    await r.step('1단계 정산 처리 — 두 줄 「비용 대기」', async () => {
      await L.login(p, 'finance@car-erp.test');
      await list();
      for (const pl of PLATES) if (!(await rowText(pl)).includes('비용 대기')) throw new Error(`${pl} 줄에 「비용 대기」 없음`);
    });

    await r.step('참고 — 1121 매입 탭 「⏳ 2차 정산 대기 — 실측 정정 가능」 띠', async () => {
      await L.openVehicle(p, '00가1121', 'purchase');
      await L.expectText(p, '⏳ 2차 정산 대기 — 실측 정정 가능', '2차 대기 띠');
      await L.closePanel(p);
      L.unlockVehicles(['00가1121']);
    });

    await r.step('2·3단계 [명세서 기입] → 붙여넣기 → [미리보기] 매칭 2대 → [일괄 기입]', async () => {
      await p.goto(`${L.BASE}/erp/vehicles`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.getByRole('button', { name: '명세서 기입' }).first().click();
      await L.expectText(p, '비용 명세서 일괄 기입', '창 제목');
      for (const t of ['대상 비용', '거래처']) await L.expectText(p, t, `「${t}」`);
      // 기본 = 탁송비 · 위카(붙여넣기 가능). 촬영 스크립트도 고르지 않았다 — 기본값이 바뀌면 여기서 매칭이 안 된다.
      await p.locator('textarea[wire\\:model="costImportRaw"]').fill('00가1121 탁송 150,000\n00가1122 탁송 120,000');
      await p.locator('button[wire\\:click="parseCostImport"]').click();
      await L.expectText(p, '매칭 2대', '「매칭 2대」');
      for (const t of ['현재', '새 금액']) await L.expectText(p, t, `미리보기 「${t}」`);
      // 새 금액은 글자가 아니라 고칠 수 있는 입력칸 값(콤마 없음)으로 나온다
      const vals = await p.locator('div[role="dialog"], .fixed').locator('input[type="text"], input:not([type])').evaluateAll((els) => els.map((e) => e.value.replace(/[^0-9]/g, '')));
      for (const want of ['150000', '120000']) if (!vals.includes(want)) throw new Error(`미리보기 새 금액 칸에 ${want} 없음(${vals.filter(Boolean).slice(0, 6).join(',')})`);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyCostImport"]').click(), '2대 비용 기입 완료', '[일괄 기입] 결과');
      await L.assertNoViolations(p);
      const rows = st();
      L.assertEq(Number(rows[0].ct), 150000, 'DB 1121 탁송비');
      L.assertEq(Number(rows[1].ct), 120000, 'DB 1122 탁송비');
      for (const x of rows) if (!x.ready) throw new Error(`DB ${x.pl} 「2차 가능」(secondary_ready_at) 안 찍힘`);
    });

    await r.step('4단계 정산 처리 — 「2차 가능」 · 이월 −67,500 / −54,000 · 드로어 「2차 차액」', async () => {
      await list();
      const t1 = await rowText('00가1121');
      const t2 = await rowText('00가1122');
      if (!t1.includes('2차 가능') || !t2.includes('2차 가능')) throw new Error('「2차 가능」 없음');
      if (!/이월[^\d]{0,6}67,500/.test(t1)) throw new Error('1121 줄에 「이월 −67,500」 없음');
      if (!/이월[^\d]{0,6}54,000/.test(t2)) throw new Error('1122 줄에 「이월 −54,000」 없음');
      await p.locator('tr', { hasText: '00가1121' }).first().locator('td').nth(1).click();
      await L.expectText(p, '2차 차액', '드로어 「2차 차액」');
      await p.locator('button[wire\\:click="close"]').first().click().catch(() => {});
      await p.waitForTimeout(800);
    });

    await r.step('5단계 [이 달 2차 일괄 마감 (2)] → [2건 마감] → 「최종 마무리」', async () => {
      await L.expectText(p, '이 달 2차 일괄 마감 (2)', '버튼 「이 달 2차 일괄 마감 (2)」');
      await p.locator('button[wire\\:click="openCloseSecondaryModal"]').click();
      await L.expectText(p, '마감할 정산 2건', '「마감할 정산 2건」');
      // 🚨 범위 가드 — 창의 마감 대상이 정확히 예시 두 대인지 (화면 문구 + 위 사전 DB 검사)
      for (const pl of PLATES) await L.expectText(p, pl, `마감 목록에 ${pl}`);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="closeSecondaryMonth"]').click(), '2차 정산 2건 마감됨 (건너뜀 0건).', '[2건 마감] 결과');
      await L.assertNoViolations(p);
      const rows = st();
      for (const x of rows) L.assertEq(x.sec, 'closed', `DB ${x.pl} 2차 상태`);
      L.assertEq(Number(rows[0].co), -67500, 'DB 1121 이월');
      L.assertEq(Number(rows[1].co), -54000, 'DB 1122 이월');
      await list();
      for (const pl of PLATES) if (!(await rowText(pl)).includes('최종 마무리')) throw new Error(`${pl} 줄에 「최종 마무리」 없음`);
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles(PLATES);
  return r.result();
};
