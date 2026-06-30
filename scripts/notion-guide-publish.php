<?php

/**
 * Notion "사내 업무 가이드" 부서 페이지를 HTML 가이드 내용으로 발행(교체)한다.
 *
 * 대상: 허브 "사내 업무 가이드" 하위 — 영업 / 수출통관 / 재무, 그리고 재무 밑 "관리 (통합)".
 * 동작: 각 페이지의 기존 블록을 모두 삭제(archive) 후, 가이드 내용 블록을 새로 append.
 *       "관리 (통합)"은 재무 페이지의 하위 child_page로 없으면 생성.
 *
 * 확인(dry-run):   php scripts/notion-guide-publish.php
 * 실제 발행:        php scripts/notion-guide-publish.php --apply
 */
$token = getenv('NOTION_TOKEN') ?: '여기에_토큰_붙여넣기';
$apply = in_array('--apply', $argv, true);
$only = array_values(array_intersect($argv, ['영업', '수출통관', '재무', '관리'])); // 비어있으면 전체
$HUB_TITLE = '사내 업무 가이드';
$V = '2022-06-28';
$BASE = 'https://api.notion.com/v1';

if (str_contains($token, '여기에_')) {
    fwrite(STDERR, "❌ NOTION_TOKEN 설정 필요\n");
    exit(1);
}

function notion(string $m, string $url, array $body, string $t, string $v): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$t, 'Content-Type: application/json', 'Notion-Version: '.$v],
        CURLOPT_POSTFIELDS => $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true) ?? [];
    if ($code >= 300) {
        fwrite(STDERR, "❌ Notion API ($code): ".($j['message'] ?? $res)."\n");
        exit(1);
    }

    return $j;
}

// ── rich text + block builders ──────────────────────────────
function seg(string $t, array $ann = []): array
{
    $s = ['type' => 'text', 'text' => ['content' => $t]];
    if ($ann) {
        $s['annotations'] = $ann;
    }

    return $s;
}
function tx(string $t): array
{
    return [seg($t)];
}
function h2(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_2', 'heading_2' => ['rich_text' => tx($t)]];
}
function h3(string $t): array
{
    return ['object' => 'block', 'type' => 'heading_3', 'heading_3' => ['rich_text' => tx($t)]];
}
function para(string $t): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => tx($t)]];
}
function pararich(array $segs): array
{
    return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => $segs]];
}
function num(string $t): array
{
    return ['object' => 'block', 'type' => 'numbered_list_item', 'numbered_list_item' => ['rich_text' => tx($t)]];
}
function bul(string $t): array
{
    return ['object' => 'block', 'type' => 'bulleted_list_item', 'bulleted_list_item' => ['rich_text' => tx($t)]];
}
function todo(string $t): array
{
    return ['object' => 'block', 'type' => 'to_do', 'to_do' => ['rich_text' => tx($t), 'checked' => false]];
}
function callout(string $e, string $t, string $c = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => tx($t)]];
}
function calloutrich(string $e, array $segs, string $c = 'gray_background'): array
{
    return ['object' => 'block', 'type' => 'callout', 'callout' => ['icon' => ['type' => 'emoji', 'emoji' => $e], 'color' => $c, 'rich_text' => $segs]];
}
function divider(): array
{
    return ['object' => 'block', 'type' => 'divider', 'divider' => (object) []];
}
// 채팅 메시지: 발화자(굵게)\n영어(굵게)\n한글(이탤릭/회색)
function chatmsg(string $who, string $en, string $ko, string $color): array
{
    return calloutrich($who === '바이어' ? '🧔🏽' : '👨‍💼', [
        seg($who."\n", ['bold' => true]),
        seg($en."\n", ['bold' => true]),
        seg($ko, ['italic' => true, 'color' => 'gray']),
    ], $color);
}

