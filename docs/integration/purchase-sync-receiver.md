# 연동 B 수신 계약 — car-erp "받는 절반" (발신 = board)

> **짝 문서**: board `SKILLS.md §12`("보내는 절반", 권위=payload). 이 문서 = **수신 스펙 권위**(엔드포인트·수신로직·보안).
> ⚠️ **계약 변경은 양쪽 동기화 + 수신측(car-erp) 먼저 배포** → 그다음 board 발신. (car-erp `artisan down` 1~3분 순단은 board 큐+재시도가 흡수.)
> 두 앱은 **DB·보안경계가 다른 별도 앱**(board=RRN 없음 / car-erp=RRN 보유). 합치지 않고 **이 API 계약 1개**로만 연결.

상태: **✅ 구현·2차수정·기본비용 자동기입 + 운영 배포 완료 (2026-06-15, master deploy #10 — 배포기록 §20)** — 운영 car-erp `.env CAR_ERP_HMAC_SECRET` 세팅 + `config:cache` 완료(값은 .env/AWS 폴더에만, 문서 평문 금지). 테스트 18케이스. **남은 것 = board 운영 `.env`(CAR_ERP_BASE_URL + 동일 시크릿) 세팅 → 운영 end-to-end 확인**(board 세팅 전까진 board Job no-op라 안 흐름·안전). 구현 파일: `routes/api.php` · `bootstrap/app.php`(api 등록) · `app/Http/Middleware/VerifyPurchaseSyncHmac.php` · `app/Http/Controllers/Webhook/PurchaseSyncController.php` · `config/services.php`(`services.purchase_sync.hmac_secret`) · 마이그레이션 `…add_purchase_sync_columns_to_vehicles`(purchase_source·c_no) · 테스트 `tests/Feature/PurchaseSyncReceiverTest.php`(17 케이스). **2차 수정(board 로컬 e2e 발견)**: ① `sales_channel='heyman'` 제거(enum export 단일) ② 매칭키 vin→vehicle_number + NICE 조회로 VIN 채우기.

---

## 엔드포인트
`POST /api/internal/purchase-sync` — board가 `status='won'` 확정 차량을 car-erp(heyman) 매입 재고로 동기화.

- **인증**: **HMAC 서명 검증 전용**(`CAR_ERP_HMAC_SECRET`, board와 공유) + HTTPS. 세션/role 미들웨어 아님(시스템 간 호출). 검증 실패 → **401**.
- **라우팅 선행**: `routes/api.php` 신설 + `bootstrap/app.php` 에 `api:` 등록(현재 web only). 전용 미들웨어 `VerifyPurchaseSyncHmac`.
- **현재 board만 발신**(heyman 수신). karaba/ssancar 연동은 미정.

## 요청 payload (board §12와 동일 — 권위는 board)
```json
{ "contract_version": 1,
  "vehicle_number": "...", "owner_name": "...", "source": "encar|auction",
  "final_price": 0, "salesman_email": "...", "car_erp_salesman_id": null,
  "c_no": null, "payee_name": null, "payee_bank": null, "payee_account": null }
```
- ⚠️ **VIN 은 payload 에 없음** (2026-06-15 정정). board 는 VIN 을 모른다 — VIN 은 **NICE 차량조회로만** 나오고 그건 **car-erp 책임**. board 는 `vehicle_number + owner_name` 을 보내고 car-erp 가 NICE 로 VIN·차량정보를 채운다. **매칭/멱등/식별 키 = `vehicle_number`**. (과거 vin 기반 계약은 drift — 되돌리지 말 것.)
- **전방호환**: **모르는 필드는 무시**(구 계약의 `vin` 잔재 포함). `contract_version` 검사 — **`1`·`2` 처리**(v2 = `attachments[]` 추가, §연동 B v2), 미지원 버전 → **422** + 로그.
- **필수**: `vehicle_number · source · final_price · salesman_email`. `owner_name` 포함 나머지 optional. (`owner_name` 없으면 NICE 불가 → vehicle_number 로만 생성, VIN 수동/후속.)
- **보안경계**: RRN/전화/서류 **미포함**(board가 안 보냄). `payee_account` 는 HMAC+HTTPS 한정 평문 수신.

## 수신 로직 (car-erp 책임)
1. **HMAC 검증** → 실패 401 (타이밍-세이프 비교).
2. **멱등 (vehicle_number 사전조회)**: `Vehicle::where('vehicle_number', $x)` 조회.
   - **존재** → 신규 생성 스킵 + **NICE 재호출 방지**, 기존 `{vehicle_id}` 반환(**200**). (기존행 갱신 안 함 — push-once.)
   - **없음** → 신규 `Vehicle` 생성(**매입 단계**), 201.
3. **영업 매칭 → 담당 관리 자동 솔팅**: `car_erp_salesman_id`(명시 오버라이드) 우선 → `salesman_email`(`Salesman.email` 직접 → `User.email` → `user.salesman`) → `vehicle.salesman_id`. 못 찾으면 null(수동 배정). 담당 관리 = `salesman.user.manager_user_id`(기존 솔팅에 자동 반영, 별도 저장 X).
4. **NICE 조회로 VIN·차량정보 채움**: `owner_name` 있으면 `NiceApiService::lookupVehicle(vehicle_number, owner_name)` → 성공 시 registration/spec(=vehicle 컬럼명) 을 fillable 가드로 적용(`nice_reg_vin` 등 + `nice_raw` 보존). owner_name 없거나 NICE 미설정/실패 → **graceful**(VIN 없이 생성, 에러 아님). UI `lookupNiceApi()` 와 동일 매핑.
5. **필드 매핑**: `final_price`→`purchase_price` · `vehicle_number` · `owner_name`→`nice_reg_owner_name`(baseline, NICE resFinalOwner 가 덮어쓸 수 있음) · `source`→`purchase_source`(신설) · `c_no`→`c_no`(신설, 연동 A 조인키 nullable·non-unique) · `payee_*`→**매입탭 정산계좌**(`purchase_seller_holder/bank/account`, account 는 모델 cast 자동 암호화 = RRN 패턴). **`sales_channel` 은 set 안 함** — enum 이 `export` 단일로 축소됨(2026-05-14) → default 사용.
6. **감사**: `audit_logs` action `inbound_purchase_sync`(차량당 1행). board 는 outbound 를 `integration_events` 에 기록 → 양방향 추적.

## 연동 B v2 — 차량 첨부(사진/서류) 수신 (2026-06-22)

> 발신 권위 = board `SKILLS.md §12` (`contract_version: 2`). 인계 = board `meetings/handoff-car-erp-vehicle-attachments.md`.
> ⚠️ **승인**: purchase-sync 승인 위의 신규 변경(대표 승인 영역). 대표 부재 + 근거(① 차량등록증=주소·RRN 마스킹본 ② car-erp 가 NICE 권위데이터 재등록 → board 분은 참고사본 ③ 실행파일 차단)로 **Jin 권한 진행**. board측 게이트는 이미 해소.

영업이 board 에 올린 **차량 사진(sales_photo)·서류(sales_document)** 를 `won→synced` 시 1회 payload 에 실어 보낸다(**S3 키만, 바이트 X** — 공유 버킷 `heysellcar-erp-docs`).

**payload 확장** (v2, 나머지 필드 불변):
```json
"attachments": [
  { "s3_path": "purchase-board/sales/photos/123/abc.jpg", "original_name": "front.jpg", "kind": "sales_photo", "sort": 1 },
  { "s3_path": "purchase-board/sales/documents/123/reg.pdf", "original_name": "차량등록증.pdf", "kind": "sales_document", "sort": 2 }
]
```

**수신 로직** (`PurchaseSyncController::syncAttachments`):
1. `attachments[]` 있으면 생성/매칭된 vehicle 의 **`vehicle_photos`(차량 기본정보탭 첨부, 최대 10건)** 에 행 생성. `sort` 로 정렬.
2. **S3 접근 = (B) 서버사이드 복사** (car-erp 결정). board 키 → car-erp prefix `vehicles/{id}/synced/{md58}_{basename}`. 소스 디스크 = `config('filesystems.purchase_sync_inbound_disk')`(기본 = vehicle_docs_disk).
   - **운영**: 소스=타겟=같은 s3 버킷 → `disk->copy`(서버사이드, 바이트 전송 X). **env 무설정 = 자동.**
   - **로컬**: board·car-erp 가 별도 디스크라 그대로면 source 못 찾음(skip). `.env` 에 `PURCHASE_SYNC_INBOUND_DISK=board_inbound` + `BOARD_STORAGE_PATH=<board>/storage/app/public` → 교차 디스크 스트림 복사로 로컬 e2e 가능. (운영엔 이 두 env 미설정.)
3. **멱등/dedup**: target 경로가 source 키로 **결정적** → 재전송 시 동일 target → 기존 행 있으면 skip. 멱등(기존 vehicle 200) 분기에서도 첨부는 보강 시도(방어적).
4. **cap 10** 초과분 무시. **원본 누락·복사 실패 = graceful**(해당 건만 skip, 동기화 전체 성공).
5. **스키마**: `vehicle_photos` 는 `path`·`sort_order` 만(원본명·kind 컬럼 미도입 — Jin 결정 "최소"). 파일명은 key basename. `kind` 는 prefix(photos/documents)로 구분 가능.
6. **서류(sales_document) PII**: car-erp 기존 문서 보안 정책(접근권한·다운로드 감사)이 `vehicle_photos` 경유 자동 적용.

**배포 순서**: car-erp 먼저(전방호환이라 board v1 발신 중에도 안전) → board v2 송신. e2e = board 영업자료 올린 차 won → car-erp 첨부탭에 사진/서류.

테스트 = `PurchaseSyncReceiverTest`(첨부 5케이스: 생성+복사·미첨부·dedup·cap10·원본누락 graceful) 포함 23케이스.

## 연동 B v3 — 금액/바이어/컨사이니 확장 (2026-06-23)

> 발신 권위 = board `SKILLS.md §12` (`contract_version: 3`). 인계 = board `meetings/handoff-car-erp-amount-mapping.md`. 배경: 차량은 원가판매(판매마진≈0), 회사수익=부가세환급(구입금액×9%, 정산씬 불변). 판매가/환율은 **관리가 ERP 지정시점 환율로 확정** → board 는 **pre-fill(추정치)만** 보내고 관리가 덮어씀. ⚠️ 무수정 원칙의 명시적 확장 = **Jin 권한 진행**.

**payload 확장** (v3, 기존 v1/v2 필드 전부 유지·전방호환. 신규 모두 nullable):
```json
"purchase_price_krw": 0,      // 구입금액(차값−할인)만 → purchase_price (final_price 부풀림 교정)
"selling_fee_krw": 0,         // 매도비 → selling_fee
"transport_fee_usd": 0,       // 운임비 → transport_fee (외화/USD)
"sale_price": 0,              // pre-fill → sale_price (관리 편집)
"sale_currency": "USD",       // → currency (enum USD/JPY/EUR/GBP/CNY/KRW)
"sale_exchange_rate": 0,      // pre-fill → exchange_rate (관리가 지정시점 환율로 덮어씀)
"buyer_id": null,             // → buyer_id (FK buyers, 검증 후)
"consignee_id": null          // → consignee_id (FK consignees, 검증 후)
```

**수신 규칙** (`PurchaseSyncController`):
1. **`contract_version: 3` 수용** (1·2·3). 미지원 → 422 유지.
2. **purchase_price**: v3 & `purchase_price_krw` 있으면 그것, 아니면 `final_price`(v2 호환). validation = `final_price` 는 `required_without:purchase_price_krw`(둘 중 하나 필수).
3. **selling_fee/transport_fee**: 값 있으면 채움(관리 이후 편집).
4. **sale pre-fill — ⚠️ chk_sale_required all-or-nothing**: `sale_price>0` 이면 DB CHECK 가 `sale_date NOT NULL AND exchange_rate>0` 요구(SKILLS #25). 따라서 **`sale_price>0 AND sale_exchange_rate>0` 일 때만** sale_price·exchange_rate·currency·`sale_date=now()` 세팅(→ progress 즉시 `판매중`, Jin 확정 OK). **환율 누락 시 sale 필드 통째 보류**(매입중 유지, currency 힌트만 무해 보존) — INSERT 실패 방지.
5. **buyer_id/consignee_id**: 존재 + `is_active` 검증. consignee 는 **해당 buyer 하위 + active** 여야 함(소속 불일치 → null). buyer 무효 → 둘 다 null.
6. **정산(부가세 9%·마진) 미변경** — 기존 정산씬 그대로.
7. **멱등(기존 vehicle 200)**: 신규 필드도 스킵(push-once, 기존행 갱신 안 함). 첨부 보강만 유지.

**배포 순서 (중요)**: ⚠️ board 가 `contract_version:3` 을 보내는데 car-erp 가 아직 v3 미배포면 **422 로 sync 전체 거부**. → **car-erp v3 master 먼저 배포 → board v3 송신 전환**. (엔드포인트도 board 는 404 graceful 이지만 라이브 전엔 실제로 안 됨.)

테스트 = `PurchaseSyncReceiverTest` v3 6케이스(purchase_price_krw 우선·fallback·sale prefill 환율유무·buyer/consignee 검증·소속불일치).

## 응답 / 에러 계약
| 상황 | 코드 | board 동작 |
|---|---|---|
| 신규 생성 | 201 `{vehicle_id}` | car_erp_vehicle_id 채움 → VIN 잠금 |
| 기존(멱등) | 200 `{vehicle_id}` | 동일 |
| HMAC 실패 | 401 | 영구실패 → integration_events 기록·알림 |
| 검증/미지원 버전 | 422 | 영구실패(4xx) |
| 서버 오류 | 5xx | **큐 재시도**(멱등이라 안전) |

## 보안 (car-erp-security 검토 필수)
- HMAC 전용 게이트(권한 가드 우회 = 신규 vehicle 생성이므로 **인증된 board만**). rate limit. 가능하면 board IP allowlist.
- `payee_account` 저장 암호화 검토(민감정보 — RRN 암호화 패턴 참고).
- 재전송 공격 방지(timestamp window + nonce 또는 integration 멱등).

## 구현 선행 (car-erp)
- `routes/api.php` + `bootstrap/app.php` api 등록 · `VerifyPurchaseSyncHmac` 미들웨어 · `PurchaseSyncController`
- `.env CAR_ERP_HMAC_SECRET`(board와 동일 값) · `config/services.php` 에 secret
- (확인) 매입출처(source) 컬럼 · payee 정산계좌 컬럼명
- 테스트: HMAC 위변조·멱등 재전송·영업 매칭·미지원 버전 (car-erp-qa/security)

## 구현 확정 사항 (2026-06-15, 2차 수정 반영)
- **매칭/멱등 키** = **`vehicle_number`** (VIN 아님). 기존 차량번호 → 갱신 안 하고 `{vehicle_id}` 200 + NICE 재호출 방지. 신규 → 201.
- **VIN** = car-erp 가 **NICE(vehicle_number+owner_name)** 로 조회해 `nice_reg_vin` 에 채움. owner_name 없거나 NICE 미설정/실패 → graceful(VIN 없이 생성). `NiceApiService::fromConfig()->lookupVehicle()` 사용, registration/spec 을 fillable 가드로 적용 + `nice_raw` 보존.
- **`source` 저장** = 신설 컬럼 `vehicles.purchase_source`(string 20, nullable). 기존 `purchase_from`(구입처, 자유서식)과 별개 — board origin 추적용.
- **`c_no` 저장** = 신설 컬럼 `vehicles.c_no`(string nullable, **index·non-unique**). 연동 A 조인 thread 키.
- **payee 정산계좌** = 기존 매입탭 계좌 3컬럼 재사용: `payee_name`→`purchase_seller_holder` · `payee_bank`→`purchase_seller_bank` · `payee_account`→`purchase_seller_account`(**모델 cast 로 자동 암호화** = RRN 패턴, `AuditLog::MASKED_COLUMNS` 에도 이미 등록).
- **HMAC** = 헤더 `X-Board-Signature: sha256=<hex>`, 서명대상 = **수신 raw body 그대로**(`$request->getContent()`), `hash_equals` 타이밍-세이프 비교. 비밀키 `config('services.purchase_sync.hmac_secret')` ← `CAR_ERP_HMAC_SECRET`. 미설정/불일치/누락 → 401.
- **재전송 방지** = timestamp/nonce 미도입. **vehicle_number 멱등 자체가 재전송을 무해화**(중복 생성 불가) → 별도 nonce 불필요로 판단. 필요 시 추후 보강.
- **`sales_channel`** = **set 안 함**. enum 이 `export` 단일로 축소됨(2026-05-14 `simplify_sales_channel_to_export_only`) → `heyman` 지정 시 MySQL truncate 500. default(`export`) 사용. ⚠️ board 로컬 e2e 가 잡은 1차 버그(daa4c16) — SQLite 테스트는 TEXT 라 못 잡았음.
- **응답 코드** = 신규 **201** / 멱등 스킵 **200** (board는 2xx 모두 성공 처리). 미지원 버전·검증 실패 **422**.
- **rate limit** = `throttle:30,1`(분당 30). **감사** = `audit_logs` action `inbound_purchase_sync`(차량당 1행, payee_account 는 마스킹 대상이라 미로깅).
- **라우트 prefix** = Laravel `api:` 자동 `/api` prefix → 최종 경로 `/api/internal/purchase-sync`.
