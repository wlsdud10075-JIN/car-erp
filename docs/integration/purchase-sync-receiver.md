# 연동 B 수신 계약 — car-erp "받는 절반" (발신 = board)

> **짝 문서**: board `SKILLS.md §12`("보내는 절반", 권위=payload). 이 문서 = **수신 스펙 권위**(엔드포인트·수신로직·보안).
> ⚠️ **계약 변경은 양쪽 동기화 + 수신측(car-erp) 먼저 배포** → 그다음 board 발신. (car-erp `artisan down` 1~3분 순단은 board 큐+재시도가 흡수.)
> 두 앱은 **DB·보안경계가 다른 별도 앱**(board=RRN 없음 / car-erp=RRN 보유). 합치지 않고 **이 API 계약 1개**로만 연결.

상태: **✅ car-erp 수신측 구현 완료 (2026-06-15, dev)** — board 발신측은 이미 완료. 남은 것 = 양쪽 `.env` 비밀키 세팅 + 배포(car-erp 먼저). 구현 파일: `routes/api.php` · `bootstrap/app.php`(api 등록) · `app/Http/Middleware/VerifyPurchaseSyncHmac.php` · `app/Http/Controllers/Webhook/PurchaseSyncController.php` · `config/services.php`(`services.purchase_sync.hmac_secret`) · 마이그레이션 `…add_purchase_sync_columns_to_vehicles`(purchase_source·c_no) · 테스트 `tests/Feature/PurchaseSyncReceiverTest.php`(13 케이스).

---

## 엔드포인트
`POST /api/internal/purchase-sync` — board가 `status='won'` 확정 차량을 car-erp(heyman) 매입 재고로 동기화.

- **인증**: **HMAC 서명 검증 전용**(`CAR_ERP_HMAC_SECRET`, board와 공유) + HTTPS. 세션/role 미들웨어 아님(시스템 간 호출). 검증 실패 → **401**.
- **라우팅 선행**: `routes/api.php` 신설 + `bootstrap/app.php` 에 `api:` 등록(현재 web only). 전용 미들웨어 `VerifyPurchaseSyncHmac`.
- **현재 board만 발신**(heyman 수신). karaba/ssancar 연동은 미정.

## 요청 payload (board §12와 동일 — 권위는 board)
```json
{ "contract_version": 1,
  "vin": "...", "vehicle_number": "...", "source": "encar|auction",
  "final_price": 0, "salesman_email": "...", "car_erp_salesman_id": null,
  "c_no": null, "payee_name": null, "payee_bank": null, "payee_account": null }
```
- **전방호환**: **모르는 필드는 무시**. `contract_version` 검사 — `1` 처리, 미지원 버전 → **422** + 로그.
- **필수**: `vin · vehicle_number · source · final_price · salesman_email`. 나머지 optional.
- **보안경계**: RRN/전화/서류 **미포함**(board가 안 보냄). `payee_account` 는 HMAC+HTTPS 한정 평문 수신.

## 수신 로직 (car-erp 책임)
1. **HMAC 검증** → 실패 401 (타이밍-세이프 비교 + timestamp/nonce 재전송 방지 권장).
2. **멱등 (VIN 사전조회)**: `Vehicle::where('nice_reg_vin', vin)` 조회.
   - **존재** → 신규 생성 스킵, 기존 `{vehicle_id}` 반환(**200**). (기존행 일부 갱신 여부 = 구현 시 정책 확정 — 기본 스킵 권장.)
   - **없음** → 신규 `Vehicle` 생성(**매입 단계**), 201.
3. **영업 매칭 → 담당 관리 자동 솔팅**: `salesman_email` → `User(email)` → `Salesman` → `vehicle.salesman_id`. 없으면 `car_erp_salesman_id` fallback. 담당 관리 = `salesman.user.manager_user_id`(자동).
4. **필드 매핑**: `final_price`→매입가(`purchase_price`) · `nice_reg_vin`=`vin` · `vehicle_number` · `source`→매입출처(컬럼 유무 구현 시 확인/추가) · `c_no`→저장(연동 A 조인키, nullable·non-unique) · `payee_*`→**매입탭 정산계좌**(`add_purchase_account_to_vehicles` 컬럼 — 정확명 구현 시 / `payee_account` 저장 암호화 검토 = RRN 패턴).
5. **감사**: car-erp 측 **inbound 수신 기록**(`audit_logs` 또는 전용 inbound 로그). board는 outbound를 자기 `integration_events`에 기록 → 양방향 추적.

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

## 구현 확정 사항 (2026-06-15)
- **기존 VIN 갱신 정책** = **스킵**. 기존행 건드리지 않고 `{vehicle_id}` 200 반환. (갱신은 car-erp 내부 운영이 담당 — board는 push-once.)
- **`source` 저장** = 신설 컬럼 `vehicles.purchase_source`(string 20, nullable). 기존 `purchase_from`(구입처, 자유서식)과 별개 — board origin 추적용.
- **`c_no` 저장** = 신설 컬럼 `vehicles.c_no`(string nullable, **index·non-unique**). 연동 A 조인 thread 키.
- **payee 정산계좌** = 기존 매입탭 계좌 3컬럼 재사용: `payee_name`→`purchase_seller_holder` · `payee_bank`→`purchase_seller_bank` · `payee_account`→`purchase_seller_account`(**모델 cast 로 자동 암호화** = RRN 패턴, `AuditLog::MASKED_COLUMNS` 에도 이미 등록).
- **HMAC** = 헤더 `X-Board-Signature: sha256=<hex>`, 서명대상 = **수신 raw body 그대로**(`$request->getContent()`), `hash_equals` 타이밍-세이프 비교. 비밀키 `config('services.purchase_sync.hmac_secret')` ← `CAR_ERP_HMAC_SECRET`. 미설정/불일치/누락 → 401.
- **재전송 방지** = timestamp/nonce 미도입. **VIN 멱등 자체가 재전송을 무해화**(중복 생성 불가) → 별도 nonce 불필요로 판단. 필요 시 추후 보강.
- **sales_channel** = 생성 시 `heyman` 고정(CLAUDE.md 연동 B — board → heyman 재고 전환).
- **응답 코드** = 신규 **201** / 멱등 스킵 **200** (board는 2xx 모두 성공 처리). 미지원 버전·검증 실패 **422**.
- **rate limit** = `throttle:30,1`(분당 30). **감사** = `audit_logs` action `inbound_purchase_sync`(차량당 1행, payee_account 는 마스킹 대상이라 미로깅).
- **라우트 prefix** = Laravel `api:` 자동 `/api` prefix → 최종 경로 `/api/internal/purchase-sync`.