// ── 페이지 내용 정의 ────────────────────────────────────────
function blocks_sales(): array
{
    $b = [];
    $b[] = callout('🤝', '바이어가 채팅앱으로 문의하면 영업이 직접(영어로) 응대 → 매입보드 등록(엔카/경매) → 검차팀 현지확인·최종금액 산정 → 영업이 사진+최종금액 바이어 전달 → 경매/구매 → [관리] 카톡 전달.', 'blue_background');
    $b[] = para('※ 현재 기준(자동 AI 응대·자동 연동 도입 전). 단계마다 담당이 다릅니다.');
    $b[] = callout('🖥', '아래 매입예정·검차·경매 흐름은 별도 「매입보드(board)」 앱으로 디지털화돼 있습니다 (car-erp 원장과 분리된 시스템). 단 현재 사내 도입 준비 중 — 정식 도입 전까지는 기존 방식(엔카/경매 사이트에서 직접 진행 + 낙찰/구매확정 차량을 [관리]에게 카톡 인계 → [관리]가 car-erp 등록)을 따릅니다.', 'gray_background');
    $b[] = h2('🔄 전체 흐름');
    $b[] = bul('문의 응대(영업) → 매입예정 등록 엔카/경매(영업) → 현지확인·최종금액(검차팀) → 바이어 사진+최종금액(영업, 시간차) → 경매/구매(경매팀) → [관리] 카톡→car-erp(영업/관리)');
    $b[] = callout('⏰', '시간잠금은 경매 차량만 — 경매: 10:00 매입예정 마감·13:00 검차 마감(이후 [관리]만 수정) / 엔카: 시간 마감 없음(상시 등록·진행).', 'yellow_background');
    $b[] = divider();
    $b[] = h2('1. 채팅 문의 접수 & 1차 응대  [영업]');
    $b[] = num('채팅에 차 정보가 자동으로 옵니다 — 매물번호·차종·연식·주행거리·가격·사진. 매물번호(c_no) 메모.');
    $b[] = num('재고·기본사항 안내 + "실차 확인 후 사진과 최종금액 보내드리겠다" 안내 (가격은 현지 확인 후 확정).');
    $b[] = num('그다음 2단계(매입예정 등록)로.');
    $b[] = callout('💬', '1차 대화 (English / 한글 자막)', 'gray_background');
    $b[] = chatmsg('바이어', 'Hi SSANCAR, is this Genesis GV70 (#6797296) still available?', '안녕하세요, 이 제네시스 GV70 (#6797296) 아직 있나요?', 'gray_background');
    $b[] = chatmsg('영업', 'Hello! Let me check — the 2022 GV70 2.5T, 41,189 km, right? We will inspect the actual car and send you photos with the final price.', '안녕하세요! 확인해 드릴게요 — 2022년식 GV70 2.5T, 41,189km 맞으시죠? 실차 확인 후 사진과 최종금액을 보내드리겠습니다.', 'blue_background');
    $b[] = chatmsg('바이어', 'Great. How long will it take?', '좋아요. 얼마나 걸릴까요?', 'gray_background');
    $b[] = chatmsg('영업', 'Usually within a few hours — I will message you with photos and the final price.', '보통 몇 시간 내예요 — 사진과 최종금액으로 다시 연락드릴게요.', 'blue_background');
    $b[] = callout('✅', '매입예정 등록 + 검차팀 현지확인으로', 'green_background');
    $b[] = callout('⚠️', '"아직 있나요?"는 원본(엔카/ssancar.com) 재고 기준. 최종금액은 현지 확인 전에 확정해 주지 말 것(차 상태로 달라짐). 큰 할인은 윗선 확인.', 'yellow_background');
    $b[] = divider();
    $b[] = h2('2. 매입예정 등록 — 엔카/경매 선택  [영업]');
    $b[] = para('[+ 매입예정 추가] → 먼저 출처(엔카/경매) 선택');
    $b[] = h3('🛒 엔카 (즉시구매)');
    $b[] = bul('엔카 매물 URL / 매물번호 붙여넣기 (식별용 — 엔카 공식 API 없어 자동수집 X, 수동)');
    $b[] = bul('판매 딜러 / 지역 · 시간 마감 없음(상시)');
    $b[] = h3('🔨 경매');
    $b[] = bul('경매장 / 출품번호');
    $b[] = bul('10:00 매입예정 마감 (이후 [관리]만 수정)');
    $b[] = h3('공통 입력');
    $b[] = bul('차량번호 · 차대번호(VIN) — 필수 (등록 후 수정 불가, 중복 방지 키)');
    $b[] = bul('예상가 — 선택 (엔카/경매 매물 표시가 넣어두면 좋지만 비워도 됨. 진짜 금액은 현지 확인 후 확정)');
    $b[] = callout('⏰', '시간 마감은 경매만. 엔카는 상시. 본인 등록 차량만 본인 화면에 보임.', 'yellow_background');
    $b[] = divider();
    $b[] = h2('3. 현지 차상태 확인 & 최종금액 산정  [검차팀]');
    $b[] = num('현장 실차 상태 확인(외관·주행·사고흔적) + 외관 사진 촬영.');
    $b[] = num('차 상태 반영 현지 최종금액 산정 (예상가와 달라도 됨 — 실제 상태가 기준).');
    $b[] = num('최종금액·사진 보드 입력 → 영업에게 넘어감.');
    $b[] = callout('⚡', '현지(한국) 흥정은 실시간으로 빠르게 — 검차팀이 차 보고 그 자리에서 금액 결정.', 'gray_background');
    $b[] = callout('🛡', '촬영·공유 사진은 차량 외관/상태만. 번호판·서류(등록증·성능지·말소신청서) 제외.', 'purple_background');
    $b[] = divider();
    $b[] = h2('4. 바이어에게 사진+최종금액 전달 → 회신  [영업]');
    $b[] = num('검차팀이 올린 실차 사진 + 최종금액을 바이어에게 전달.');
    $b[] = num('바이어 회신 대기 — 수락/거절. 흥정으로 금액 바뀌면 최종금액 다시 수정.');
    $b[] = num('수락되면 5단계(경매/구매)로.');
    $b[] = callout('⏱', '2차 대화 — 바이어 응답은 몇 시간 뒤일 수 있고, 몇 시간 이어질 수 있음. "회신대기"로 두고 다른 차량 먼저 처리.', 'orange_background');
    $b[] = chatmsg('영업', '📷 Here are the actual photos. After on-site inspection: clean record, minor scratch on front bumper. Final price: 13,200,000 KRW. Shall we proceed?', '📷 실차 사진입니다. 현지 확인 결과 무사고, 앞범퍼 미세 스크래치. 최종금액 13,200,000원. 진행할까요?', 'blue_background');
    $b[] = chatmsg('바이어', 'Looks good. Yes, please proceed!', '좋네요. 네, 진행해 주세요!', 'gray_background');
    $b[] = callout('✅', '수락 → 경매/구매로 (거절이면 종료)', 'green_background');
    $b[] = divider();
    $b[] = h2('5. 경매 · 구매 (수락 차량만)  [경매팀 + 영업]');
    $b[] = h3('🔨 경매팀이 하는 일');
    $b[] = bul('바이어 수락 차량만 진입 (미수락 차단)');
    $b[] = bul('현지 최종금액으로 집행 — 경매=낙찰/유찰, 엔카=구매확정/취소');
    $b[] = bul('낙찰·구매확정 = SSANCAR 소유(우리 재고)');
    $b[] = h3('👨‍💼 영업이 하는 일');
    $b[] = bul('결과 확인 / 유찰·취소 시 바이어 안내 / 낙찰·구매확정 시 6단계');
    $b[] = divider();
    $b[] = h2('6. ★ [관리]에게 카톡 전달 (핵심 인계)  [영업 → 관리]');
    $b[] = para('[관리]에게 카카오톡으로 아래 전달 → [관리]가 car-erp 등록:');
    $b[] = todo('차량번호 / 차대번호(VIN)');
    $b[] = todo('매물번호(c_no) — 예: #6797296');
    $b[] = todo('출처 (엔카 / 경매)');
    $b[] = todo('차종 · 연식 · 주행거리');
    $b[] = todo('매입가 (현지 최종금액 = 낙찰/구매가)');
    $b[] = todo('경매장/출품번호 또는 엔카 매물번호');
    $b[] = todo('담당 영업 본인 이름');
    $b[] = todo('바이어 정보 (누가 사기로 했는지)');
    $b[] = todo('차량 사진 (외관 등)');
    $b[] = callout('⚠️', '매물번호(c_no)를 꼭 같이 — 처음 문의~거래완료까지 한 줄로 이어집니다. 누락 없이 한 번에 보내면 [관리] 재입력이 줄어듭니다.', 'red_background');
    $b[] = divider();
    $b[] = h2('📥 데이터 내려받기 (엑셀) — 신규');
    $b[] = callout('📥', 'car-erp 차량목록 상단 [내려받기]로 본인 담당 차량을 엑셀로 저장할 수 있습니다(본인 차량만 보임). 마진·정산액 등 정산 컬럼은 영업에겐 제외되고, 주민번호 등 개인정보는 자동 마스킹됩니다.', 'gray_background');
    $b[] = divider();
    $b[] = h2('👥 역할별 요약');
    $b[] = bul('👨‍💼 영업 — 채팅 응대·매입예정 등록(엔카/경매)·바이어 사진+최종금액 전달·유찰/취소 안내·[관리] 카톡');
    $b[] = bul('🔍 검차팀 — 현지 실차 검수·외관 사진·차상태 반영 최종금액 산정');
    $b[] = bul('🔨 경매팀 — 수락 차량 진입·최종금액 집행·경매(낙찰/유찰)·엔카(구매확정/취소)');
    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('현지 확인 전에 바이어에게 최종금액 확정해 줌 → 차 상태로 금액 달라짐, 신뢰 문제');
    $b[] = bul('경매 10:00 마감 넘겨 등록 못 함 (엔카는 상시)');
    $b[] = bul('VIN 누락 → car-erp 등록 시 중복·오류 (등록 후 수정 불가)');
    $b[] = bul('바이어에게 서류·번호판 사진 전송 → 금지(개인정보)');
    $b[] = bul('[관리] 카톡에 매물번호(c_no)·출처 빠뜨림 → 추적 끊김');
    $b[] = footer('영업');

    return $b;
}

