# 연동 B v3 — 운영 테스트 씬 (2026-06-23 배포 후)

> **트리거**: "v3 운영 테스트 이어서" / "board 연동 테스트". 새 세션이 이 문서 보고 운영(`https://heysellcar.com`)에서 검증할 씬 정리.
> **배포 상태**: car-erp master `ad1c8b3` 배포 완료(코드 13파일, docs/integration .md 제외). dev=`67a4ca5`. 703 테스트 통과.
> **권위 스펙**: `docs/integration/purchase-sync-receiver.md`(v3 섹션) · `docs/integration/board-portal-api.md`(buyers/consignees). 인계 원본=board `meetings/handoff-car-erp-amount-mapping.md`.

## ⚠️ 선행조건 (테스트 전 확인)
1. **board 발신측 v3 전환 여부** — board 가 아직 v1/v2 송신 중이면 씬 1~3·5는 발화 안 함(car-erp 는 전방호환이라 v1/v2 정상 수신). board 세션이 ① payload v3 송신(SyncWonListingToCarErp) ② `transport_fee` 판매통화 환산 ③ 드로어 드롭다운 배선 + board env(`CAR_ERP_BASE_URL`·HMAC 시크릿) 세팅해야 실제 흐름 테스트 가능.
2. **🔴 board 에 전달 필수**: `GET /board/buyers` = **영업 본인 스코프**(인계문서 "전체 허용 권장"과 다름, jin 결정). board 는 salesman_email 본인 바이어만 받음 → 타 영업 바이어는 car-erp 에서 수동.
3. **도착 알람 라이브** — `alarm_enabled` 가 운영 ON 이라, board v3(또는 현 v1/v2) won push 로 신규 차량 생성되는 순간부터 [관리]/admin 에 도착 알람 발화(백필 없음, 신규만).

## 운영 테스트 씬

### 씬 1 — v3 금액 자동기입 (board v3 won push)
- **방법**: board 에서 낙찰차 won → purchase-sync v3 전송. car-erp 차량 편집 패널 열기.
- **기대**: 매입탭 `purchase_price`=구입금액만(매도비·배송 제외, final_price 부풀림 아님) / `selling_fee`=매도비 / `transport_fee`=운임비. 바이어·컨사이니 드롭다운 자동 선택됨.
- **검증 SQL**: `Vehicle::where('vehicle_number',?)->first()` 로 purchase_price·selling_fee·transport_fee·buyer_id·consignee_id 확인.

### 씬 2 — 운임비 통화 (EUR 판매차 미수금 정상) ⭐ 버그수정 핵심
- **방법**: 판매통화 EUR 차량에 board 가 `transport_fee`(EUR 환산값) 전송.
- **기대**: `sale_total_amount = sale_price + transport_fee + …` 가 EUR 기준으로 맞고, 미수금/판매합계가 **부풀지 않음**(과거 USD raw × EUR환율 = ~16% 부풀던 버그 해소). 채권관리 KPI·미납 게이지 정상.

### 씬 3 — 판매 pre-fill (판매중 전환 / 환율 누락 보류)
- **3a 환율 동반**: board 가 sale_price + sale_exchange_rate 둘 다 전송 → 차량 `판매중` 전환, sale_date=수신일 자동. 관리가 지정시점 환율로 덮어쓰기 가능(편집 필드 유지).
- **3b 환율 누락**: sale_price 만 오고 환율 없음 → sale 필드 **보류**(`매입중` 유지), currency 힌트만 보존. (chk_sale_required INSERT 실패 방지)

### 씬 4 — 드로어 드롭다운 (buyers/consignees 엔드포인트)
- **방법**: board 경매/구매 드로어에서 바이어 드롭다운 열기(HMAC GET `/board/buyers?salesman_email=`), 바이어 선택 후 컨사이니 드롭다운(`/board/consignees?buyer_id=`).
- **기대**: 해당 영업 **본인 바이어만** 표시(활성). 컨사이니는 선택 바이어 하위 활성만. 응답에 연락처·주소 등 PII 없음.
- **⚠️ 사전 확인**: 운영 바이어의 `salesman_id` 채움 비율 — 미지정 많으면 드롭다운 빔. `Buyer::whereNull('salesman_id')->count()` vs 전체. (로컬은 16/17 채워짐.) 비면 jin 과 재논의(본인스코프 유지 vs salesman_id null fallback).

### 씬 5 — 매입 도착 알람 (purchase_arrival)
- **방법**: board won push 로 신규 차량 생성.
- **기대**: [관리]/admin 우하단 상주카드 + 사이드바 벨에 **NEW 뱃지 "신규 매입차 도착 · 계약금 진행"**(파란 테두리). 카드 클릭 → 차량 통관탭. 
- **해소 5a**: 매입 계약금(PBP type=down) 입력 → 알람 자동 사라짐(resolved_reason=down_payment).
- **해소 5b**: 수동 [확인] 클릭 → 사라짐(resolved_reason=manual_confirm).
- **가시성**: 수출통관·영업 role 에겐 안 보임(관리/admin 만).

### 씬 6 — 회귀 (기존 기능 안 깨짐)
- 기존 **수출통관 ETA 알람**(eta_clearance) 정상 표시·확인(도착 알람 추가로 가시성 안 깨짐).
- 기존 **v1/v2 purchase-sync** 정상 수신(board 미전환 상태에서도 차량 생성).
- 기존 board 재무 읽기 API(receivables·finance 등) 정상.

## 남은 board 측 작업 (car-erp 아님)
- payload v3 송신 + transport_fee 판매통화 환산 + 드로어 드롭다운 배선 + board env 세팅.
- board 문서(`meetings/board-carerp-amount-mapping.md`)에 응답키 역링크.
