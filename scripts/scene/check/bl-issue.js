// 씬 점검 — bl-issue (resources/guides/bl-issue). 촬영 원본 = cap/bl_scene.js · bl_locked.js
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = clear@. 차 = 00가1113 — 🚨 2단계(한 바퀴) 사슬의 차. 「완납 · 반입지 있음 · 통관완료 · B/L 없음」 일 때만 돈다(아니면 건너뜀).
//   1113 의 B/L 칸·출고일 되돌리기는 2단계 시더와 합칠 것(seeder-additions.php restoreBlIssue 는 제안).
// ① 「✓ B/L 발행 가능 — 잔금 100% 완납」 ② 체크빌만 올려 저장 → 진행상태 그대로 ③ 번호 GSBL1113001 · 발행일 20261113 · 써랜더 · B/L 문서
//   → 「거래완료」 · 출고일 자동(선적일) · 목록 「거래완료」.
// 「B/L 발행 잠김」(대금이 남은 차) 화면은 00가1141(USD 미수)로 읽기만 확인한다(촬영의 80가8001 은 예시 차가 아니다).
// ⚠️ 업로드 디스크가 s3 면 건너뛴다(disk-guard.php).
const L = require('./lib');
const H = require('./p3-helpers');

// 🔀 11-16 — 2단계 한 바퀴 전용 차(00가2001)가 monthly-payout 뒤 정확히 「완납 · 반입지 · 통관완료 · B/L 없음 · 정산 지급완료」다(가이드 상태).
//   00가1113 은 이미 거래완료라 매번 건너뛰었다. 이 차는 다음 시더(resetLoop)가 통째로 지운다.
const PLATE = '00가2001';
const LOCKED = '00가1141';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('bl-issue');
  const v = () => L.dbOne('select id, bl_document bl, checkbill_document cb, bl_number, bl_type, bl_issue_date, bl_loading_location, shipping_date, warehouse_out_date, progress_status_cache ps, sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
  let before;

  await r.step('사전: 00가2001 = 완납 · 반입지 · 통관완료 · B/L·체크빌 없음 · 업로드 디스크', async () => {
    before = v();
    if (!before) { r.skip('00가2001 없음(한 바퀴가 안 돌았다)'); return; }
    if (before.bl || before.cb || Number(before.u) > 0 || !before.bl_loading_location || before.ps !== '통관완료') {
      r.skip(`00가2001 이 B/L 직전 상태가 아니다(진행 ${before.ps} · B/L ${before.bl ? '있음' : '없음'}) — 한 바퀴(monthly-payout 까지)가 끝나야 돈다`);
      return '';
    }
    const disk = H.docsDisk();
    if (disk.driver === 's3') { r.skip(`서류 디스크가 s3(${disk.disk})`); return ''; }
    L.unlockVehicles([PLATE, LOCKED]);
    return `선적일 ${String(before.shipping_date || '').slice(0, 10)}`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  try {
    await r.step('1단계 B/L 탭 — 초록 「✓ B/L 발행 가능 — 잔금 100% 완납」', async () => {
      await L.login(p, 'clear@car-erp.test');
      await L.openVehicle(p, PLATE, 'bl');
      await L.expectText(p, '✓ B/L 발행 가능 — 잔금 100% 완납', '발행 가능 칸');
      for (const t of ['체크빌 (Check B/L)', '(올리면 거래완료)']) await L.expectText(p, t, `「${t}」`);
    });

    await r.step('2단계 체크빌만 올려 저장 → 진행상태 그대로', async () => {
      await p.locator('input[type=file][wire\\:model="checkbillFile"]').setInputFiles(H.tmpPdf());
      await L.expectText(p, '(저장 전)', '「(저장 전)」');
      await H.saveVehicle(p, '체크빌 저장');
      const x = v();
      if (!x.cb) throw new Error('DB: 체크빌 경로 없음');
      if (x.bl) throw new Error('DB: B/L 문서가 생겼다');
      L.assertEq(x.ps, before.ps, 'DB 진행상태(체크빌만으로는 그대로)');
      await L.closePanel(p);
      L.unlockVehicles([PLATE]);
    });

    await r.step('3단계 번호 · 발행일 8자리 · 써랜더 · B/L 문서 → [수정 저장](확인 창 없이)', async () => {
      await L.openVehicle(p, PLATE, 'bl');
      await p.locator('input[wire\\:model="bl_number"]').fill('GSBL1113001');
      const d = p.locator('input[wire\\:model="bl_issue_date"]');
      await d.click(); await d.pressSequentially('20261113'); await p.keyboard.press('Tab');
      await p.waitForTimeout(500);
      await p.locator('select[wire\\:model\\.live="bl_type"]').selectOption('surrender');
      await p.waitForTimeout(1000);
      await p.locator('input[type=file][wire\\:model="blDocFile"]').setInputFiles(H.tmpPdf());
      await p.waitForTimeout(2000);
      // 가이드: 「수출통관 계정은 확인 창 없이 바로 저장」 — 확인 창이 뜨면 실패로 본다
      await p.getByRole('button', { name: '수정 저장' }).first().click();
      await L.expectText(p, '차량 정보가 수정됐습니다.', '저장 토스트', { timeout: 20000 });
      if (await p.locator('button:visible', { hasText: '확인 후 저장' }).count()) throw new Error('수출통관인데 확인 창이 떴다');
      await L.assertNoViolations(p);
    });

    await r.step('4단계 「거래완료」 · 출고일 자동 · 목록 줄', async () => {
      const x = v();
      if (!x.bl) throw new Error('DB: B/L 문서 경로 없음');
      L.assertEq(x.bl_number, 'GSBL1113001', 'DB B/L 번호');
      L.assertEq(x.bl_type, 'surrender', 'DB B/L 방식');
      L.assertEq(String(x.bl_issue_date).slice(0, 10), '2026-11-13', 'DB 발행일(8자리 → 날짜)');
      L.assertEq(x.ps, '거래완료', 'DB 진행상태');
      const expectOut = String(before.shipping_date || x.bl_issue_date).slice(0, 10);
      L.assertEq(String(x.warehouse_out_date || '').slice(0, 10), expectOut, 'DB 출고일 자동(선적일 → 없으면 발행일)');
      await L.closePanel(p);
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      if (!(await p.locator('tr', { hasText: PLATE }).first().innerText()).includes('거래완료')) throw new Error('목록 줄에 「거래완료」 없음');
    });

    await r.step('알아 둘 것 — 대금이 남은 차는 「B/L 발행 잠김」(1141, 읽기만)', async () => {
      const u = L.dbOne('select sale_unpaid_amount_krw_cache u from vehicles where vehicle_number = ? and deleted_at is null', [LOCKED]);
      if (!u || !(Number(u.u) > 0)) return '건너뜀: 1141 미수 없음';
      L.unlockVehicles([LOCKED]);
      await L.openVehicle(p, LOCKED, 'bl');
      await L.expectText(p, 'B/L 발행 잠김', '「🔒 B/L 발행 잠김」');
      await L.closePanel(p);
      L.unlockVehicles([LOCKED]);
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles([PLATE, LOCKED]);
  return r.result();
};
