// 씬 점검 — deregistration-notice (resources/guides/deregistration-notice). 촬영 원본 = cap/dr_scene.js
// ⚠️ 초안 — 한 번도 실행하지 않았다.
// 계정 = clear@. 차 = 00가1131(시더가 말소 칸을 비워 둔다 — RRN 은 그대로).
// ① 「말소완료」 체크 · 말소증명서(임시 PDF) · 등록번호 2026-11131 · 말소등록일 20261114 → [수정 저장]
// ② 「국내 딜러 알림톡」 칸 — 잘못된 번호 경고 · 맞는 번호면 경고 사라짐 · [알림톡 발송] 버튼이 보이는지만.
// 🚫 [알림톡 발송] · [세금계산서 발행 요청] 은 누르지 않는다(lib 가 sendDeregistrationAlimtalk · sendTaxInvoiceAlimtalk 를 막는다).
// 🚫 딜러 번호는 저장하지 않는다(창을 [취소]로 닫는다).
// ⚠️ 업로드 디스크가 s3 면 파일 단계를 건너뛴다(매주 실제 버킷에 쌓인다) — disk-guard.php.
// ⚠️ 이 씬이 중간에 실패하면 1131 이 「말소 안 됨」으로 남는다 — 다음 씬 중 1131 반입지 저장이 있으면 막힌다.
const L = require('./lib');
const H = require('./p3-helpers');

const PLATE = '00가1131';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('deregistration-notice');
  const v = () => L.dbOne('select id, is_deregistered, deregistration_document doc, registration_number rn, deregistration_date dd, deregistration_notice_phone ph, nice_reg_owner_rrn is not null rrn from vehicles where vehicle_number = ? and deleted_at is null', [PLATE]);
  let disk;

  await r.step('사전: 1131 = 말소 안 됨 · RRN 있음(시더) · 업로드 디스크', async () => {
    const x = v();
    if (!x) throw new Error('예시 차량 00가1131 없음');
    if (Number(x.is_deregistered) || x.doc || x.rn || x.dd) throw new Error('말소 칸이 남아 있다 — 시더가 안 돌았다');
    if (!Number(x.rrn)) throw new Error('RRN 이 없다 — 「말소 처리에는 소유자 주민(법인)등록번호가 필수입니다」에 막힌다');
    disk = H.docsDisk();
    if (disk.driver === 's3') { r.skip(`서류 디스크가 s3(${disk.disk}) — 업로드 씬을 돌리지 않는다`); return 's3'; }
    L.unlockVehicles([PLATE]);
    return `디스크 ${disk.disk}/${disk.driver}`;
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const open = async () => {
    await L.openVehicle(p, PLATE, 'purchase');
    await p.locator('#purchase-dereg').evaluate((e) => e.scrollIntoView({ block: 'start' }));
    await p.waitForTimeout(400);
  };
  try {
    await r.step('1단계 말소완료 · 말소증명서 · 등록번호 · 말소등록일 → [수정 저장]', async () => {
      await L.login(p, 'clear@car-erp.test');
      await open();
      await p.locator('input[wire\\:model="is_deregistered"]').check();
      await p.locator('#purchase-dereg input[type=file]').first().setInputFiles(H.tmpPdf());
      await L.expectText(p, '(저장 전)', '파일 「(저장 전)」', { scope: '#purchase-dereg' });
      await p.locator('input[wire\\:model="registration_number"]').fill('2026-11131');
      const d = p.locator('input[wire\\:model="deregistration_date"]');
      await d.click(); await d.pressSequentially('20261114'); await p.keyboard.press('Tab');
      await p.waitForTimeout(600);
      await H.saveVehicle(p, '말소 저장');
      const x = v();
      L.assertEq(Number(x.is_deregistered), 1, 'DB 말소완료');
      if (!x.doc) throw new Error('DB: 말소증명서 경로 없음');
      L.assertEq(x.rn, '2026-11131', 'DB 등록번호');
      L.assertEq(String(x.dd).slice(0, 10), '2026-11-14', 'DB 말소등록일(8자리 → 날짜)');
      await L.closePanel(p);
      L.unlockVehicles([PLATE]);
    });

    await r.step('2단계 「국내 딜러 알림톡」 — 번호 형식 경고 · [알림톡 발송] 보임(안 누름)', async () => {
      await open();
      await L.expectText(p, '국내 딜러 알림톡', '칸 제목', { scope: '#purchase-dereg' });
      const ph = p.locator('input[wire\\:model\\.live\\.blur="deregistrationBuyerPhone"]');   // 11-16 .blur → .live.blur(§8 #138)
      await ph.fill('010-123-45');
      await p.keyboard.press('Tab');
      await L.expectText(p, '휴대폰 번호 형식이 아닙니다', '잘못된 번호 경고');
      await ph.fill('010-0000-1131');
      await p.keyboard.press('Tab');
      await L.expectNoText(p, '휴대폰 번호 형식이 아닙니다', '맞는 번호 → 경고 사라짐', { settle: 1500 });
      if (!(await p.locator('button[wire\\:click="sendDeregistrationAlimtalk"]:visible').count())) throw new Error('[알림톡 발송] 버튼이 안 보인다');
      await L.assertNoViolations(p);
      await L.closePanel(p);   // [취소] — 딜러 번호는 저장하지 않는다
      L.assertEq(v().ph, null, 'DB 딜러 번호(저장 안 함)');
    });
  } finally {
    await ctx.close();
  }
  L.unlockVehicles([PLATE]);
  return r.result();
};
