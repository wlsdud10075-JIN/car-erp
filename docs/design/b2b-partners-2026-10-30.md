# B2B 파트너스 — 바이어 계약서·관리대상·DP/FD 뱃지 (1차 범위)

> 📄 원본 요청서 = `C:\Users\User\Desktop\ssancarerpDB\SSANCAR_ERP_B2B_Partners_Plan.html`(강지나 부장, 2026-10-29, 2.1MB — 참고 이미지 1장 내장).
> 🧭 이 문서 = 2026-10-30 Claude 검토 결과 + 착수 설계. **주말(2026-11-01~02) 작업 진입점.** jin 결정 3건(§4)은 착수 때 묻는다.
> 상태: ⏳ 착수 대기(코드 0줄). 메모리 = `project_b2b_partners_badges`.

## 1. 요청서 요약 (08번 「개발팀 전달 문구」 기준)

1. **B2B 파트너스 메뉴** 신설 — 바이어별 **특수계약 관리대상(예/아니오)** 지정 · **계약서 업로드**(기본약정서 + 추가약정서 복수, 교체 시 원본 보관) · **관리대상만 모아보기**(차량·미수 없어도 표시).
2. **차량관리·채권관리**에서 차종명 옆에 **DP(Delay Payment, 보라) / FD(First Deal, 파랑)** 약자 뱃지 + 호버 전체 명칭 + **DP/FD 필터**. 새 컬럼 없음. 권한 있는 담당자가 **거래별(차량별)** 지정 — 바이어 지정만으로 전 차량 자동 뱃지 ❌. 재판매 시 과거 표시 상속 ❌.
3. **제외**: 승인시스템·선금/잔금 자동계산·지급기일·알림·연체 차단·한도 심사·보험·별도 미수 집계. 기존 수치 무변경. 04 참고 이미지의 요약 카드·약정번호·적용기간·연체여부 칸은 **범위 밖**(문서 스스로 명시).
4. 완료 기준(07): 관리대상만 조회 · 복수 업로드/권한 열람 · 지정 차량에만 뱃지 · 두 화면 일치 · 필터 동작 · 미수·승인상태 무변경.

## 2. 기존 코드 대조 (2026-10-30 실측)

