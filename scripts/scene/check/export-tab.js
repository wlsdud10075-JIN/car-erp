// 씬 점검 — export-tab (resources/guides/export-tab). 촬영 원본 = cap/ex_scene.js · ex_scene2.js
// 계정 = clear@ (수출통관 — 확인 창 없이 저장). 차 = _loop.PLATE (완납 · 판매완료).
// 저장마다 진행상태: (말소) → 선적중(반입지) → 선적중(신고서) → 선적완료(선적일) → 통관중(ETA) → 통관완료(체크).
// 🚨 ① 반입 저장에서 인코텀즈가 들어가는 순간 정산이 「대기」로 생긴다(가이드 「알아 둘 것」) → Settlement::created 가
//    erp_settle_pending 알림톡을 시도한다. 러너가 알림톡 마스터가 꺼진 것을 먼저 확인하므로 밖으로 안 나간다(BizmAlimtalkService 게이트).
// 업로드 PDF = _loop.pdf() 로 메모리에서 만든다(cap/sample-*.pdf 는 레포에 없다). 파일은 public 디스크 vehicles/{id}/ 에 남고 시더가 지운다.
const L = require('./lib');
const X = require('./_loop');

const RRN_FAKE = '000000-0000000';          // 실존할 수 없는 가짜 번호(가이드 0단계 ※ 와 같음)
const DECL_NO = '999-99-999999';
const VESSEL = 'GUIDE SAMPLE VESSEL';

