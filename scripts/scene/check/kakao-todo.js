// 씬 점검 — kakao-todo (resources/guides/kakao-todo). 촬영 원본 = cap/kk_explore.js · kk_setup.js · kk_scene.js
// 계정 = guide.manager@(김업무 — 가짜 방 하나만 구독). 방 = 「[데모] 한빛모터스 × 수출팀」(시더가 매번 다시 만든다 · 시각은 실행 시점 기준 상대값).
//
// 🚫🚫 jin PC 에 실제 카톡 에이전트(127.0.0.1:47321)가 떠 있다. lib 가 47321 을 막고, 여기서 한 겹 더:
//   - POST /erp/kakao/installer(연결 열쇠 발급) · /erp/kakao/rebind 주소 차단
//   - Livewire 메서드 continueClassifying · saveSetup(= Claude API 호출 · 비용) · recordReadResults · reportOpenRooms ·
//     revokeToken · unsubscribeRoom · resetSetup 차단
//   - [이 PC 연결하기] · [지금 읽기] · [이 글 열기] 는 lib 라벨 가드가 삼킨다 — 이 씬은 그 버튼이 「있다」만 본다.
// ⚠️ 분류는 시더가 미리 채운다 — type 이 빈 메시지가 있으면 페이지가 열리자마자 continueClassifying 을 부른다(위에서 막지만, 사전 단계에서 먼저 멈춘다).
// ⚠️ 묶음 자리 단언은 미룸·답글·방향으로 정해지는 것만 엄격히(d·c·f·h·i·j). a·b·e 는 시간 임계·KakaoAutoClose(ERP 상태)에 흔들려 「목록에 있다」만 본다.
const L = require('./lib');
const B = require('./board');

const ROOM = '[데모] 한빛모터스 × 수출팀';
const ME = 'guide.manager@car-erp.test';

// 시더(KakaoTodoDemoSeeder 흡수판)의 메시지 key → 본문 앞부분(행 찾기용 · 화면 표시 제목은 이 앞부분을 포함한다)
const MSG = {
  a: '00가1141 미수 7,800불',
  b: '00가1152 말소증 언제쯤',
  c: '다음 달 물량 견적표',
  d: '환율 기준을 계약일로',
  e: '11가1001 컨테이너 다음 주 화요일',
  f: '인보이스 금액 USD 로 다시',
  h: '00가1131 잔금 2,500불',
  i: '00가1141 수출신고서 수정본',
  j: '탁송 기사님 연락처',
};