function blocks_clearance(): array
{
    $b = [];
    $b[] = callout('🚢', '영업이 판매를 마친 차량을 받아 반입(선적)·수출통관·B/L 발급·DHL 발송까지 처리해 거래완료로 만드는 절차. car-erp 수출통관/선적/DHL/서류 탭에서 진행.', 'green_background');
    // ── ETA 통관서류 알람 (2026-06-18) — 옛 notion-guide-clearance-patch.php 가 in-place 삽입하던 섹션을
    //    여기로 흡수(publish.php = 단일 출처). 그 패치는 더 이상 실행하지 말 것(중복 삽입).
    $b[] = h2('🔔 통관서류 알람');
    $b[] = callout('🔔', '도착(ETA) 10일 전 수출 차량이 사이드바 「알림」 + 화면 우하단 카드에 떠요. 통관서류를 미리 준비하세요. (기본 10일, 시스템관리자가 조정 가능)', 'blue_background');
    $b[] = bul('카드/알림함 클릭 → 해당 차량 통관탭으로 이동. [확인] = "봤음" 표시. 수출신고서를 올리면 그 알람은 자동으로 사라집니다.');
    $b[] = bul('✕로 닫으면 새 알람이 올 때까지 카드가 접혀 있고(벨 숫자만), 페이지를 옮겨도 다시 안 뜹니다.');
    $b[] = bul('도착일(ETA)이 없는 차량은 알림함의 「데이터 보정」에서 날짜를 바로 입력하세요 → 채우면 도착 10일 전 알람이 자동 예약됩니다.');
    $b[] = bul('이 알람은 수출통관·관리·시스템관리자에게만 보입니다. 시스템관리자가 기능설정(alarm_enabled)에서 켜야 작동합니다.');
    $b[] = divider();
    $b[] = para('※ 수출(export) 채널 차량만 해당. 헤이맨·카풀(국내)은 통관·B/L·DHL 흐름 없음. (현재는 [관리]가 겸함)');
    $b[] = h2('🔄 전체 흐름 (car-erp v4)');
    $b[] = bul('판매완료 인수 → 반입(선적) → 통관 신청 → B/L 발급 → DHL 발송 → 거래완료');
    $b[] = h2('🚦 진행 게이트 (입금률 — 꼭 기억)');
    $b[] = callout('🟡', '입금률 50% 이상 → 반입(선적)·통관 진입 가능', 'yellow_background');
    $b[] = callout('🔴', '잔금 100% 완납 → B/L 발급 가능 (미완납은 [관리]/관리자 승인으로만 우회)', 'red_background');
    $b[] = para('※ 미입금 우회 승인은 단계별로 따로입니다 — 통관 진입(50%) / 선적 진입(50%) / B/L 발행(100%). 50% 미만 차량을 B/L까지 보내려면 선적·B/L 두 건의 승인이 각각 필요합니다.');
    $b[] = divider();
    $b[] = h2('1. 판매완료 차량 인수');
    $b[] = num('판매 등록 + 입금률 50%↑ 수출 차량을 통관 대상으로 확인');
    $b[] = num('차량 편집 패널 → 수출통관 탭');
    $b[] = h2('2. 통관 정보 입력 (수출통관 탭)');
    $b[] = bul('통관 바이어 / 컨사이니 (필수)');
    $b[] = bul('포워딩사 지정 (필수)');
    $b[] = bul('면장금액(USD) — 수출신고 금액(매출 검증용)');
    $b[] = bul('도착일자(ETA) · RORO/CONTAINER · Port of Loading');
    $b[] = bul('수출신고서 업로드');
    $b[] = h2('3. 반입(선적) 처리 (선적 B/L 탭)');
    $b[] = num('반입지(선적지) 입력 → 진행상태 "선적중"');
    $b[] = num('선적 바이어/컨사이니, 선적일, VSL(선박) 입력');
    $b[] = callout('📄', '선적 서류 — 서류 탭에서 RORO/컨테이너 Invoice&Packing·Contract 자동 생성. 여러 대는 차량목록 체크 후 일괄 생성.', 'gray_background');
    $b[] = h2('4. B/L 발급');
    $b[] = num('B/L번호, 컨테이너 No, VSL 입력');
    $b[] = num('B/L 문서 업로드 → 진행상태 "거래완료"');
    $b[] = callout('⚠️', 'B/L 발급은 잔금 100% 완납 필수. 미완납은 [관리]/관리자 승인 없이는 막힘. 임의 발급 금지.', 'red_background');
    $b[] = h2('5. DHL 발송 (DHL 탭)');
    $b[] = num('수취인·발송인 정보, 중량/크기 입력');
    $b[] = num('DHL 발송신청 체크 → 추적번호 기록(수동)');
    $b[] = h2('6. 통관 SET 서류 / 마무리');
    $b[] = callout('📄', '통관 SET — 서류 탭에서 8시트 생성(구매리스트 마스터→6시트 자동연동). 매매업등록번호 등 NICE 미제공 항목은 공란→수기.', 'gray_background');
    $b[] = num('모든 서류·B/L·DHL 완료 → 거래완료 확인');
    $b[] = divider();
    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('50% 미만인데 통관 진입 시도 → 막힘');
    $b[] = bul('100% 미완납인데 B/L 발급 시도 → 승인 없이는 불가');
    $b[] = bul('국내(헤이맨·카풀) 차량에 통관/B/L 시도 → 수출 채널만');
    $b[] = bul('포워딩사·통관 바이어 누락 → 서류 공란');
    $b[] = footer('수출통관');

    return $b;
}

