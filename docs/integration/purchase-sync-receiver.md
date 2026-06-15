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
- **전방호환**: **모르는 필드는 무시**(구 계약의 `vin` 잔재 포함). `contract_version` 검사 — `1` 처리, 미지원 버전 → **422** + 로그.
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