module.exports.run = async function run({ browser }) {
  const r = L.recorder('kakao-todo');
  const msgId = (k) => {
    const x = L.dbOne('select m.id from kakao_messages m join kakao_rooms r on r.id = m.room_id where r.name = ? and m.body like ? order by m.id desc limit 1', [ROOM, `%${MSG[k]}%`]);
    if (!x) throw new Error(`데모 메시지 ${k} 없음`);
    return Number(x.id);
  };
  const item = (k) => L.dbOne('select i.state, i.snooze_until from kakao_items i join users u on u.id = i.user_id where u.email = ? and i.message_id = ?', [ME, msgId(k)]);
  const inBucket = (page, bucket, k) => page.evaluate(([b, id]) => {
    const el = document.querySelector(`[data-bucket="${b}"] [data-row="${id}"]`);
    return !!el;
  }, [bucket, msgId(k)]);
  const whereIs = (page, k) => page.evaluate((id) => {
    const el = document.querySelector(`[data-row="${id}"]`);
    const b = el && el.closest('[data-bucket]');
    return b ? b.getAttribute('data-bucket') : null;
  }, msgId(k));

  await r.step('사전: 데모 방 · 메시지 10건 · 미분류 0 · 분야 미선택 · 기기 연결 0(시더)', async () => {
    const room = L.dbOne('select id from kakao_rooms where name = ?', [ROOM]);
    if (!room) { r.skip('데모 방 없음 — GuideSceneFixtureSeeder(카톡 데모 블록)가 안 돌았다'); return; }
    const n = L.dbOne('select count(*) n, sum(type is null) u, min(sent_at) oldest from kakao_messages where room_id = ?', [room.id]);
    L.assertEq(Number(n.n), 10, '데모 메시지 수');
    if (Number(n.u) > 0) throw new Error(`미분류 ${n.u}건 — 페이지가 열리면 Claude 분류(비용)를 부른다. 멈춘다`);
    const pend = Number(L.dbOne(`select count(*) n from kakao_messages m join kakao_room_users ru on ru.room_id = m.room_id
                                 join users u on u.id = ru.user_id where u.email = ? and m.type is null`, [ME]).n);
    if (pend) throw new Error(`이 계정이 구독한 다른 방에 미분류 ${pend}건 — 멈춘다`);
    // 🚫 「나」가 빈 구독 방이 있으면 페이지 mount(autoSetUpRooms)가 서버에서 바로 classifyPending(Claude 호출)을 부른다 — 브라우저 가드로 못 막는다
    const noMe = Number(L.dbOne("select count(*) n from kakao_room_users ru join users u on u.id = ru.user_id where u.email = ? and (ru.my_name is null or trim(ru.my_name) = '')", [ME]).n);
    if (noMe) throw new Error(`「나」가 빈 구독 방 ${noMe}개 — 열면 서버가 분류(비용)를 돈다. 멈춘다`);
    L.assertEq(Number(L.dbOne('select count(*) n from kakao_user_topics t join users u on u.id = t.user_id where u.email = ?', [ME]).n), 0, '저장된 담당 분야(시더가 지움)');
    L.assertEq(Number(L.dbOne('select count(*) n from kakao_collector_tokens t join users u on u.id = t.user_id where u.email = ? and t.revoked_at is null', [ME]).n), 0, '연결된 PC(이 계정)');
  });

  const c = await L.newContext(browser);
  await B.extraGuard(c,
    ['continueClassifying', 'saveSetup', 'recordReadResults', 'reportOpenRooms', 'revokeToken', 'unsubscribeRoom', 'resetSetup'],
    [/\/erp\/kakao\/installer/, /\/erp\/kakao\/rebind/]);
  const p = await L.newPage(c);
  try {
    await r.step('1단계 화면 위 「이 PC 에는 에이전트가 없습니다」 · [방·수집기 설정] → 「이 PC 연결」 [이 PC 연결하기]', async () => {
      await L.login(p, ME);
      await p.goto(`${L.BASE}/erp/kakao`, { waitUntil: 'networkidle', timeout: 120000 });
      await L.expectText(p, '내 카톡 할 일', '화면 제목');
      await L.expectText(p, '이 PC 에는 에이전트가 없습니다', '에이전트 없음 안내', { timeout: 20000 });
      // 분야 고르기 창이 위를 덮고 있으면 먼저 2단계를 한다(창은 저장 전까지 뜬다)
    });

    await r.step('2단계 「내가 맡는 일을 골라 주세요」 → 「전체」 → [고른 분야 저장]', async () => {
      await L.expectText(p, '내가 맡는 일을 골라 주세요', '분야 고르기 창');
      await p.locator('[data-topic-pick="전체"]').click();
      await L.expectText(p, '고른 분야 저장', '[고른 분야 저장]');
      await p.locator('[data-topic-save]').click();
      await p.locator('[data-kakao-topic-picker]').waitFor({ state: 'detached', timeout: 15000 });
      await L.expectText(p, '내 담당 분야', '「내 담당 분야」 칩');
      L.assertEq(Number(L.dbOne('select count(*) n from kakao_user_topics t join users u on u.id = t.user_id where u.email = ?', [ME]).n), 1, 'DB 담당 분야 저장');
      await L.assertNoViolations(p);
    });

    await r.step('1단계(이어서) [방·수집기 설정] → 「이 PC 연결하기」 버튼이 있다(누르지 않음)', async () => {
      await p.getByRole('button', { name: '방·수집기 설정' }).click();
      await p.locator('[data-kakao-setup]').waitFor({ timeout: 15000 });
      await L.expectText(p, '이 PC 연결하기', '[이 PC 연결하기]', { scope: '[data-kakao-setup]' });
      await p.getByRole('button', { name: '설정 닫기' }).click().catch(() => {});
      await p.waitForTimeout(800);
    });

    await r.step('3단계 「읽을 방」에 데모 방 체크칸(체크됨) · [지금 읽기]는 에이전트가 없어 꺼져 있다', async () => {
      await L.expectText(p, '읽을 방', '「읽을 방」');
      const pick = p.locator(`[data-room-pick][data-room-name="${ROOM}"]`).first();
      if (!(await pick.count())) throw new Error('「읽을 방」에 데모 방 체크칸 없음');
      L.assertEq(await pick.getAttribute('data-room-selected'), '1', '데모 방 체크 상태');
      // 🚫 체크를 바꾸지 않는다(toggleRoom 은 무해하지만 다음 읽기 대상이 바뀐다)
      const disabled = await p.locator('[data-kakao-read]').isDisabled();
      if (!disabled) throw new Error('[지금 읽기]가 켜져 있다(에이전트가 응답했다? 47321 차단 확인)');
    });

    await r.step('4단계 묶음 자리 — 놓쳤을 가능성(d) · 미뤄둔(c) · 답 기다리는(h) · 팀 동료(i) · 끝난(f 자동 완료) · 나와 무관(j)', async () => {
      for (const label of ['놓쳤을 가능성', '지금 할 일', '오늘 안에', '답 기다리는 중', '미뤄둔 일', '팀 동료 진행 중', '오늘 끝난 것', '확인 필요', '나와 무관']) {
        await L.expectText(p, label, `묶음 「${label}」`);
      }
      const want = { d: 'missed', c: 'snoozed', h: 'waiting', i: 'teammate', f: 'done', j: 'none' };
      for (const [k, b] of Object.entries(want)) {
        const at = await whereIs(p, k);
        if (at !== b) throw new Error(`메시지 ${k}(${MSG[k]}…) 기대 ${b} / 실제 ${at}`);
      }
      for (const k of ['a', 'b', 'e']) {
        const at = await whereIs(p, k);
        if (!['now', 'today', 'missed', 'done'].includes(at)) throw new Error(`메시지 ${k} 가 할 일 묶음에 없음(${at})`);
      }
      // d 는 「미룬 시점 지남」 이유가 붙는다
      const dRow = p.locator(`[data-row="${msgId('d')}"]`);
      if (!((await dRow.textContent()) || '').includes('미룬 시점')) throw new Error('d 줄에 「미룬 시점 … 지남」 없음');
      const fRow = p.locator(`[data-row="${msgId('f')}"]`);
      if (!((await fRow.textContent()) || '').includes('자동 완료 후보')) throw new Error('f 줄에 「자동 완료 후보」 없음');
    });

    await r.step('4단계 「놓쳤을 가능성」 펼치기 → 차량 줄의 ERP 정보 · [완료] [미루기] [이 글 열기] [⋯]', async () => {
      await p.locator('[data-bucket="missed"] > button').first().click();
      await p.locator('[data-bucket="now"] > button').first().click();
      await p.waitForTimeout(500);
      const dRow = p.locator(`[data-row="${msgId('d')}"]`);
      await dRow.waitFor({ state: 'visible', timeout: 10000 });
      const t = await dRow.innerText();
      for (const s of ['완료', '미루기', '이 글 열기']) if (!t.includes(s)) throw new Error(`d 줄에 [${s}] 없음`);
      // 차량번호가 있는 줄(a = 00가1141)엔 ERP 카드 + ⋯ 메뉴의 [차량 열기]
      const aAt = await whereIs(p, 'a');
      if (['missed', 'now'].includes(aAt)) {
        const aRow = p.locator(`[data-row="${msgId('a')}"]`);
        const at = (await aRow.textContent()) || '';
        if (!at.includes('00가1141')) throw new Error('a 줄에 차량번호 뱃지 없음');
        if (!at.includes('차량 열기')) throw new Error('a 줄 ⋯ 메뉴에 [차량 열기] 없음');
      }
    });

    await r.step('4단계 [완료] (d) → 「오늘 끝난 것」으로', async () => {
      const id = msgId('d');
      await Promise.all([
        p.waitForResponse((x) => /\/livewire[^/]*\/update/.test(x.url()), { timeout: 30000 }),
        p.locator(`[data-bucket="missed"] [data-row="${id}"] button[wire\\:click="markDone(${id})"]`).click(),
      ]);
      await p.waitForTimeout(800);
      L.assertEq(await whereIs(p, 'd'), 'done', 'd 의 묶음');
      L.assertEq(item('d').state, 'done', 'DB d 상태');
      await L.assertNoViolations(p);
    });

    await r.step('5단계 [미루기] (e) → 「오늘 16:00 · 내일 10:00 · 날짜 지정 · 상대가 답하면」 → 「내일 10:00」 → 「미뤄둔 일」', async () => {
      const id = msgId('e');
      const from = await whereIs(p, 'e');
      if (from !== 'today' && from !== 'now') { r.skip(`e 가 ${from} 에 있어 미루기 단계를 건너뜀(시간 임계)`); return; }
      await p.locator(`[data-bucket="${from}"] > button`).first().click().catch(() => {});
      await p.waitForTimeout(400);
      const row = p.locator(`[data-bucket="${from}"] [data-row="${id}"]`);
      if (!(await row.isVisible())) await p.locator(`[data-bucket="${from}"] > button`).first().click();
      await row.getByRole('button', { name: '미루기' }).click();
      const form = p.locator('[data-snooze-form]');
      await form.waitFor({ timeout: 15000 });
      const ft = await form.innerText();
      for (const s of ['언제 다시 볼까요', '오늘 16:00', '내일 10:00', '날짜 지정', '상대가 답하면']) if (!ft.includes(s)) throw new Error(`미루기 창에 「${s}」 없음`);
      await form.locator('input[wire\\:model="snoozeReason"]').fill('견적 확인 후');
      // 🚫 「오늘 16:00」은 16시 이후 실행하면 곧바로 「놓쳤을 가능성」이 된다 — 늘 미래인 「내일 10:00」
      await Promise.all([
        p.waitForResponse((x) => /\/livewire[^/]*\/update/.test(x.url()), { timeout: 30000 }),
        form.getByRole('button', { name: '내일 10:00' }).click(),
      ]);
      await p.waitForTimeout(800);
      L.assertEq(await whereIs(p, 'e'), 'snoozed', 'e 의 묶음');
      const it = item('e');
      L.assertEq(it.state, 'snoozed', 'DB e 상태');
      const tm = L.addDays(1);
      if (!String(it.snooze_until).startsWith(`${tm} 10:00`)) throw new Error(`DB 미룬 시각 기대 ${tm} 10:00 / 실제 ${it.snooze_until}`);
      await L.assertNoViolations(p);
    });

    await r.step('6단계 「답 기다리는 중」(h) · 「팀 동료 진행 중」(i) · 「오늘 끝난 것」의 [응답 확인](f)', async () => {
      for (const k of ['waiting', 'teammate', 'done']) await p.locator(`[data-bucket="${k}"] > button`).first().click();
      await p.waitForTimeout(500);
      const fRow = p.locator(`[data-bucket="done"] [data-row="${msgId('f')}"]`);
      await fRow.waitFor({ state: 'visible', timeout: 10000 });
      if (!(await fRow.innerText()).includes('응답 확인')) throw new Error('f 줄에 [응답 확인] 없음');
      const hRow = p.locator(`[data-bucket="waiting"] [data-row="${msgId('h')}"]`);
      if (!(await hRow.isVisible())) throw new Error('h 줄이 「답 기다리는 중」에 안 보임');
      await L.assertNoViolations(p);
    });
  } finally {
    await c.close();
  }
  return r.result();
};
