// 씬 점검 — shipping-requests (resources/guides/shipping-requests). 촬영 원본 = cap/sr_scene.js · sr_explore.js · sr_reject.js
// 계정 = clear@(수출통관). 예시 묶음 = 00가1141 · 00가1131 CONTAINER (GUIDE SAMPLE TRADING, 영업 sales@) + 착수 불가 예시 = 11가1001 RORO 묶음.
//
// 🚨 board 의 선적 묶음 sync(/shipping-requests/sync)는 **부르지 않는다** — 선언형이라 같은 영업의 다른 열린 요청
//    (99무0001~3 · 11가1001 RORO)이 자동 취소된다. 대신 시더가 예시 묶음을 「요청」 상태로 되돌려 둔다(restoreDemoShippingBundle).
//    이 씬이 board 로 보내는 건 묶음·차 단위 두 개뿐 = bl-request(그 묶음만) · change-request(그 차만).
// ⚠️ 순서 의존 — 「진행중으로」는 묶음 입금률 게이트를 탄다(00가1141 은 진입 우회가 있어 집계에서 빠지고 00가1131 만 남는다).
//    시더 직후 00가1131 은 미수 약 45%(로컬 임계 60% 입금 = 미수 40% 이하)라 막히고, buyer-account 씬이 2,000 USD 를 확정한 뒤엔 25% 로 통과한다.
//    ⇒ 러너에서 buyer-account **뒤**에 둔다. 단독(--only)으로 돌리면 2단계부터 「건너뜀」.
// ⚠️ bulk-shipping-dates 가 두 차의 신고번호·컨테이너번호 「비어 있음」을 사전 조건으로 본다 — 이 씬은 반드시 그 **뒤**.
const L = require('./lib');
const B = require('./board');

