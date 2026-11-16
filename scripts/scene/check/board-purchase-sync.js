// 씬 점검 — board-purchase-sync (resources/guides/board-purchase-sync). 촬영 원본 = cap/ps_scene.js(보기만) + 촬영 전 board_send 1회(신규 생성)
// 계정 = manage@. 예시 차 = 00가1201 (board 매입 확정으로 생긴 차 · 바이어미정 · 야드 「인천 야드 3구역」 · 송금인 EXAMPLE REMITTER).
//
// 🔑 신규 생성(201)은 점검하지 않는다 — 매번 하려면 시더가 00가1201 을 지워야 하는데(소프트 삭제가 쌓이고 차량 id 가 매주 바뀐다)
//    그 대신 가이드 「알아 둘 것」 첫 줄 = 「같은 차를 board 가 다시 보내도 새 차가 생기지 않습니다. 비어 있던 칸만 채우고」를 실제 수신 경로로 본다.
//    시더가 00가1201 의 보관위치 비고만 비워 둔다 → 재전송이 그 칸만 채우고 나머지는 already_set 으로 건너뛰어야 한다.
// 🚫 owner_name·attachments·seller_phone·payee_account 는 안 보낸다(NICE 실호출 · S3 복사 · 번호 형식 · 암호화 칸).
const L = require('./lib');
const B = require('./board');

const PLATE = '00가1201';
const NOTE = '인천 야드 3구역';
const PAYLOAD = {
  contract_version: 5,   // 받는 쪽 PurchaseSyncController::SUPPORTED_VERSIONS — 빠지면 422 Unsupported contract_version
  vehicle_number: PLATE,
  source: 'encar',
  purchase_price_krw: 12000000,
  selling_fee_krw: 300000,
  salesman_email: B.SALES,
  seller_company: '[예시] 한빛상사',
  seller_ceo: '[예시] 김대표',
  remitter_name: 'EXAMPLE REMITTER',
  buyer_undecided: true,
  stock_location: '야드',
  stock_location_note: NOTE,
};