function blocks_finance(): array
{
    $b = [];
    $b[] = callout('💰', '바이어 입금 확인·입력, 매입처 지급, 미수금 관리, 거래완료 차량 정산 확정까지 돈 관련 전 과정. car-erp 판매·매입 탭 + 정산 화면.', 'purple_background');
    $b[] = callout('🔐', '핵심 원칙 — 입금/지급/정산은 재무가 확정(confirmed)까지, 실제 지급(paid)은 [관리]가 승인 (직무 분리). 현재는 [관리]가 겸함.', 'yellow_background');
    $b[] = h2('🔄 전체 흐름');
    $b[] = bul('판매 입금 입력 → 매입 지급 → 미수금 관리 → 정산 확정 → 2차 정산');
    $b[] = divider();
    $b[] = h2('1. 판매 입금 입력 (판매 탭)');
    $b[] = num('바이어 송금 확인 → 그 금액 입력 (계약금/중도금/잔금 N건/선수금)');
    $b[] = num('외화 차량은 통화·환율 반드시 함께 (환율 0이면 미수금 계산이 깨짐)');
    $b[] = num('입금 상태 자동 갱신 — 완납 / 부분입금(%) / 미입금');
    $b[] = callout('💡', '입금액 입력 = 곧 확정. 입금률 50%↑ → 통관·선적, 100% 완납 → B/L 발급 가능(수출통관과 연결).', 'purple_background');
    $b[] = callout('⚠️', '외화차에 환율 미입력 → 미수금이 0으로 잘못 잡혀 완납 오판. 판매가 입력 시 통화·환율·판매일·바이어 항상 함께.', 'red_background');
    $b[] = h2('2. 매입 지급 처리 (매입 탭)');
    $b[] = num('매입가·매도비 확인, 계약금/잔금(N건) 지급 입력');
    $b[] = num('지급일 도래 건만 미지급에서 차감(미래 예정 건 제외)');
    $b[] = num('매입처 계좌 등 민감정보는 권한 범위 내에서만');
    $b[] = h2('3. 미수금 · 채권 관리');
    $b[] = num('미입금·부분입금 차량 모니터링 (담당자별·바이어별 TOP10)');
    $b[] = num('환율 미입력 외화 차량은 별도 경고로 분리 → 환율 채우기');
    $b[] = num('정산 완료(paid) 차량에 남은 미수(예: 운임비·환차 잔액)는 채권관리에서 회수 이력으로 정리');
    $b[] = callout('💡', '정산이 완료(paid)된 차량은 회수 방식을 "현금/상계/기타/손실"로 입력해야 미수에서 차감됩니다. "입금(전액입금)" 방식은 완료 정산 보호로 막혀 미반영되니 사용 금지. USD 계약이라도 한화로 받은 잔액은 차량 통화(USD) 금액으로 입력하면 원화 환산은 자동.', 'purple_background');
    $b[] = h3('회수 방식 5종 — 언제 무엇을 고르나');
    $b[] = para('차량을 선택하고 "회수 이력 추가"에서 방식을 고릅니다. 5종 모두 미수를 차감하지만 의미·기록이 다릅니다.');
    $b[] = bul('입금 — 실제 입금받아 정상 잔금으로 처리. 잔금(final_payment)이 자동 생성됨. ※ 정산 완료(paid) 차량엔 사용 불가(보호).');
    $b[] = bul('현금 — 현금으로 직접 받았을 때. 잔금은 안 만들고 미수만 차감.');
    $b[] = bul('상계 — 우리가 그 바이어에게 줄 돈(적립금·환불·다른 거래 대금 등)과 받을 미수를 맞까서 정리. 현금이 오가지 않고 서로 빚을 상쇄. (set-off)');
    $b[] = bul('기타 — 위 셋에 안 맞는 실제 수령·단순 정리(반올림 오차 등). 차량 기록만 남고 바이어 누적엔 안 잡힘.');
    $b[] = bul('손실(셀러부담) — 못 받고 우리가 떠안는 금액(예: 바이어가 송금수수료·운임을 빼고 입금). 미수는 차감하되, 우리가 떠안았다는 사실을 바이어별로 누적.');
    $b[] = callout('🗡️', '바이어 편집 패널의 "누적 셀러부담액" 카드 = 판매 탭의 송금수수료(셀러부담) + 채권관리의 손실(셀러부담)을 합산해 보여줍니다. "당신 때문에 우리가 이만큼 떠안았다, 입금 잘해달라"는 영업 협상 카드로 쓰세요. ※ 정상 수령한 "기타"는 여기 안 들어갑니다(숫자 정확성).', 'purple_background');
    $b[] = callout('📍', '구분 기준 한 줄 — 받은 것=현금/상계/기타, 못 받고 떠안은 것=손실. 손해를 "기타"로 지우면 바이어 누적에 안 잡혀 협상 근거가 사라지니, 손해는 꼭 "손실"로.', 'yellow_background');
    $b[] = h2('4. 정산 확정');
    $b[] = para('정산 상태: pending(자동 생성) → confirmed(재무 확정) → paid([관리] 지급)');
    $b[] = num('거래완료 시 정산 pending 자동 생성 (마진·정산액 자동 계산)');
    $b[] = callout('🧮', '정산 방식 (2026-06-22 차등 적용) — ① 프리랜서(비율제): 총마진 × 50%(기본). ② 사내직원(건당 정액): 매입가 1억 이상이면 총마진 × 25%(손해 차량은 0), 1억 미만이면 총마진 100만 미만은 10만 원 · 100만 이상은 20만 원. 본인 유형(프리랜서/사내직원)은 [관리]에 확인. 위 비율·금액은 시스템관리자 기능설정에서 조정 가능.', 'gray_background');
    $b[] = num('금액 검토 후 confirmed(확정) — 재무 담당');
    $b[] = callout('🔐', '재무는 paid(지급)를 직접 못 함. 확정까지가 재무, 지급은 [관리]/관리자 승인. 본인 요청·본인 지급 차단.', 'yellow_background');
    $b[] = h2('5. 2차 정산 (사후 보정)');
    $b[] = num('지급 후 한 달간 실측 비용 보정 (말소·탁송·쇼링·보험 등 9개)');
    $b[] = num('정산 시점 환율 정정(환차 반영)');
    $b[] = num('"2차 완료" → closed(회계 잠금), 환차·이월 자동 계산');
    $b[] = callout('⚠️', 'confirmed/paid 이후 회계 데이터는 함부로 못 바꿈(스냅샷 보존). 비용 보정은 2차 정산 절차 안에서만. paid·confirmed·2차완료(closed) 정산은 삭제 자체가 차단됩니다(감사추적 보존) — 삭제 가능한 건 대기(pending)뿐.', 'red_background');
    $b[] = h2('📥 데이터 내려받기 (엑셀) — 신규');
    $b[] = num('차량목록 상단 [내려받기] → 범위(현재 필터 결과 / 전체) 선택 + 팝오버에서 컬럼 선택 → 엑셀 저장.');
    $b[] = callout('💰', '재무·관리는 마진·정산액·실지급 등 정산 컬럼까지 포함됩니다(영업·통관은 정산 컬럼 제외). 주민번호 등 개인정보는 자동 마스킹(예: 880717-*******).', 'purple_background');
    $b[] = divider();
    $b[] = h2('⚠️ 자주 하는 실수');
    $b[] = bul('외화 환율 미입력 → 완납 오판');
    $b[] = bul('재무가 paid 직접 시도 → 불가([관리] 승인)');
    $b[] = bul('완료(paid) 차량 미수 정리에 "입금(전액입금)" 방식 사용 → 막힘. "현금/상계/기타/손실"로 입력');
    $b[] = bul('우리가 떠안은 손해를 "기타"로 지움 → 바이어 누적에 안 잡혀 협상 근거 소실. 손해는 "손실"로 입력');
    $b[] = bul('지급일 미래인 매입 잔금을 미지급으로 오인');
    $b[] = bul('확정 후 회계 컬럼 임의 수정 → 2차 정산 절차로만');
    $b[] = footer('재무');

    return $b;
}