const A = '00가1141';
const Bv = '00가1131';
const RORO = '11가1001';
const DECL = '12345-26-1234567';
const CONT = 'ABCU1234567';
const CHANGE_NOTE = '00가1131 은 다음 배로 빼 주세요';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('shipping-requests');
  const rows = () => L.dbQuery(
    "select s.id, s.batch_id, v.vehicle_number n, s.status, s.bl_status, s.bl_type, s.change_requested_at from shipping_requests s join vehicles v on v.id = s.vehicle_id where v.vehicle_number in (?, ?) and s.shipping_method = 'CONTAINER' and s.requested_by_email = ? and s.status in ('requested','in_progress') order by s.id", [A, Bv, B.SALES]);
  let batch = null;

  await r.step('사전: 예시 묶음 2대 「요청」 · B/L요청 없음 · 변경요청 없음(시더) / RORO 묶음 「요청」', async () => {
    const rs = rows();
    if (rs.length !== 2) { r.skip(`예시 CONTAINER 묶음이 열린 상태로 ${rs.length}행 — 시더 경고를 볼 것`); return; }
    if (new Set(rs.map((x) => x.batch_id)).size !== 1) throw new Error('두 차가 서로 다른 묶음');
    for (const x of rs) {
      L.assertEq(x.status, 'requested', `${x.n} 상태`);
      L.assertEq(x.bl_status, 'none', `${x.n} B/L 상태`);
      if (x.change_requested_at) throw new Error(`${x.n} 변경요청이 남아 있다`);
    }
    batch = rs[0].batch_id;
    const ro = L.dbOne("select s.status from shipping_requests s join vehicles v on v.id = s.vehicle_id where v.vehicle_number = ? and s.shipping_method = 'RORO' and s.requested_by_email = ? order by s.id desc limit 1", [RORO, B.SALES]);
    if (!ro || ro.status !== 'requested') throw new Error(`착수 불가 예시(11가1001 RORO)가 「요청」이 아님(${ro ? ro.status : '없음'}) — 누가 sync 를 불렀나`);
    const d = L.dbQuery('select export_declaration_number e, container_number c from vehicles where vehicle_number in (?, ?)', [A, Bv]);
    if (d.some((x) => x.e === DECL && x.c === CONT)) throw new Error('신고번호·컨테이너가 이미 이 씬 값 — 시더가 안 돌았다');
  });

  // 게이트 사전 계산 — entryAggregate 와 같은 식(진입 우회 차 · 선적대기 항로 차 제외, 미수 원화 캐시 합 / 총판매가×환율 합)
  await r.step('사전: 묶음 입금률이 착수 기준을 넘는다(buyer-account 뒤에 돌 것)', async () => {
    const vs = L.dbQuery(
      `select v.vehicle_number n, v.sale_unpaid_amount_krw_cache u,
              (v.sale_price + v.transport_fee + v.sale_other_costs + v.commission + v.auto_loading - v.tax_dc) * v.exchange_rate d,
              b.lock_shipping_entry_pct bp,
              (select count(*) from unpaid_export_overrides o where o.vehicle_id = v.id and o.stage in ('clearance','shipping')) ov
         from vehicles v left join buyers b on b.id = v.buyer_id where v.vehicle_number in (?, ?) and v.sale_price > 0`, [A, Bv]);
    const set = (L.dbOne("select value from settings where `key` = 'company_template_set'") || { value: 'system' }).value;
    const g = L.dbOne('select value from settings where `key` = ?', [`lock_threshold_shipping_entry_${set}`]);
    const on = L.dbOne('select value from settings where `key` = ?', [`lock_shipping_entry_${set}`]);
    if (on && String(on.value) === '0') return '착수 락 꺼짐 — 게이트 없음';
    const active = vs.filter((x) => Number(x.ov) === 0);
    if (!active.length) return '진입 우회로 집계 대상 없음';
    const ratio = active.reduce((s, x) => s + Number(x.u || 0), 0) / active.reduce((s, x) => s + Number(x.d || 0), 0);
    // 화면 = cutoff 의 min(가장 엄격) = 필요 입금률의 max
    const pct = Math.max(...active.map((x) => (x.bp !== null ? Number(x.bp) : Number(g ? g.value : 50))));
    const cutoff = (100 - pct) / 100;
    if (ratio > cutoff) {
      const msg = `묶음 미수율 ${(ratio * 100).toFixed(1)}% > 기준 ${(cutoff * 100).toFixed(0)}% — 예시 데이터 상태(§7-1 스크립트 문제). buyer-account 씬 뒤에 돌아야 통과`;
      if (process.argv.some((a) => a.startsWith('--only'))) { r.skip(msg); return; }
      throw new Error(msg);
    }
    return `미수율 ${(ratio * 100).toFixed(1)}% · 기준 ${(cutoff * 100).toFixed(0)}%`;
  });

  const c = await L.newContext(browser);
  const p = await L.newPage(c);
  const card = () => p.locator('div.card', { hasText: A }).filter({ hasText: 'CONTAINER' }).first();
  const go = async () => { await p.goto(`${L.BASE}/erp/shipping-requests`, { waitUntil: 'networkidle', timeout: 120000 }); await p.waitForTimeout(1500); };
  try {
    await r.step('1단계 「선적요청」 — 탭 · 검색칸 · 묶음 카드(상태·방식·바이어·대수·미수·포워딩·운임·묶음 서류)', async () => {
      await L.login(p, 'clear@car-erp.test');
      await go();
      for (const s of ['할 일', '전체', '요청 영업', '묶음 서류']) await L.expectText(p, s, `「${s}」`);
      const ph = await p.locator('input[placeholder="바이어·컨사이니·차량번호"]').count();
      if (!ph) throw new Error('검색칸(바이어·컨사이니·차량번호) 없음');
      const t = await card().innerText();
      for (const s of ['요청', 'CONTAINER', 'GUIDE SAMPLE TRADING', '2대', '미수', '포워딩사', '운임비', '2대 분할 기록', 'Invoice & Packing', '계약서', '차량관리에서 2대 보기']) {
        if (!t.includes(s)) throw new Error(`예시 묶음 카드에 「${s}」 없음`);
      }
      // 착수 불가 예시 — 11가1001 RORO
      const ro = p.locator('div.card', { hasText: RORO }).filter({ hasText: 'RORO' }).first();
      if (!(await ro.innerText()).includes('착수 불가')) throw new Error('11가1001 RORO 묶음에 「🔒 착수 불가」 없음');
    });

    await r.step('2단계 [⋯] → 「진행중으로」 → 상태 「진행중」', async () => {
      await card().locator('button[title="더보기"]').click();
      await L.expectText(p, '진행중으로', '메뉴 「진행중으로」');
      await L.expectText(p, '완료 처리', '메뉴 「완료 처리」');
      await L.expectAfter(p, () => card().getByRole('button', { name: '진행중으로' }).click(), '선적요청 상태를 변경했습니다.', '상태 토스트');
      await L.assertNoViolations(p);
      for (const x of rows()) L.assertEq(x.status, 'in_progress', `DB ${x.n} 상태`);
      if (!(await card().innerText()).includes('진행중')) throw new Error('카드 상태가 「진행중」으로 안 바뀜');
    });

    await r.step('3단계 [수출신고번호·컨테이너 기입] → 두 칸 → [일괄 기입]', async () => {
      await card().getByRole('button', { name: '수출신고번호·컨테이너 기입' }).click();
      await L.expectText(p, '묶음 2대 일괄 기입', '기입 폼 제목');
      await p.locator('input[wire\\:model="declNumber"]').fill(DECL);
      await p.locator('input[wire\\:model="declContainer"]').fill(CONT);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyDeclNumber"]').click(), '묶음 2대에 일괄 기입했습니다.', '기입 토스트');
      const d = L.dbQuery('select vehicle_number n, export_declaration_number e, container_number c, progress_status_cache s from vehicles where vehicle_number in (?, ?)', [A, Bv]);
      for (const x of d) { L.assertEq(x.e, DECL, `DB ${x.n} 신고번호`); L.assertEq(x.c, CONT, `DB ${x.n} 컨테이너`); }
    });

    await r.step('4단계 board B/L 요청 → 「B/L요청」 칩 · 「오리지널」 / 수출통관엔 [B/L번호·선박명 기입] 없음', async () => {
      B.expectStatus(B.boardSend('POST', `/api/internal/board/bundles/${batch}/bl-request`, { salesman_email: B.SALES, bl_type: 'original' }), 200, 'B/L 요청');
      await go();
      const t = await card().innerText();
      if (!t.includes('B/L요청')) throw new Error('카드에 「B/L요청」 칩 없음');
      if (!t.includes('오리지널')) throw new Error('카드에 「오리지널」 없음');
      L.assertEq(await card().getByRole('button', { name: 'B/L번호·선박명 기입' }).count(), 0, '수출통관 계정의 [B/L번호·선박명 기입] 버튼 수');
      for (const x of rows()) L.assertEq(x.bl_status, 'requested', `DB ${x.n} B/L 상태`);
    });

    await r.step('5단계 board 변경요청(00가1131) → 노란 「변경요청」 줄 → [반려] → 줄 사라짐 · 진행 그대로', async () => {
      B.expectStatus(B.boardSend('POST', '/api/internal/board/shipping-requests/change-request', { salesman_email: B.SALES, vehicle_id: B.vehicleId(Bv), note: CHANGE_NOTE }), 200, '변경요청');
      await go();
      const t = await card().innerText();
      for (const s of ['변경요청', Bv, CHANGE_NOTE, '수락(해제)', '반려']) if (!t.includes(s)) throw new Error(`변경요청 줄에 「${s}」 없음`);
      // 🚫 [수락(해제)]는 누르지 않는다 — 그 차가 묶음에서 빠져(취소) 다음 주 시더가 되살려야 한다
      await L.expectAfter(p, () => card().getByRole('button', { name: '반려', exact: true }).click(), '변경요청을 반려했습니다.', '반려 토스트');
      await L.assertNoViolations(p);
      const x = rows().find((y) => y.n === Bv);
      if (!x) throw new Error('00가1131 이 묶음에서 빠졌다(수락으로 처리됨?)');
      L.assertEq(x.status, 'in_progress', 'DB 00가1131 상태');
      if (x.change_requested_at) throw new Error('DB 변경요청 표시가 남아 있다');
      if ((await card().innerText()).includes('수락(해제)')) throw new Error('노란 줄이 안 사라짐');
    });
  } finally {
    await c.close();
  }
  return r.result();
};
