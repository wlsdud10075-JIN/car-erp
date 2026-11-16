// 씬 점검 — documents (resources/guides/documents). 촬영 원본 = cap/doc_scene.js · doc_bar.js
// 계정 = clear@ (수출통관). 예시 차량 = 00가1113(한 대) · 00가1131 · 00가1141(여러 대).
// 데이터를 바꾸지 않는다(서류 접근 로그만 쌓인다) — 되돌리기 불필요.
const L = require('./lib');

module.exports.run = async function run({ browser }) {
  const r = L.recorder('documents');
  const { ymd } = L.today();
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const veh = (plate) => L.dbOne('select id from vehicles where vehicle_number = ? and deleted_at is null', [plate]);
  const logCount = (ids, type) => Number(L.dbOne(
    `select count(*) c from document_access_logs where vehicle_id in (${ids.map(() => '?').join(',')}) and document_type = ?`, [...ids, type]).c);
  const download = async (clickFn, expectName, what) => {
    const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 60000 }), clickFn()]);
    L.assertEq(dl.suggestedFilename(), expectName, `${what} 파일명`);
    await dl.cancel().catch(() => {});
  };

  try {
    let v1113;
    await r.step('사전: 예시 차량 3대 존재', async () => {
      v1113 = veh('00가1113');
      if (!v1113 || !veh('00가1131') || !veh('00가1141')) throw new Error('예시 차량(00가1113·00가1131·00가1141) 없음');
      L.unlockVehicles(['00가1113']);
    });

    await r.step('1단계 차를 열고 「서류」 탭', async () => {
      await L.login(p, 'clear@car-erp.test');
      await L.openVehicle(p, '00가1113', 'docs');
      for (const t of ['PDF 받기', '인쇄', 'Proforma Invoice', 'Sales Contract', '통관 SET (Ver.1)', '통관 SET (Ver.2)', '컨테이너 Invoice & Packing', 'RORO Invoice & Packing']) {
        await L.expectText(p, t, `서류 탭 「${t}」`);
      }
    });

    await r.step('1단계 카드 누르면 바로 엑셀 — Proforma Invoice', async () => {
      const before = logCount([v1113.id], 'invoice');
      await download(() => p.locator('a', { hasText: 'Proforma Invoice' }).first().click(), `Invoice_00가1113_${ymd}.xlsx`, 'Proforma Invoice');
      await L.assertNoViolations(p);
      L.assertEq(logCount([v1113.id], 'invoice'), before + 1, 'DB 서류 접근 로그(invoice) +1');
    });

    await r.step('1단계 카드 — 통관 SET (Ver.1)', async () => {
      await download(() => p.locator('a', { hasText: '통관 SET (Ver.1)' }).first().click(), `통관SET(Ver.1)_00가1113_${ymd}.xlsx`, '통관 SET');
      await L.assertNoViolations(p);
    });

    await r.step('2단계 목록에서 2대 체크 → 막대', async () => {
      await L.closePanel(p);
      L.unlockVehicles(['00가1113']);
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent('00가11')}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(1200);
      for (const pl of ['00가1131', '00가1141']) {
        const cb = p.locator('tr', { hasText: pl }).first().locator('input[wire\\:model\\.live="shipDocIds"]');
        if (!(await cb.isChecked())) await cb.check();
        await p.waitForTimeout(600);
      }
      await L.expectText(p, '2대 선택', '막대 「2대 선택」');
      await L.expectText(p, '선택 해제', '막대 [선택 해제]');
      await L.expectText(p, '컨테이너 Invoice&Packing', '막대 선적 서류 버튼');
    });

    await r.step('2단계 막대 [컨테이너 Invoice&Packing] → 2대 한 서류', async () => {
      const ids = [veh('00가1131').id, veh('00가1141').id];
      const before = logCount(ids, 'container_invoice_packing');
      await download(() => p.locator('a', { hasText: /컨테이너 Invoice&Packing/ }).first().click(), `컨테이너_Invoice_Packing_2대_${ymd}.xlsx`, '여러 대 서류');
      await L.assertNoViolations(p);
      // 다중 발급 = 차량당 접근 로그 1행(SKILLS §12).
      L.assertEq(logCount(ids, 'container_invoice_packing'), before + 2, 'DB 서류 접근 로그(차량당 1행) +2');
    });

    await r.step('알아 둘 것 — [선택 해제]로 비운다', async () => {
      await p.locator('button', { hasText: '선택 해제' }).first().click();
      await L.expectNoText(p, '2대 선택', '선택 해제 뒤 막대', { settle: 1500 });
    });
  } finally {
    await ctx.close();
  }
  return r.result();
};
