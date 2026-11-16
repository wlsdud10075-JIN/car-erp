// 씬 점검 — inventory-location ④ 「board 에서 바뀐 보관위치」(4단계만). 촬영 원본 = cap/inv_board.js
// ⚠️ inventory-location 의 ①~③(00가1161 · 출고일)은 다른 단계 몫 — 이 파일은 4단계만 따로 둔다. 합칠 땐 이 단계들을 그 파일 끝에 붙인다.
// 예시 차 = 20가2001 (영업 sales@ 의 재고 차 · 매입완료 · 미판매). 00가1161 은 영업이 sales@ 가 아니라 board 쓰기 대상이 아니다(403).
// 계정 = clear@. board 요청 = POST /api/internal/board/vehicles/{id}/stock-location (덮어쓰기 · 본인 차만).
// 시더가 20가2001 의 보관위치·비고를 「비움」으로 되돌려 둔다(이 씬의 표식 비고일 때만).
const L = require('./lib');
const B = require('./board');

const PLATE = '20가2001';
const LOC = '화물';
const NOTE = '[예시] 탁송 배차 — 평택 화물터미널';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('inventory-location-board');
  const row = () => L.dbOne('select id, stock_location l, stock_location_note n, progress_status_cache p, warehouse_out_date w from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
  const audits = (id) => Number(L.dbOne("select count(*) n from audit_logs where auditable_id = ? and auditable_type like '%Vehicle' and column_name in ('stock_location','stock_location_note') and user_id is not null", [id]).n);
  let v = null;
  let a0 = 0;

  await r.step('사전: 20가2001 재고 · 보관위치 비어 있음(시더)', async () => {
    v = row();
    if (!v) { r.skip('예시 차 20가2001 없음'); return; }
    if (v.w) throw new Error('출고일이 찍혀 재고가 아니다');
    if (v.l || v.n) throw new Error('보관위치가 남아 있다 — 시더가 안 돌았다');
    a0 = audits(v.id);
  });

  await r.step('board 탁송 배차 → 위치 「화물」 · 비고 저장(누가 바꿨는지 기록)', async () => {
    const res = B.expectStatus(B.boardSend('POST', `/api/internal/board/vehicles/${v.id}/stock-location`, { salesman_email: B.SALES, stock_location: LOC, stock_location_note: NOTE }), 200, '보관위치 쓰기');
    const up = (res.updated || []).slice().sort();
    if (up.join(',') !== 'stock_location,stock_location_note') throw new Error(`updated 기대 두 칸 / 실제 ${JSON.stringify(res.updated)}`);
    const now = row();
    L.assertEq(now.l, LOC, 'DB 보관위치');
    L.assertEq(now.n, NOTE, 'DB 비고');
    L.assertEq(audits(v.id), a0 + 2, 'DB 감사로그(행위자 있음) +2');
    // 목록 밖 값은 422 — 회사마다 위치 이름이 다르다(가이드 「알아 둘 것」)
    B.expectStatus(B.boardSend('POST', `/api/internal/board/vehicles/${v.id}/stock-location`, { salesman_email: B.SALES, stock_location: '없는위치' }), 422, '목록 밖 위치');
    L.assertEq(row().l, LOC, 'DB 보관위치(422 뒤 그대로)');
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  try {
    await r.step('4단계 재고관리 그 차 줄 — 「화물」 버튼 진하게 · 비고 그대로([출고일 적용] 안 눌러도)', async () => {
      await L.login(p, 'clear@car-erp.test');
      await p.goto(`${L.BASE}/erp/inventory`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.locator('input[wire\\:model="search"]').first().fill(PLATE);
      await p.getByRole('button', { name: '조회', exact: true }).click();
      const tr = p.locator('tr', { hasText: PLATE }).first();
      await tr.waitFor({ timeout: 30000 });
      for (const loc of ['홈플', '화물', '야드']) {
        const cls = (await tr.locator('button', { hasText: loc }).first().getAttribute('class')) || '';
        const on = /bg-primary-light/.test(cls);
        if (on !== (loc === LOC)) throw new Error(`「${loc}」 버튼 선택 표시가 ${on ? '켜짐' : '꺼짐'}`);
      }
      L.assertEq(await tr.locator('input[wire\\:model^="stockLocationNote"]').first().inputValue(), NOTE, '비고 칸');
      await L.assertNoViolations(p);
    });
  } finally {
    await c.close();
  }
  return r.result();
};