| 요청 | 기존 | 판단 |
|---|---|---|
| 바이어 계약서 업로드 | ❌ **바이어 첨부 기능 없음**. `VehiclePhoto`(`vehicle_photos`: vehicle_id·path·category·sort_order)는 차량 전용. `BuyerDocumentController` 는 서명 링크 **전달**(말소등록증·사업자등록증) 전용 | **새 테이블 `buyer_documents`**. 업로드 방식(`WithFileUploads`)·디스크(`vehicle_docs_disk`)·URL(`VehicleDocUrl::for`)·상한(30건)은 차량 첨부 것을 복제 |
| 관리대상 지정 | ❌ | `buyers.is_b2b_partner`(bool, default 0) — 바이어 패널 「내수」 체크(`buyers/index.blade.php:59 is_domestic`) 옆 |
| 모아보기 메뉴 | 바이어 관리 + 담당자 필터(`salesmanFilter`) 있음 | 사이드바 「고객관리」 그룹(`sidebar.blade.php:212~`)에 항목 추가, 화면은 바이어 관리 + 관리대상 필터(§4 ③) |
| 차종 옆 뱃지 | 칩 패턴 있음(`vehicles/index.blade.php:8585~8630` — 잔금·보증금·바이어미정·내수·board 신호). `bg-purple-100/text-purple-700`·`bg-blue-100/text-blue-700` **빌드 CSS 에 있음**(board 칩이 씀) | `vehicles.deal_type` **string(2) nullable** — 🚫 enum 금지(SKILLS §8 #36) |
| 필터 | 차량관리 `#[Url]` 셀렉트 구조(`cancelFilter` :8117) / 채권관리 `riskFilter` 셀렉트(:1428) | 셀렉트 1개씩. 엑셀 내보내기(`VehicleExportController`)에도 미러 — 차량관리 필터는 내보내기에 따라가는 규칙(SKILLS §9) |
| 정렬 | 차량관리 `SORTABLE_COLUMNS`(:1121)/`SORT_EXPRESSIONS`(:1150) · 채권관리 `SORTABLE`(:62) | 요청서는 **필터만**. 정렬은 안 넣는다(세 값이라 가치 낮음). 바이어 관리는 「관리대상 먼저」 정도 |
| 열람 로그 | `document_access_logs.vehicle_id` **NOT NULL FK** — 바이어 문서에 못 쓴다 | `AuditLog` 액션 행(`b2b_contract_read`, 10-29 `board_buyer_contact_read` 와 같은 패턴). `config/column_labels.php actions` 에 라벨 필수(`PaymentRowAuditTest` 정적 스캔) |
| 변경 이력 | 차량 = `AUDITED_COLUMNS` 자동 / 바이어 = 훅 없음 | `deal_type` 을 `Vehicle::AUDITED_COLUMNS` + `column_labels.vehicles` 에. `is_b2b_partner` 는 `Buyer.php:84` 정산방식처럼 `AuditLog::recordChange` 명시 |
| 권한 | 「지정 담당자」에 맞는 기존 개념 = **관리 이상**(`approvalRank() ≥ 1`, 메모리 `feedback_manager_and_above`). 영업은 `canEditVehicleFinancialFields` 에 들어 있음 | 지정·업로드 = 관리 이상(§4 결정 곁다리: 영업 포함 여부) |

**요청서 모순 1개** — 02 는 바이어 목록에 「DP/FD 적용 여부」 열·필터, 03 은 「차량별 지정」. 둘 다 저장하면 출처가 둘. ⇒ 바이어 쪽은 **차량에서 센 개수**(DP n · FD n)만 표시하고 필터도 그 개수로.

## 3. 뱃지 렌더 자리 (「차종명 옆」 5곳)

| 화면 | 파일:줄 | 비고 |
|---|---|---|
| 차량관리 데스크톱 | `vehicles/index.blade.php:8632` `brand_model` 셀 | ⚠️ **표시컬럼이라 사용자가 끌 수 있다** → 끄면 뱃지도 사라짐(§4 ①) |
| 차량관리 모바일 카드 | `:8919` | |
| 채권관리 데스크톱 | `receivables/index.blade.php:1541` | |
| 채권관리 이력 패널 머리 | `:1663` | |
| 채권관리 모바일 카드 | `:1582~` | 차종을 안 찍는다 → 차량번호 옆 |

- 색: DP `bg-purple-100 text-purple-700` / FD `bg-blue-100 text-blue-700`(요청서 보라·파랑). ⚠️ board 신호 칩(보라)·판매단계 `badge-purple` 과 색이 겹침 — 차종 셀에 두면 칩 줄과 떨어져 구분됨.
- 호버 = `title` 로 「Delay Payment(지연 결제)」/「First Deal(선수금 거래)」. 필터는 「DP만 / FD만 / 뱃지 없음」.

**안 넣는 곳**: 대시보드 단계 스트립 · 재고관리 · 선적요청 · board/ssancar 포털 API(요청서 05 「신규 메뉴 이유로 제한정보 공개 금지」 — `InternalPortalController` 바이어 행에 플래그 싣지 말 것) · 알림톡.

## 4. jin 결정 3건 (착수 때 묻는다)

| # | 질문 | 선택지 | 추천 |
|---|---|---|---|
| ① | 뱃지 위치 | (가) 차종 옆(요청서) — 컬럼 끄면 같이 사라짐 / (나) 차량번호 칩 줄 — 항상 보이나 5종 칩과 섞이고 색 겹침 | **(가)** + 「끄면 안 보인다」 안내 |
| ② | 바이어가 바뀌면 뱃지는? (요청서 03 「재판매 시 물려주지 않음」) | (가) `buyer_id` 변경 시 자동 해제 + 감사 1줄(`Vehicle.php:972` 바이어미정 플래그와 같은 자리) / (나) 사람이 지움 | **(가)** |
| ③ | B2B 파트너스 메뉴 형태 | (가) 메뉴 항목 = 바이어 관리를 「관리대상만」 필터로 여는 링크 / (나) 참고 이미지 같은 별도 화면 | **(가)** — 요청서 06 제외 범위와 일치. (나)는 04 가 스스로 범위 밖이라 한 요약 카드·약정번호가 필요해짐 |
| 곁 | 지정 권한 | 관리 이상만 / 영업 포함 | 관리 이상 |

## 5. 착수 순서 (약 1~1.5일, 결정 ①③ 확정 후)

1. **마이그 1개**(3-DB 안전, 전부 nullable/default): `buyers.is_b2b_partner` bool default 0 · `vehicles.deal_type` string(2) nullable index · `buyer_documents`(id, buyer_id FK cascade, path, kind string(20) 'base'|'extra', note string(200) nullable, uploaded_by FK users nullable, timestamps). 삭제 대신 보관이면 `superseded_at` nullable.
2. **모델**: `BuyerDocument`(url/filename/extension/is_image 는 `VehiclePhoto` 복제) · `Buyer` fillable+cast+`documents()` · `Vehicle` fillable + `AUDITED_COLUMNS` + `scopeDealType()` · `DEAL_TYPES = ['DP' => …, 'FD' => …]` 단일 출처(필터·뱃지·검증이 같이 봄).
3. **바이어 패널**(`buyers/index.blade.php`): 「특수계약 관리대상」 체크(내수 옆) + 「계약서」 섹션(복수 업로드·목록·열기·종류/메모) — 관리 이상만. 목록에 관리대상 필터 + DP/FD 개수 열. `AuditLog::recordChange` 로 플래그·업로드·열람 기록.
4. **차량 패널 판매 탭**: 「거래 유형」 셀렉트(없음/DP/FD) — 관리 이상만 편집(그 외 읽기). 저장 배열·rules(`in:DP,FD`)·resetForm 기본값. ⚠️ 「읽기는 하는데 저장 배열에 안 실린」 함정(§8 #94).
5. **뱃지 5곳 + 필터 2곳 + 내보내기 미러**(§3). 필터는 `#[Url(as:'deal')] string $dealFilter`.
6. **사이드바** 고객관리 그룹에 「B2B 파트너스」 → `route('erp.buyers.index', ['b2b' => 1])`. `nav.php` ko/en.
7. **lang ko/en**(`LocaleKeyParityTest`) · `column_labels.php`(vehicles `deal_type`, actions `b2b_contract_read`·`b2b_contract_uploaded`) · 가이드 소스(`cards.json` 바이어 카드 + `notion-guide-publish.php` 관리 가이드) 같은 커밋.
8. **테스트**: 뱃지 렌더(지정 차만·두 화면 일치·호버 문구) · 필터 · 바이어 변경 시 해제(②) · 업로드/열람 권한(관리 외 403) · 감사 행 · `BoardPortalVehicleMetaTest`(포털 행에 안 실림) · 검색칸 추가 시 `SearchRequiresButtonTest`.

**재사용 가능 ≈** 칩·필터/정렬 뼈대·바이어 패널·업로드 트레이트·S3 디스크·AuditLog. **새로 ≈** 테이블 1 + 컬럼 2, 업로드 UI, 뱃지 5곳, 필터 2, 메뉴, 테스트, lang, 카드.

## 6. 주의

- 🚫 `deal_type` 을 enum 으로 만들지 말 것 — SQLite·CI 통과하고 운영 MySQL 에서만 죽는다(§8 #36). 값 검증은 코드 상수.
- 🚫 바이어 레벨에 DP/FD 플래그를 따로 저장하지 말 것(출처 둘).
- 🚫 포털/board 응답에 플래그·계약서 노출 금지(요청서 05).
- 새 색 클래스를 쓰면 빌드 CSS 확인(§8 #50) — 위 두 색은 이미 있다.
- 바이어 삭제 가드가 없다(메모리 「바이어 삭제 가드 후속」) — 계약서가 매달린 바이어가 지워지면 파일이 고아가 된다. `cascadeOnDelete` 로 행은 따라가되 S3 파일은 남는다 — 1차에선 그대로 두고 적어 둔다.
