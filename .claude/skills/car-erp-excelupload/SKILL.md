---
name: car-erp-excelupload
description: 헤이맨 수출차량현황표(xlsx)를 car-erp에 차량 일괄 import한다. 사용자가 "수출차량현황표 엑셀 import", "헤이맨 엑셀 업로드", "차량 일괄 등록", "vehicles:import 이어서" 같은 요청을 하거나, 수출차량현황표 xlsx 경로(특히 바탕화면 *수출차량현황표*.xlsx)를 가리킬 때 활성화. car-erp 전용.
---

# car-erp 수출차량현황표 엑셀 업로드

헤이맨 `수출차량현황표.xlsx`(= 정산 공식 출처 엑셀)를 받아 car-erp에 **차량 일괄 import**한다.
명령은 이미 구현돼 dev에 커밋됨(`app/Console/Commands/ImportVehicles.php`). 상세 맥락은 메모리 `project_vehicle_import` 참조.

## 명령

```powershell
php artisan vehicles:import "<엑셀경로>" --dry-run                 # 데이터 품질 리포트(쓰기 없음)
php artisan vehicles:import "<엑셀경로>" --with-payments --force   # 본 적재(입금+정산까지 풀 재현)
php artisan vehicles:import "<엑셀경로>" --force                   # 1단계만(마스터+재무, 입금/정산 제외)
```

- 시트 `수출차량매입-2026`, 2행=컬럼명, 3행부터 데이터. **한 행 = 차량 한 대.**
- 멱등: **차대번호(VIN) 우선 매칭**, 없으면 차량번호. 재실행 안전(중복 생성 안 함).

## 호출 절차

### 1. 파일 경로 확인
사용자가 경로를 안 주면 묻는다 (예: `C:\Users\User\Desktop\0. 헤이맨 수출차량현황표.xlsx`).

### 2. dry-run 데이터 품질 리포트
```powershell
php artisan vehicles:import "<경로>" --with-payments --dry-run
```
출력: 데이터 행 수 / 통화 분포 / 신규 담당자·바이어 / 이슈 유형별(날짜 파싱 실패·금액칸 텍스트·VIN 없음·RRN 비표준·차단 중복). **미등록 담당자가 있으면 차단** — 먼저 등록 안내.

### 3. 사용자 승인 후 본 적재
이슈 정리되고 OK 받으면 `--with-payments --force`. (입금이력 + 완료정산 paid + B/L번호→거래완료 재현)

### 4. 2중 검토 (필수)
적재 후 car-erp 재계산값을 엑셀 계산값과 대조한다. 엑셀 계산값은 `getCalculatedValue`로 읽을 것(수식 셀).
- **총마진**: car-erp `Settlement(ratio,50).total_margin` vs 엑셀 **CD**
- **정산액**: vs 엑셀 **CE** (정산비율 칸) · **실지급**: vs 엑셀 **CF/CI**
- **미수/완납**: `vehicle->sale_unpaid_amount` vs 엑셀 **BH**
실측 기준: CD/CE/CF가 ±1원(반올림) 이내면 정상. 차이 크면 매핑·데이터 점검.

### 5. 운영 반영
실제 운영 적재는 **서버 write라 read-only SSH 경계 밖** → 사용자 승인하 별도 실행. 로컬 검증 먼저, 그다음 운영(사용자 직접/승인).

## 사전 확인 (환경 점검)

1. **담당자(salesmen) 등록** — 양식 J(담당자) 이름이 `salesmen.name`과 정확히 일치해야 함. 자동 생성 안 함, **미등록이면 차단**.
   - 확정된 type: **무사백 = 사내직원(employee)**, **아트·이용빈 = 프리랜서(freelance)**. 신규 담당자는 type 지정해 먼저 등록.
2. **마이그 최신** — `php artisan migrate:status` Pending 없어야.

## 범위 / 데이터 정책

- **1단계(기본)**: 차량 마스터 + 매입(가/매도비/비용9/RRN/소유자/구입처) + 판매(판매가/통화/환율/커미션/면장 등) + 당사자.
- **2단계(`--with-payments`)**: 정산1~5(AO~AX) → confirmed 입금 + 완료정산(`type=ratio·50%·서류비5만·paid·secondary=closed`) + B/L번호 → bl_document 마커 → 거래완료.
- **과거 정산은 엑셀대로 프리50% 재현**(담당자 현재 type 무관 — 신규 정산부터 type 적용).
- 수식/계산 컬럼(R·AD·AM·BG·BH·BV·BX~CF·CI) import 제외 — car-erp 자동 계산.
- 손상값: 못 읽는 날짜→null, 금액칸 텍스트→0(경고), 부분 RRN→그대로. 차량은 전부 import, 리포트로 추적.
- **선수금/예치금(BA·BD·BE·BF) 미구현** — 해당 행은 미수 일부 차이 가능. (필요 시 후속)
- sale_date: 엑셀에 없음 → 선적일(W), 없으면 구입일(B). chk_sale_required 충족용. 불충족 시 판매 보류(매입만).

## 실무자 배포 문서

업로드용 입력 규칙(실무자에게 양식 작성 고지용):
- `docs/operations/vehicle-import-spec.md` (상세) · `vehicle-import-spec.html` (인쇄용 1장). 참고용 — 커밋 안 함.

## 적재 방식 (구현 메모)

완료·정산된 과거 데이터라 **모델 이벤트를 끈 채(withoutEvents) 최종 상태 기입 → 캐시 명시 재계산**. 일반 save면 자동 PBP phantom·중복 정산·confirmed/paid 가드와 충돌하므로 의도적 가드 우회(마이그레이션). 셀은 **계산된 값(getCalculatedValue)** 으로 읽음(입력열에도 수식 섞임).

## Cleanup (로컬 시험 후 정리)

로컬 시험 데이터를 깨끗이 하려면:
```powershell
php artisan migrate:fresh --seed     # 로컬을 깨끗한 시드 상태로 (전부 테스트 시드라 OK)
```
(타겟 삭제도 가능하나, import가 이름 매칭으로 기존 데모 바이어를 재사용하면 과삭제될 수 있어 fresh가 안전.)