module.exports.run = async function run({ browser }) {
  const r = L.recorder('export-tab');
  const { iso } = L.today();
  let v;
  const settlement = () => L.dbOne("select id, settlement_status s from settlements where vehicle_id = ? and deleted_at is null order by id desc limit 1", [v.id]);

  await r.step('사전: 앞 씬(sale-payment) 결과 — 판매완료 · 말소 전 · 인코텀즈 비어 있음', async () => {
    v = X.vehicle();
    if (!v || v.p !== '판매완료') { r.skip(`앞 씬 sale-payment 가 끝나지 않았다(진행상태 ${v ? v.p : '차 없음'})`); return '건너뜀'; }
    if (Number(v.is_deregistered) || v.incoterms || v.bl_loading_location) throw new Error('말소·수출 칸이 이미 채워져 있다 — 시더가 안 돌았다');
    L.unlockVehicles([X.PLATE]);
  });

  const ctx = await L.newContext(browser);
  const p = await L.newPage(ctx);
  const open = async (tab) => { L.unlockVehicles([X.PLATE]); await L.openVehicle(p, X.PLATE, tab); };
  const step = async (name, fill, expect) => r.step(name, async () => {
    await open('export');
    await fill();
    await X.saveEdit(p, name);
    await L.closePanel(p);
    v = X.vehicle();
    L.assertEq(v.p, expect, `DB 진행상태(${name})`);
  });

  try {
    await r.step('0단계 기본정보 소유자 주민(법인)등록번호 → 매입 탭 말소완료 · 말소증명서 · 말소등록일', async () => {
      await L.login(p, 'clear@car-erp.test');
      await open('basic');
      await p.locator('input[wire\\:model\\.blur="nice_reg_owner_rrn"]').fill(RRN_FAKE);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(500);
      await p.click(`button[\\@click="tab = 'purchase'"]`);
      await p.waitForTimeout(600);
      await p.locator('input[type=checkbox][wire\\:model="is_deregistered"]').check();
      await p.locator('input[type=file][wire\\:model="deregistrationDocFile"]').setInputFiles(X.pdf('scene-deregistration.pdf'));
      await p.waitForTimeout(2500);
      await X.typeDate(p, 'input[wire\\:model="deregistration_date"]', iso);
      await X.saveEdit(p, '말소 저장');
      await L.closePanel(p);
      v = X.vehicle();
      L.assertEq(Number(v.is_deregistered), 1, 'DB 말소완료');
      if (!v.deregistration_document) throw new Error('DB: 말소증명서 파일 경로 없음');
      L.assertEq(Number(v.has_rrn), 1, 'DB 주민등록번호(암호화) 저장');
    });

    await r.step('1단계 수출 탭 맨 위 「수출 진입 가능 — 입금률 100%」 · 컨사이니 [+ 신규]', async () => {
      await open('export');
      await L.expectText(p, '수출 진입 가능 — 입금률 100%', '초록 칸(수출 진입)');
      await p.locator(`button[wire\\:click="openQuickAdd('consignee','bl')"]`).click();
      await L.expectText(p, '신규 컨사이니 등록', '창 제목');
      await p.locator('input[wire\\:model="qaName"]').fill(X.CONSIGNEE);
      await L.expectAfter(p, () => p.locator('button[wire\\:click="saveQuickAdd"]').click(), '신규 컨사이니 등록 완료', '[등록 + 선택] 토스트');
      await L.assertNoViolations(p);
      const c = L.dbOne('select id, buyer_id from consignees where name = ?', [X.CONSIGNEE]);
      if (!c) throw new Error('DB: 컨사이니 없음');
      L.assertEq(c.buyer_id, v.buyer_id, 'DB 컨사이니가 이 바이어 아래');
    });

    await r.step('2단계 반입지 · 포워딩사 · 서류마감 · RORO · 선적항 · CFR → [수정 저장] → 「선적중」 + 정산 「대기」', async () => {
      // 패널은 1단계에서 연 채 — 컨사이니가 칸에 들어가 있다.
      const loc = p.locator('select[wire\\:model="bl_loading_location"]').first();
      const hj = await loc.locator('option', { hasText: 'HJIT' }).first().getAttribute('value');
      await loc.selectOption(hj);
      await p.locator('select[wire\\:model="forwarding_company_id_str"]').first().selectOption({ label: '부산포워딩㈜' });
      await X.typeDate(p, 'input[wire\\:model="document_deadline_date"]', L.addDays(2));
      await p.locator('select[wire\\:model="shipping_method"]').first().selectOption('RORO');
      await p.locator('select[wire\\:model="port_of_loading"]').first().selectOption({ label: 'BUSAN, KOREA' });
      await p.locator('select[wire\\:model="incoterms"]').first().selectOption('CFR');
      await p.waitForTimeout(800);
      await X.saveEdit(p, '반입 저장');
      await L.closePanel(p);
      v = X.vehicle();
      L.assertEq(v.p, '선적중', 'DB 진행상태');
      L.assertEq(v.bl_loading_location, 'HJIT', 'DB 반입지');
      L.assertEq(v.incoterms, 'CFR', 'DB 인코텀즈');
      L.assertEq(v.shipping_method, 'RORO', 'DB 선적방법');
      L.assertEq(v.port_of_loading, 'BUSAN, KOREA', 'DB 선적항');
      const c = L.dbOne('select id from consignees where name = ?', [X.CONSIGNEE]);
      if (Number(v.bl_consignee_id) !== Number(c.id) && Number(v.export_consignee_id) !== Number(c.id)) throw new Error('DB: 컨사이니가 차에 안 들어갔다');
      // 가이드 「판매 대금을 다 받은 차는 인코텀즈를 넣고 저장하는 순간 담당자 정산이 「대기」로 생깁니다」
      const s = settlement();
      if (!s) throw new Error('DB: 인코텀즈 저장 뒤 정산이 안 생겼다');
      L.assertEq(s.s, 'pending', 'DB 정산 상태(대기)');
    });

    await step('3단계 수출신고번호 · 면장금액(자동) · 수출신고서 → 「선적중」 그대로', async () => {
      await p.locator('input[wire\\:model="export_declaration_number"]').fill(DECL_NO);
      // 면장금액 = 판매 금액 자동(가이드 「예시 10,000.00」). 칸 값은 innerText 에 안 잡혀 DB 로 본다(Vehicle::saving 이 비었을 때 채움).
      const dec = L.dbOne('select export_declaration_amount a from vehicles where id = ?', [v.id]);
      L.assertEq(Number(dec.a), 10000, 'DB 면장금액(자동 = 판매가 + 운임비)');
      await p.locator('input[type=file][wire\\:model="exportDeclarationDocFile"]').setInputFiles(X.pdf('scene-export-declaration.pdf'));
      await p.waitForTimeout(2500);
      await L.expectText(p, '(저장 전)', '올린 파일 「(저장 전)」');
    }, '선적중');

    await step('4단계 선적일 · VSL → 「선적완료」', async () => {
      await X.typeDate(p, 'input[wire\\:model="shipping_date"]', iso);
      await p.locator('input[wire\\:model="vessel_name"]').fill(VESSEL);
    }, '선적완료');

    await step('5단계 ETA → 「통관중」', async () => {
      await X.typeDate(p, 'input[wire\\:model="eta_date"]', L.addDays(24));
    }, '통관중');

    await r.step('5단계 목록 — 「통관중」 옆 🚢', async () => {
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      if (!t.includes('통관중')) throw new Error('목록 줄에 「통관중」 없음');
      if (!t.includes('🚢')) throw new Error('목록 줄에 🚢(운항중) 없음');
    });

    await step('6단계 통관 완료 체크 → 「통관완료」', async () => {
      await p.locator('input[type=checkbox][wire\\:model="is_export_cleared"]').check();
      await p.waitForTimeout(500);
    }, '통관완료');

    await r.step('7단계 최종 DB — 신고서·선적일·ETA·선박명 · 목록 「통관완료」 + 🚢', async () => {
      v = X.vehicle();
      L.assertEq(v.export_declaration_number, DECL_NO, 'DB 수출신고번호');
      if (!v.export_declaration_document) throw new Error('DB: 수출신고서 파일 없음');
      L.assertEq(String(v.shipping_date).slice(0, 10), iso, 'DB 선적일');
      L.assertEq(String(v.eta_date).slice(0, 10), L.addDays(24), 'DB ETA');
      L.assertEq(v.vessel_name, VESSEL, 'DB 선박명');
      L.assertEq(Number(v.is_export_cleared), 1, 'DB 통관 완료');
      await p.goto(`${L.BASE}/erp/vehicles?search=${encodeURIComponent(X.PLATE)}`, { waitUntil: 'networkidle', timeout: 120000 });
      const t = await p.locator('tr', { hasText: X.PLATE }).first().innerText();
      if (!t.includes('통관완료')) throw new Error('목록 줄에 「통관완료」 없음');
      if (!t.includes('🚢')) throw new Error('목록 줄에 🚢 가 사라졌다(배가 아직 가는 중)');
      await L.assertNoViolations(p);
    });
    // 0단계 「말소 전 저장 막힘」 문구는 안 본다 — 가이드가 그림으로만 싣는다(인용 문구 없음).
  } finally {
    await ctx.close();
    L.unlockVehicles([X.PLATE]);
  }
  return r.result();
};
