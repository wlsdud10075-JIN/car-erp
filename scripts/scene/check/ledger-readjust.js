// 씬 점검 — ledger-readjust (resources/guides/ledger-readjust). 촬영 원본 = cap/ra_scene.js · ra2.js
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = guide.manager@(업무관리자). 차 = 00가1121 — secondary-settlement 결과(2차 마감 · 운임비 0)를 그대로 쓴다.
// 흐름: 정산 관리 [🔓 회계 재조정] → 사유 → [해제 후 차량 편집] → 「잠금 해제됨」 → 판매 탭 운임비 300,000 → 저장
//      → 다시 열면 「2차 정산 마감됨」 · 판매 미입금 요약 300,000 · 정산 목록 「받을 ₩300,000」.
// 알아 둘 것 「재무는 버튼이 안 보입니다」 = finance@ 로 버튼 0개 확인(읽기만).
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1121';
const REASON = '선사 추가 운임 청구분 반영 (가이드 예시)';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('ledger-readjust');
  let veh;
  const auditCount = () => Number(L.dbOne("select count(*) c from audit_logs where auditable_id = ? and auditable_type like '%Vehicle' and action = 'ledger_field_unlocked'", [veh.id]).c);
  let a0 = 0;

  await r.step('사전: 1121 = 2차 마감 · 운임비 0', async () => {
    veh = L.dbOne('select v.id, v.transport_fee, s.secondary_status sec from vehicles v join settlements s on s.vehicle_id = v.id and s.deleted_at is null where v.vehicle_number = ? and v.deleted_at is null', [PLATE]);
    if (!veh) throw new Error('00가1121 또는 그 정산 없음');
    L.assertEq(veh.sec, 'closed', '2차 상태(secondary-settlement 가 먼저 돌아야 한다)');
    L.assertEq(Number(veh.transport_fee), 0, '운임비');
    a0 = auditCount();
    L.unlockVehicles([PLATE]);
  });

  const search = async (pg) => {
    // 월 필터를 준다 — 기본값은 「지금 급여월」이라 12월부터는 11월 귀속 1121 이 안 나온다.
    await pg.goto(`${L.BASE}/erp/settlements?monthFilter=2026-11`, { waitUntil: 'networkidle', timeout: 120000 });
    await H.hideAlarm(pg);
    await pg.locator('input[wire\\:model="search"]').first().fill(PLATE);
    await pg.keyboard.press('Enter');
    await pg.waitForTimeout(2200);
    await H.hideAlarm(pg);
  };
  const BTN = 'button[wire\\:click\\.stop^="openReadjustModal"]';

  const f = await L.newContext(browser);
  const fp = await L.newPage(f);
  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  try {
    await r.step('알아 둘 것 — 재무 계정에는 [회계 재조정] 이 없다', async () => {
      await L.login(fp, 'finance@car-erp.test');
      await search(fp);
      if (!(await fp.locator('tr', { hasText: PLATE }).count())) throw new Error('재무 목록에 1121 없음');
      L.assertEq(await fp.locator(BTN).count(), 0, '재무 화면의 회계 재조정 버튼 수');
    });

    await r.step('1·2단계 [🔓 회계 재조정] → 사유 → [해제 후 차량 편집]', async () => {
      await L.login(p, 'guide.manager@car-erp.test');
      await search(p);
      await p.locator(BTN).first().evaluate((e) => e.click());   // 촬영과 같이 evaluate — 알람 판·행 클릭(드로어)과 안 겹친다(lib 클릭 가드는 이것도 잡는다)
      await L.expectText(p, '마감 정산 회계 재조정', '창 제목');
      await p.locator('textarea[wire\\:model="readjustReason"]').fill(REASON);
      await Promise.all([
        p.waitForURL(/openVehicle/, { timeout: 60000 }),
        p.locator('button[wire\\:click="submitReadjust"]').first().evaluate((e) => e.click()),
      ]);
      await L.expectText(p, '잠금 해제됨 — 저장 1회 후 자동 재잠금', '해제 띠', { timeout: 30000 });
      await L.assertNoViolations(p);
      L.assertEq(auditCount(), a0 + 1, 'DB 감사 로그 ledger_field_unlocked +1');
    });

    await r.step('3단계 판매 탭 운임비 300,000 → 「판매 정보 확인」 → [확인 후 저장]', async () => {
      await p.click(`button[\\@click="tab = 'sale'"]`);
      await p.waitForTimeout(900);
      await p.locator('input[wire\\:model="transport_fee_str"]').first().fill('300,000');
      await p.keyboard.press('Tab');
      await p.waitForTimeout(500);
      await H.saveVehicle(p, '운임비 저장', { modalTitle: '판매 정보 확인' });
      L.assertEq(Number(L.dbOne('select transport_fee t from vehicles where id = ?', [veh.id]).t), 300000, 'DB 운임비');
      await L.closePanel(p);
      L.unlockVehicles([PLATE]);
    });

    await r.step('4단계 다시 열면 재잠금 · 판매 미입금 요약 300,000 · 목록 「받을 ₩300,000」', async () => {
      await p.goto(`${L.BASE}/erp/vehicles?openVehicle=${veh.id}`, { waitUntil: 'networkidle', timeout: 120000 });
      await p.waitForTimeout(2500);
      await H.hideAlarm(p);
      await L.expectText(p, '2차 정산 마감됨 — 회계 영향 컬럼 잠김', '재잠금 띠');
      await p.click(`button[\\@click="tab = 'sale'"]`);
      await p.waitForTimeout(900);
      await L.expectText(p, '판매 미입금 요약', '「판매 미입금 요약」');
      await L.expectText(p, '300,000', '새 미수 300,000');
      await L.closePanel(p);
      L.unlockVehicles([PLATE]);
      await search(p);
      if (!(await p.locator('tr', { hasText: PLATE }).first().innerText()).includes('받을 ₩300,000')) throw new Error('정산 목록 줄에 「받을 ₩300,000」 없음');
    });
  } finally {
    await f.close();
    await ctx.close();
  }
  L.unlockVehicles([PLATE]);
  return r.result();
};