module.exports.run = async function run({ browser }) {
  const r = L.recorder('board-purchase-sync');
  const row = () => L.dbQuery(
    'select id, progress_status_cache p, purchase_price, selling_fee, purchase_from, purchase_seller_holder, purchase_seller_bank, remitter_name, buyer_id, buyer_undecided, stock_location, stock_location_note from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
  let before = null;

  await r.step('사전: 00가1201 1대 · 매입중 · 보관위치 비고 비어 있음(시더)', async () => {
    const rows = row();
    if (rows.length === 0) { r.skip('예시 차 00가1201 없음 — board 매입 확정 예시를 먼저 만들 것(시더는 만들지 않는다)'); return; }
    if (rows.length !== 1) throw new Error(`00가1201 이 ${rows.length}대`);
    before = rows[0];
    L.assertEq(before.p, '매입중', '진행상태');
    if (before.stock_location_note) throw new Error('보관위치 비고가 남아 있다 — GuideSceneFixtureSeeder 가 안 돌았다');
    if (before.buyer_id) throw new Error('바이어가 붙어 있다(바이어미정 예시가 깨짐)');
    L.unlockVehicles([PLATE]);
  });

  await r.step('알아 둘 것: board 재전송 → 새 차 없음 · 빈 칸(비고)만 채움', async () => {
    const res = B.boardSend('POST', '/api/internal/purchase-sync', PAYLOAD);
    const body = B.expectStatus(res, 200, '재전송 응답(201 이면 새 차가 생긴 것)');
    L.assertEq(Number(body.vehicle_id), Number(before.id), '응답 vehicle_id = 기존 차');
    const filled = body.fields_filled || [];
    if (filled.length !== 1 || filled[0] !== 'stock_location_note') throw new Error(`fields_filled 기대 [stock_location_note] / 실제 ${JSON.stringify(filled)}`);
    const sk = body.fields_skipped || {};
    for (const k of ['stock_location', 'remitter_name', 'purchase_from']) L.assertEq(sk[k], 'already_set', `fields_skipped.${k}`);
    if ((body.fields_overwritten || []).length) throw new Error('fields_overwritten 이 비어 있지 않다');
    const after = row();
    L.assertEq(after.length, 1, 'DB 00가1201 대수');
    L.assertEq(after[0].stock_location_note, NOTE, 'DB 보관위치 비고(채워짐)');
    for (const k of ['purchase_price', 'selling_fee', 'purchase_from', 'remitter_name', 'stock_location', 'buyer_undecided']) {
      L.assertEq(after[0][k], before[k], `DB ${k} 그대로`);
    }
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  try {
    await r.step('1단계 차량 관리에서 차량번호로 찾으면 「매입중」 · 「바이어미정」', async () => {
      await L.login(p, 'manage@car-erp.test');
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const tr = p.locator('tr', { hasText: PLATE }).first();
      await tr.waitFor({ timeout: 30000 });
      const t = await tr.innerText();
      if (!t.includes('매입중')) throw new Error('목록 줄에 「매입중」 없음');
      if (!t.includes('바이어미정')) throw new Error('목록 줄에 「바이어미정」 없음');
    });

    await r.step('2단계 기본정보 — 송금인 이름이 들어와 있다', async () => {
      await L.openVehicle(p, PLATE, 'basic');
      await L.expectText(p, '송금인 이름', '「송금인 이름」 칸');
      L.assertEq(await p.locator('input[wire\\:model="remitter_name"]').first().inputValue(), 'EXAMPLE REMITTER', '송금인 이름 값');
      await L.assertNoViolations(p);   // 🚫 [NICE 조회]는 누르지 않는다(가이드 문구만 확인)
    });

    await r.step('3단계 매입 탭 — 매입일 · 구입처 · 매입가 · 매도비 · 매입처 계좌', async () => {
      await p.click('button[\\@click="tab = \'purchase\'"]');
      await p.waitForTimeout(800);
      const v = (sel) => p.locator(`input[wire\\:model="${sel}"], input[wire\\:model\\.blur="${sel}"]`).first().inputValue();
      L.assertEq(await v('purchase_from'), '[예시] 한빛상사/[예시] 김대표', '구입처');
      L.assertEq((await v('purchase_price_str')).replace(/,/g, ''), '12000000', '매입가');
      L.assertEq((await v('selling_fee_str')).replace(/,/g, ''), '300000', '매도비');
      L.assertEq(await v('purchase_seller_holder'), '[예시] 한빛상사', '예금주');
      L.assertEq(await v('purchase_seller_bank'), '예시은행', '은행');
      const pd = await v('purchase_date');
      if (!/^\d{4}-\d{2}-\d{2}$/.test(pd)) throw new Error(`매입일 형식 아님: ${pd}`);
    });
    await L.closePanel(p);

    await r.step('4단계 재고관리 「지급대기」에 야드 · 「인천 야드 3구역」으로 보인다(대금 미지급 → 재고 전)', async () => {
      await p.goto(`${L.BASE}/erp/inventory`, { waitUntil: 'networkidle', timeout: 120000 });
      // 지급대기 탭(가이드 표 「지급대기」) · ⚠️ karaba 세트면 구입처 칸이 없다 — 로컬 heyman 기준
      const tab = p.locator('button[wire\\:click="setCategory(\'awaiting_payment\')"]').first();
      await L.expectText(p, '지급대기', '「지급대기」 탭');
      await tab.click();
      await p.waitForTimeout(1500);
      await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
      await p.getByRole('button', { name: '조회', exact: true }).click();
      const tr = p.locator('tr', { hasText: PLATE }).first();
      await tr.waitFor({ timeout: 30000 });
      const on = tr.locator('button', { hasText: '야드' }).first();
      const cls = await on.getAttribute('class');
      if (!/bg-primary-light/.test(cls || '')) throw new Error('보관위치 「야드」 버튼이 진하게(선택) 표시되지 않음');
      L.assertEq(await tr.locator('input[wire\\:model^="stockLocationNote"]').first().inputValue(), NOTE, '비고 칸');
      await L.assertNoViolations(p);
    });
  } finally {
    await c.close();
  }
  L.unlockVehicles([PLATE]);
  return r.result();
};