function linkpage(string $pid): array
{
    return ['object' => 'block', 'type' => 'link_to_page', 'link_to_page' => ['type' => 'page_id', 'page_id' => $pid]];
}

function blocks_manager(?string $finId = null, ?string $cleId = null): array
{
    $b = [];
    $b[] = callout('📌', '이 가이드는 "지금 현실" 버전 — 역할 분리(영업/수출통관/재무) 전이라 [관리] 한 사람이 차량 등록 + 재무 + 수출통관까지 전부 처리. 아래 B·C·D는 각 부서 가이드와 같은 내용이며, 바로 가는 링크를 함께 둡니다.', 'purple_background');
    $b[] = h2('🔄 통합 흐름');
    $b[] = bul('차량 등록(관리) → 판매·입금(재무) → 반입·선적(통관) → 통관·B/L(통관) → DHL(통관) → 정산(재무)');
    $b[] = divider();
    $b[] = h2('A. 영업 카톡 → car-erp 차량 등록  [관리 고유]');
    $b[] = num('영업이 카톡으로 보낸 차량 사진·정보 수신 (차량번호·VIN·매물번호 c_no·출처(엔카/경매)·매입가·바이어)');
    $b[] = num('car-erp 신규 차량 등록 — 기본정보·매입 입력, 사진 첨부. 출처가 엔카면 엔카 매물번호도 기록.');
    $b[] = num('매물번호(c_no)도 함께 기록 (추적용)');
    $b[] = callout('ℹ️', '향후 연동 B가 되면 이 카톡·수동등록이 자동화됩니다.', 'gray_background');
    $b[] = divider();
    $b[] = h2('B. 판매 등록 · 입금 처리');
    $b[] = num('판매가·통화·환율·바이어 입력 (외화는 환율 필수)');
    $b[] = num('입금 확인 → 그 금액 입력 = 자동 확정 (계약금/중도금/잔금)');
    $b[] = callout('🚦', '50% 이상 → 반입·통관 가능 / 100% 완납 → B/L 발급 가능', 'yellow_background');
    if ($finId) {
        $b[] = para('👉 상세 절차는 재무 가이드에서:');
        $b[] = linkpage($finId);
    }
    $b[] = divider();
    $b[] = h2('C. 반입 · 통관 · B/L · DHL');
    $b[] = num('수출통관 탭 — 통관 바이어/컨사이니, 포워딩사, 면장금액, 수출신고서');
    $b[] = num('선적(B/L) 탭 — 반입지·선적일·VSL → 선적 서류 생성');
    $b[] = num('100% 완납 확인 후 B/L 발급 → 거래완료');
    $b[] = num('DHL 탭 — 수취인 정보·발송신청');
    $b[] = callout('⚠️', 'B/L은 100% 완납 필수. 미완납 우회 승인은 단계별로 따로입니다 — 통관 진입(50%)/선적 진입(50%)/B/L 발행(100%). 50% 미만 차량을 B/L까지 보내려면 선적·B/L 두 건의 승인이 각각 필요. 본인(관리) 권한으로 승인 가능하나 신중히.', 'red_background');
    $b[] = callout('🔔', '통관 알람 — 도착(ETA) 10일 전 통관서류 준비 알람이 관리에게도 뜹니다(시스템관리자가 기능설정에서 켠 경우). 상세는 수출통관 가이드.', 'blue_background');
    if ($cleId) {
        $b[] = para('👉 상세 절차는 수출통관 가이드에서:');
        $b[] = linkpage($cleId);
    }
    $b[] = divider();
    $b[] = h2('D. 정산 확정 · 지급 · 2차 정산');
    $b[] = num('거래완료 시 정산 자동 생성(pending) → 검토 후 확정(confirmed)');
    $b[] = callout('🧮', '정산 방식 (2026-06-22 차등) — 프리랜서(비율제): 총마진 × 50%. 사내직원(건당 정액): 매입가 1억 이상이면 총마진 × 25%(손해는 0), 1억 미만이면 총마진 100만 미만 10만 원 / 100만 이상 20만 원. 비율·금액은 시스템관리자 기능설정에서 조정.', 'gray_background');
    $b[] = num('지급(paid) 처리 — 현재는 [관리] 직접 (분리되면 재무 확정/관리 지급)');
    $b[] = num('지급 후 한 달 내 2차 정산 — 실측 비용·환차 정정 → closed');
    if ($finId) {
        $b[] = para('👉 상세 절차는 재무 가이드에서:');
        $b[] = linkpage($finId);
    }
    $b[] = divider();
    $b[] = h2('E. 관리 고유 권한 (분리돼도 관리 몫)');
    $b[] = bul('정산 지급(paid) 승인 — 재무 확정 건을 지급 처리');
    $b[] = bul('게이트 우회 승인 — 미완납 B/L 발급 등 예외 승인');
    $b[] = bul('마감 해제·삭제 등 민감 액션 (감사로그 기록). 단 paid·확정·2차완료 정산은 삭제 자체가 차단 — 삭제는 대기(pending) 건만 가능');
    $b[] = bul('데이터 내려받기(엑셀) — 본인 팀 차량을 마진·정산액·실지급 등 정산 컬럼까지 포함해 저장(개인정보 자동 마스킹). 대량적재 표준 양식 다운로드는 admin 전용(마이그 도구).');
    $b[] = divider();
    $b[] = h2('⚠️ 통합 운영 핵심 주의');
    $b[] = bul('등록 시 VIN·매물번호(c_no) 누락 금지');
    $b[] = bul('외화차 환율 항상 입력 — 미수금 오판 방지');
    $b[] = bul('입금률 게이트(50%/100%) 확인 후 통관·B/L');
    $b[] = bul('한 사람이 다 해도 확정→지급 순서·스냅샷 잠금 흐름 유지(분리 대비)');
    $b[] = footer('관리(통합)');

    return $b;
}

