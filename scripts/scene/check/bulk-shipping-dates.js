// 씬 점검 — bulk-shipping-dates (resources/guides/bulk-shipping-dates). 촬영 원본 = cap/bd_scene.js
// 계정 = clear@. 예시 차량 = 00가1131 · 00가1141. 시더가 두 차의 선적일·ETA·선박명·신고번호·컨테이너번호를 비워 둔다
//   → 그래야 「적용 2대」·「기입 2대」가 나온다(안 비우면 「변경없음」이거나 선박명·번호 충돌 확인 화면으로 빠진다).
// ③ 서류 일괄 업로드는 1단계에서 안 한다(파일 업로드 + 진행상태가 거래완료까지 움직임 — 되돌리기 비용이 큼).
const L = require('./lib');

const PLATES = ['00가1131', '00가1141'];

module.exports.run = async function run({ browser }) {
  const r = L.recorder('bulk-shipping-dates');
  const ship = L.addDays(7);
  const eta = L.addDays(37);
  const VSL = 'MV GUIDE STAR V.01';
  const DECL = '12345-67-8901234';
  const CONT = 'TEMU1234567';
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const rows = () => L.dbQuery(
    'select vehicle_number, shipping_date, eta_date, vessel_name, export_declaration_number, container_number, progress_status_cache from vehicles where vehicle_number in (?, ?) and deleted_at is null order by vehicle_number', PLATES);
  const pick = async () => {
    await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent('00가11')}`, { waitUntil: 'networkidle', timeout: 120000 });
    await p.waitForTimeout(1200);
    for (const pl of PLATES) {
      const cb = p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]');
      if (!(await cb.isChecked())) await cb.check();
      await p.waitForTimeout(600);
    }
  };

  try {
    let before;
    let auditBefore = 0;
    const auditCount = () => Number(L.dbOne("select count(*) c from audit_logs where action = 'bulk_shipping_date_applied'").c);
    await r.step('사전: 두 차의 선적 칸이 비어 있다(시더)', async () => {
      before = rows();
      auditBefore = auditCount();
      if (before.length !== 2) throw new Error('예시 차량 00가1131·00가1141 없음');
      for (const v of before) {
        if (v.shipping_date || v.eta_date || v.vessel_name || v.export_declaration_number || v.container_number) {
          throw new Error(`${v.vehicle_number} 선적 칸이 비어 있지 않다 — GuideSceneFixtureSeeder 가 안 돌았다`);
        }
      }
      L.unlockVehicles(PLATES);
    });

    await r.step('1단계 차들을 체크 → 막대의 [선적일·ETA·VSL·반입지 일괄]', async () => {
      await L.login(p, 'clear@car-erp.test');
      await pick();
      await L.expectText(p, '선적일·ETA·VSL·반입지 일괄', '막대 버튼');
      await L.expectText(p, '번호 일괄 기입', '막대 버튼');
      await p.locator('button[wire\\:click="openBulkDate(\'selected\')"]').click();
      await L.expectText(p, '선택한 2대에 적용됩니다', '창 위 대상 문구');
      // 「선택한 2대에 적용됩니다」에도 「2대에 적용」이 들어 있어 본문 검사는 헛통과한다 — 버튼 글자만 본다.
      const btn = (await p.locator('button[wire\\:click="applyBulkDate"]').last().innerText()).trim();
      if (!btn.includes('2대에 적용')) throw new Error(`[N대에 적용] 버튼 글자가 다름: ${btn}`);
    });

    await r.step('1단계 [2대에 적용] → 「적용 2대 · 변경없음 0대 · 제외 0대」', async () => {
      await p.locator('input[wire\\:model="bulkShipDate"]').fill(ship);
      await p.locator('input[wire\\:model="bulkEtaDate"]').fill(eta);
      await p.locator('input[wire\\:model="bulkVessel"]').fill(VSL);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(500);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyBulkDate"]').last().click(), '적용 2대 · 변경없음 0대 · 제외 0대', '적용 결과');
      await L.assertNoViolations(p);
      for (const v of rows()) {
        L.assertEq(String(v.shipping_date).slice(0, 10), ship, `DB ${v.vehicle_number} 선적일`);
        L.assertEq(String(v.eta_date).slice(0, 10), eta, `DB ${v.vehicle_number} ETA`);
        L.assertEq(v.vessel_name, VSL, `DB ${v.vehicle_number} 선박명`);
      }
      // 가이드: 「진행상태는 선적일만으로는 바뀌지 않고 … 예시 00가1131 은 판매중 그대로」
      const v1131b = before.find((v) => v.vehicle_number === '00가1131');
      const v1131 = rows().find((v) => v.vehicle_number === '00가1131');
      L.assertEq(v1131.progress_status_cache, v1131b.progress_status_cache, 'DB 00가1131 진행상태(선적일만으로는 안 바뀜)');
    });

    await r.step('2단계 [번호 일괄 기입] → 세 칸 중 필요한 것만 → [기입]', async () => {
      await pick();
      await p.locator('button[wire\\:click="openBulkNumber"]').click();
      await L.expectText(p, '수출신고번호·컨테이너번호·B/L번호 일괄 기입', '번호 일괄 창 제목');
      await L.expectText(p, '선택한 2대에 기입합니다', '번호 일괄 대상 문구');
      await p.locator('input[wire\\:model="bulkNumDecl"]').fill(DECL);
      await p.locator('input[wire\\:model="bulkNumContainer"]').fill(CONT);
      await p.locator('input[wire\\:model="bulkNumReason"]').fill('가이드 예시 선적분');
      await p.waitForTimeout(400);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="applyBulkNumber"]').click(), '기입 2대 · 변화 없음 0대 · 제외 0대', '기입 결과');
      await L.assertNoViolations(p);
      for (const v of rows()) {
        L.assertEq(v.export_declaration_number, DECL, `DB ${v.vehicle_number} 수출신고번호`);
        L.assertEq(v.container_number, CONT, `DB ${v.vehicle_number} 컨테이너번호`);
      }
    });

    await r.step('알아 둘 것 — 바뀐 값은 감사 로그에', async () => {
      // 일괄 출처 기록(SKILLS §14) — 날짜 일괄·번호 일괄이 같은 서비스(BulkVehicleShippingDateService)라 같은 액션으로 남는다.
      //   (액션 이름·컬럼은 2026-11-16 로컬 audit_logs 실측으로 확인. 행 수는 「이번 실행 전보다 2 이상」으로만 본다.)
      const now = auditCount();
      if (now < auditBefore + 2) throw new Error(`감사 로그(bulk_shipping_date_applied) 증가 부족: ${auditBefore} → ${now}`);
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
