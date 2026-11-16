// 씬 점검 — unpaid-gate-override (resources/guides/unpaid-gate-override). 촬영 원본 = cap/gate_scene.js · gate_prep.php · mk_gate.php
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// ① 수출통관 clear@ — 00가1141 수출 탭 「수출 진입 잠김 — 입금률 20% (필요 N%)」
// ② 관리 manage@ — 「관리/관리자 — 미입금 우회 승인」 수출 진입 단계 + 사유 → [승인 기록]
// ③ 수출통관 clear@ — 파란 「… 적용됨 → 수출 진입 가능」 → 반입지 → [수정 저장] → 진행상태 「선적중」
// 전제(시더 restoreGateOverride): 1141 = USD 10,000 · 계약금 2,000 확정 · 적립금 사용 0 · 반입지 없음 · 우회 승인 없음 ·
//   통관/B/L 바이어·컨사이니 지정 · 말소완료 · RRN 있음. ⚠️ savings 씬보다 먼저 돌아야 한다(1141 입금률이 바뀐다).
// ⚠️ bulk-shipping-dates(1단계)보다 먼저 — 선적일이 있으면 반입지 저장 뒤 진행상태가 「선적완료」가 된다.
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1141';
const REASON = '바이어 잔금 11/20 송금 예정 확인(메일). 선박 일정상 반입 먼저 진행.';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('unpaid-gate-override');
  let veh;
  let need;
  const state = () => L.dbOne('select id, bl_loading_location, progress_status_cache, is_override_active, shipping_date from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
  const overrides = () => L.dbQuery('select stage, reason from unpaid_export_overrides where vehicle_id = ?', [veh.id]);

  await r.step('사전: 1141 = 입금률 20% · 반입지 없음 · 우회 승인 없음(시더)', async () => {
    veh = L.dbOne("select id, currency, sale_price, savings_used, bl_loading_location, shipping_date, is_deregistered, export_consignee_id from vehicles where vehicle_number = ? and deleted_at is null", [PLATE]);
    if (!veh) throw new Error('예시 차량 00가1141 없음');
    const paid = Number(L.dbOne('select coalesce(sum(amount),0) s from final_payments where vehicle_id = ? and confirmed_at is not null', [veh.id]).s);
    L.assertEq(Number(veh.sale_price), 10000, '판매가');
    L.assertEq(paid, 2000, '확정 잔금 합');
    L.assertEq(Number(veh.savings_used), 0, '적립금 사용(savings 씬이 먼저 돌았나)');
    if (veh.bl_loading_location) throw new Error('반입지가 이미 있다 — 시더가 안 돌았다');
    if (veh.shipping_date) throw new Error('선적일이 있다 — bulk-shipping-dates 가 먼저 돌았다(진행상태 기대가 「선적완료」로 바뀐다)');
    if (overrides().length) throw new Error('우회 승인 행이 남아 있다 — 시더가 안 돌았다');
    if (!Number(veh.is_deregistered) || !veh.export_consignee_id) throw new Error('말소 또는 통관 컨사이니가 비어 있다 — 반입지 저장의 다른 관문에 막힌다');
    const set = (L.dbOne("select value from settings where `key` = 'company_template_set'") || {}).value || 'system';
    need = Number((L.dbOne('select value from settings where `key` = ?', [`lock_threshold_shipping_entry_${set}`]) || { value: 50 }).value);
    if (!(need > 20)) throw new Error(`이 회사 수출 진입 기준(${need}%)이 20% 이하라 잠기지 않는다 — 씬 전제가 다르다`);
    L.unlockVehicles([PLATE]);
    return `필요 ${need}%`;
  });

  const c = await L.newContext(browser);
  const cp = await L.newPage(c);
  const m = await L.newContext(browser);
  const mp = await L.newPage(m);
  try {
    await r.step('1단계 수출통관 — 수출 탭 빨간 「수출 진입 잠김」 · 저장 막힘', async () => {
      await L.login(cp, 'clear@car-erp.test');
      await L.openVehicle(cp, PLATE, 'export');
      await L.expectText(cp, '수출 진입 잠김', '「수출 진입 잠김」');
      await L.expectText(cp, `입금률 20% (필요 ${need}%)`, `「입금률 20% (필요 ${need}%)」`);
      const sel = cp.locator('select[wire\\:model="bl_loading_location"]');
      if (!(await sel.isDisabled())) {
        await sel.selectOption({ index: 1 });
        await cp.getByRole('button', { name: '수정 저장' }).first().click();
        await cp.waitForTimeout(3000);
        for (let i = 0; i < 2; i++) { const x = cp.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ }); if (await x.count()) { await x.first().click(); await cp.waitForTimeout(2000); } }
      }
      await L.assertNoViolations(cp);
      const s = state();
      if (s.bl_loading_location) throw new Error('DB: 잠긴 상태인데 반입지가 저장됐다');
      await L.closePanel(cp);
    });
    L.unlockVehicles([PLATE]);

    await r.step('2단계 관리 — 「미입금 우회 승인」 펼침 · 단계 · 사유 → [승인 기록]', async () => {
      await L.login(mp, 'manage@car-erp.test');
      await L.openVehicle(mp, PLATE, 'export');
      await mp.getByText('관리/관리자 — 미입금 우회 승인').click();
      await mp.waitForTimeout(600);
      await L.expectText(mp, '미입금 잔존:', '「미입금 잔존」');
      await L.expectText(mp, 'B/L 발행 (입금 100%)', '단계 「B/L 발행 (입금 100%)」');
      await L.expectText(mp, '사유 (20자 이상)', '「사유 (20자 이상)」');
      await mp.locator('select[wire\\:model="overrideStage"]').selectOption('shipping');
      await mp.locator('input[wire\\:model="overrideReason"]').fill(REASON);
      const ob = mp.locator('button[wire\\:click="approveUnpaidOverride"]');
      await ob.scrollIntoViewIfNeeded();
      await L.expectAfter(mp, () => ob.click(), '미입금 우회 승인 완료 — 해당 단계 진입 가능', '[승인 기록] 토스트');
      await L.assertNoViolations(mp);
      await L.expectText(mp, '승인 1', '칸 머리 「승인 1」');
      await L.expectText(mp, '기존 승인 1건:', '「기존 승인 1건:」');
      const o = overrides();
      L.assertEq(o.length, 1, 'DB 우회 승인 행 수');
      L.assertEq(o[0].stage, 'shipping', 'DB 단계(수출 진입 = shipping)');
      L.assertEq(o[0].reason, REASON, 'DB 사유');
      await L.closePanel(mp);
    });
    L.unlockVehicles([PLATE]);

    await r.step('3단계 수출통관 — 파란 「적용됨 → 수출 진입 가능」 → 반입지 → 저장 → 「선적중」', async () => {
      await L.openVehicle(cp, PLATE, 'export');
      await L.expectText(cp, '미입금 우회 승인(수출 진입) 적용됨 → 수출 진입 가능', '파란 칸 문구');
      const sel = cp.locator('select[wire\\:model="bl_loading_location"]');
      if (await sel.isDisabled()) throw new Error('승인 뒤에도 반입지 칸이 잠겨 있다');
      await sel.selectOption({ index: 1 });
      await cp.waitForTimeout(500);
      await H.saveVehicle(cp, '반입지 저장');
      const s = state();
      if (!s.bl_loading_location) throw new Error('DB: 반입지가 저장되지 않았다');
      L.assertEq(s.progress_status_cache, '선적중', 'DB 진행상태');
      await L.closePanel(cp);
    });
  } finally {
    await c.close();
    await m.close();
  }
  L.unlockVehicles([PLATE]);
  return r.result();
};