function footer(string $dept): array
{
    return callout('🕒', "SSANCAR 사내 업무 가이드 · $dept · 2026-06-30 갱신 (자동 발행). 이 아래에 running log(매일 1~2줄)를 쌓으세요.", 'gray_background');
}

// ── 페이지 트리 탐색 ────────────────────────────────────────
function childPages(string $pid, string $t, string $v): array
{
    $out = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                $out[$blk['child_page']['title']] = $blk['id'];
            }
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);

    return $out;
}
function clearBlocks(string $pid, string $t, string $v, bool $apply): int
{
    $ids = [];
    $cursor = null;
    do {
        $url = "https://api.notion.com/v1/blocks/$pid/children?page_size=100".($cursor ? "&start_cursor=$cursor" : '');
        $r = notion('GET', $url, [], $t, $v);
        foreach ($r['results'] as $blk) {
            if (($blk['type'] ?? '') === 'child_page') {
                continue;
            } // 하위 페이지(관리 등)는 보존
            $ids[] = $blk['id'];
        }
        $cursor = $r['has_more'] ? $r['next_cursor'] : null;
    } while ($cursor);
    if ($apply) {
        foreach ($ids as $id) {
            notion('DELETE', "https://api.notion.com/v1/blocks/$id", [], $t, $v);
        }
    }

    return count($ids);
}
function appendBlocks(string $pid, array $blocks, string $t, string $v): void
{
    foreach (array_chunk($blocks, 90) as $chunk) {
        notion('PATCH', "https://api.notion.com/v1/blocks/$pid/children", ['children' => $chunk], $t, $v);
    }
}

