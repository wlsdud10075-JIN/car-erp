// 씬 점검 — shipment-fee (resources/guides/shipment-fee). 촬영 원본 = cap/sh_scene.js · sh_re.js
// ① 수출통관 clear@ — 00가1141 EMS 한 줄(EE123456789KR · 25,000)
// ② 업무관리자 guide.manager@ — [EMS/DHL 기입] DHL 1234567890 총 60,000 → 00가1131·00가1141 에 30,000 씩
// 시더가 이 두 번호의 발송 줄을 지워 둔다(다른 발송 줄은 안 건드린다).
const L = require('./lib');

const EMS_NO = 'EE123456789KR';
const DHL_NO = '1234567890';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('shipment-fee');
  const { iso } = L.today();
  const shipRows = (plate) => L.dbQuery(
    'select s.carrier, s.tracking_no, s.fee, s.sent_date from vehicle_shipments s join vehicles v on v.id = s.vehicle_id where v.vehicle_number = ? and s.tracking_no in (?, ?)', [plate, EMS_NO, DHL_NO]);
  const feeTotal = (plate) => Number(L.dbOne('select shipping_fee_total_cache t from vehicles where vehicle_number = ? and deleted_at is null', [plate]).t || 0);
  let base1141 = 0;
  let base1131 = 0;

  const c = await L.newContext(browser);
  const cp = await L.newPage(c);
  try {
    await r.step('사전: 두 번호의 발송 줄이 없다(시더)', async () => {
      if (shipRows('00가1141').length || shipRows('00가1131').length) throw new Error('예시 발송 줄이 남아 있다 — GuideSceneFixtureSeeder 가 안 돌았다');
      base1141 = feeTotal('00가1141');
      base1131 = feeTotal('00가1131');
      L.unlockVehicles(['00가1131', '00가1141']);
    });

    await r.step('1단계 차를 열고 「EMS / DHL」 탭 → [＋ 발송 추가]', async () => {
      await L.login(cp, 'clear@car-erp.test');
      await L.openVehicle(cp, '00가1141', 'dhl');
      await L.expectText(cp, 'EMS / DHL', '탭 이름');
      await L.expectText(cp, '＋ 발송 추가', '[＋ 발송 추가]');
      await L.expectText(cp, '발송비 합계', '「발송비 합계」');
      await cp.locator('button[wire\\:click="addShipment(\'ems\')"]').click();
      await cp.waitForTimeout(1000);
      // 가이드: 「접수일에는 오늘이 미리 들어가 있으니」
      const pre = await cp.locator('input[wire\\:model="shipments.0.sent_date"]').inputValue();
      L.assertEq(pre, iso, '새 줄 접수일 기본값(오늘)');
    });

    await r.step('1단계 등기번호 · 우편요금 · 접수일 → [수정 저장]', async () => {
      await cp.locator('input[wire\\:model="shipments.0.tracking_no"]').fill(EMS_NO);
      await cp.locator('input[wire\\:model="shipments.0.fee"]').fill('25000');
      const d = cp.locator('input[wire\\:model="shipments.0.sent_date"]');
      await d.fill(iso); await cp.keyboard.press('Tab');
      await cp.waitForTimeout(500);
      await cp.getByRole('button', { name: '수정 저장' }).click();
      // 저장 전 확인 모달(말소 체크↔서류 등)이 뜨면 「그대로」 쪽을 누른다 — 촬영 스크립트와 같은 목록.
      for (let i = 0; i < 3; i++) {
        await cp.waitForTimeout(1500);
        const x = cp.locator('button:visible', { hasText: /맞습니다|확인 후 저장|그대로 저장|저장합니다/ });
        if (await x.count()) await x.first().click(); else break;
      }
      await L.expectText(cp, '차량 정보가 수정됐습니다.', '저장 토스트', { timeout: 20000 });
      await L.assertNoViolations(cp);
      const rows = shipRows('00가1141');
      const ems = rows.find((x) => x.tracking_no === EMS_NO);
      if (!ems) throw new Error('DB: EMS 줄 없음');
      L.assertEq(ems.carrier, 'ems', 'DB 구분');
      L.assertEq(Number(ems.fee), 25000, 'DB 우편요금');
      L.assertEq(String(ems.sent_date).slice(0, 10), iso, 'DB 접수일');
      L.assertEq(feeTotal('00가1141'), base1141 + 25000, 'DB 발송비 합계 캐시');
    });
    await L.closePanel(cp);
  } finally {
    await c.close();
  }
  L.unlockVehicles(['00가1131', '00가1141']);

  const m = await L.newContext(browser);
  const mp = await L.newPage(m);
  try {
    await r.step('2단계 [EMS/DHL 기입] (관리 이상) → [미리보기]', async () => {
      await L.login(mp, 'guide.manager@car-erp.test');
      await mp.goto(`${L.BASE}/erp/vehicles`, { waitUntil: 'networkidle', timeout: 120000 });
      await mp.getByRole('button', { name: /EMS\/DHL 기입/ }).first().click();
      await L.expectText(mp, 'EMS · DHL 발송 내역 일괄 기입', '일괄 창 제목');
      await mp.locator('select[wire\\:model\\.live="shipBulkCarrier"]').selectOption('dhl');
      await mp.waitForTimeout(600);
      await mp.locator('input[wire\\:model="shipBulkTrackingNo"]').fill(DHL_NO);
      const bd = mp.locator('input[wire\\:model="shipBulkDate"]');
      await bd.fill(iso); await mp.keyboard.press('Tab');
      await mp.locator('input[wire\\:model="shipBulkTotal"]').fill('60000');
      await mp.locator('textarea[wire\\:model="shipBulkRaw"]').fill('00가1131\n00가1141');
      await mp.locator('button[wire\\:click="previewShipmentBulk"]').click();
      await L.expectText(mp, '기입 2대', '미리보기 「기입 2대」');
      await L.expectText(mp, '1대당', '미리보기 대당 금액');
      await L.expectText(mp, '30,000', '미리보기 30,000');
    });

    await r.step('2단계 [기입] → 총액을 대수만큼 나눔', async () => {
      await L.expectAfter(mp, () => mp.locator('button[wire\\:click="applyShipmentBulk"]').click(), '기입 2대 · 변동없음 0대 · 제외 0대 · 건너뜀 0대', '기입 결과');
      await L.assertNoViolations(mp);
      for (const pl of ['00가1131', '00가1141']) {
        const dhl = shipRows(pl).find((x) => x.tracking_no === DHL_NO);
        if (!dhl) throw new Error(`DB: ${pl} DHL 줄 없음`);
        L.assertEq(dhl.carrier, 'dhl', `DB ${pl} 구분`);
        L.assertEq(Number(dhl.fee), 30000, `DB ${pl} 청구금액`);
      }
      L.assertEq(feeTotal('00가1131'), base1131 + 30000, 'DB 00가1131 발송비 합계');
      L.assertEq(feeTotal('00가1141'), base1141 + 55000, 'DB 00가1141 발송비 합계(EMS+DHL)');
    });

    await r.step('2단계 각 차의 탭에 그 발송 줄 · 「발송비 합계」', async () => {
      L.unlockVehicles(['00가1141']);
      await L.openVehicle(mp, '00가1141', 'dhl');
      await L.expectText(mp, '발송비 합계', '「발송비 합계」');
      const total = (base1141 + 55000).toLocaleString('en-US');
      await L.expectText(mp, total, `합계 ${total}`);
      await L.closePanel(mp);
    });
  } finally {
    await m.close();
  }
  L.unlockVehicles(['00가1131', '00가1141']);
  return r.result();
};