// ── 1. 허브 찾기 ────────────────────────────────────────────
echo "▶ '$HUB_TITLE' 허브 검색...\n";
$ps = notion('POST', "$BASE/search", ['query' => $HUB_TITLE, 'filter' => ['property' => 'object', 'value' => 'page']], $token, $V);
$hubId = null;
foreach ($ps['results'] as $p) {
    foreach (($p['properties'] ?? []) as $prop) {
        if (($prop['type'] ?? '') === 'title' && ($prop['title'][0]['plain_text'] ?? '') === $HUB_TITLE) {
            $hubId = $p['id'];
            break 2;
        }
    }
}
if (! $hubId) {
    fwrite(STDERR, "❌ 허브 '$HUB_TITLE' 없음. notion-onboarding.php 먼저 실행하세요.\n");
    exit(1);
}
echo "   • 허브 id=$hubId\n";

$kids = childPages($hubId, $token, $V);
echo '▶ 허브 하위 페이지: '.implode(', ', array_keys($kids))."\n";

$financeId = $kids['재무'] ?? null;
$mgrId = null;
if ($financeId) {
    $finKids = childPages($financeId, $token, $V);
    foreach ($finKids as $title => $id) {
        if (str_starts_with($title, '관리')) {
            $mgrId = $id;
            break;
        }
    }
}

// ── 2. 발행 계획 ────────────────────────────────────────────
$targets = [
    '영업' => ['id' => $kids['영업'] ?? null,     'fn' => 'blocks_sales'],
    '수출통관' => ['id' => $kids['수출통관'] ?? null, 'fn' => 'blocks_clearance'],
    '재무' => ['id' => $financeId,                'fn' => 'blocks_finance'],
];

echo "\n▶ ".($apply ? "발행 시작...\n" : "[확인 모드] 발행 계획 (실제 변경 X):\n");
foreach ($targets as $name => $cfg) {
    if ($only && ! in_array($name, $only, true)) {
        continue;
    } // 지정 부서만
    if (! $cfg['id']) {
        echo "   ⚠ $name — 페이지 없음(건너뜀)\n";

        continue;
    }
    $blocks = $cfg['fn']();
    $del = clearBlocks($cfg['id'], $token, $V, $apply);
    if ($apply) {
        appendBlocks($cfg['id'], $blocks, $token, $V);
        echo "   ✔ $name — 기존 {$del}블록 삭제 + ".count($blocks)."블록 발행\n";
    } else {
        echo "   + $name — 기존 {$del}블록 삭제 예정 + ".count($blocks)."블록 발행 예정\n";
    }
}

// ── 3. 관리(통합) — 허브 직속 (재무 하위 아님, 같은 레벨) ─────
if (! $only || in_array('관리', $only, true)) {
    $mgrBlocks = blocks_manager($financeId, $kids['수출통관'] ?? null);

    // 3a. 재무 하위에 남은 옛 관리 페이지가 있으면 제거(archive) — 이동 효과
    if ($financeId) {
        foreach (childPages($financeId, $token, $V) as $title => $id) {
            if (str_starts_with($title, '관리')) {
                if ($apply) {
                    notion('DELETE', "https://api.notion.com/v1/blocks/$id", [], $token, $V);
                    echo "   ✔ (이동) 재무 하위 옛 '관리' 페이지 제거\n";
                } else {
                    echo "   + (이동) 재무 하위 옛 '관리' 페이지 제거 예정\n";
                }
            }
        }
    }

    // 3b. 허브 직속에 관리 페이지: 있으면 내용 갱신, 없으면 생성
    $mgrHubId = null;
    foreach (childPages($hubId, $token, $V) as $title => $id) {
        if (str_starts_with($title, '관리')) {
            $mgrHubId = $id;
            break;
        }
    }
    if ($mgrHubId) {
        $del = clearBlocks($mgrHubId, $token, $V, $apply);
        if ($apply) {
            appendBlocks($mgrHubId, $mgrBlocks, $token, $V);
            echo "   ✔ 관리(통합) — 허브 직속, 기존 {$del}블록 삭제 + ".count($mgrBlocks)."블록 발행\n";
        } else {
            echo "   + 관리(통합) — 허브 직속 갱신 예정\n";
        }
    } else {
        if ($apply) {
            $pg = notion('POST', "$BASE/pages", [
                'parent' => ['type' => 'page_id', 'page_id' => $hubId],
                'icon' => ['type' => 'emoji', 'emoji' => '🗂️'],
                'properties' => ['title' => ['title' => tx('관리 (통합)')]],
                'children' => array_slice($mgrBlocks, 0, 90),
            ], $token, $V);
            if (count($mgrBlocks) > 90) {
                appendBlocks($pg['id'], array_slice($mgrBlocks, 90), $token, $V);
            }
            echo "   ✔ 관리(통합) — 허브 직속에 신규 생성\n";
        } else {
            echo "   + 관리(통합) — 허브 직속에 신규 생성 예정\n";
        }
    }
}

echo "\n".($apply ? "✅ 발행 완료.\n" : "ℹ️  확인만 했습니다. 실제 발행:  php scripts/notion-guide-publish.php --apply\n");
