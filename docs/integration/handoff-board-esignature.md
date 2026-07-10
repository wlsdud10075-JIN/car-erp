# 인계 패킷 — board: 판매계약서 전자서명 URL 전달 (2026-07-10)

> board 세션에 이 문서를 붙여넣어 작업 지시. **권위 스펙 = car-erp `docs/integration/board-portal-api.md §10`** (이 패킷은 요약 + board 할 일). board 변경은 **board repo·board 세션에서 커밋**(크로스레포 규칙 — 복사 금지, drift 방지).

## 한 줄 요약
ERP가 판매계약서 전자서명 세션을 발급하고 **서명 URL**을 반환한다. **board는 그 URL을 바이어에게 전달만** 한다. 서명 페이지·서명본·증거메일은 전부 ERP가 호스팅·완결하므로 board는 계약서 파일도 바이어 PII도 받지 않는다.

## board가 할 일 (딱 3개)
1. **`CarErpReadService::requestSigningSession($vehicleIds, $recipientEmail = null)`** 추가
   - `POST {CAR_ERP_BASE_URL}/api/internal/board/signing-requests`
   - HMAC 서명 = 기존 읽기 API와 **동일 canonical**(`board-portal-api.md §1`). ⚠️ POST라 canonical의 BODY = **전송하는 raw JSON 바이트 그대로**(직렬화 후 그 바이트로 서명·전송, 재직렬화 금지).
   - 헤더 = `X-Board-Signature`·`X-Timestamp`·`X-Nonce` (읽기 API와 동일).
   - 시크릿 = 기존 `CAR_ERP_READ_HMAC_SECRET` 재사용(신규 시크릿 없음).
   - 미설정/401/5xx → "발급 불가" degrade(기존 읽기 API와 동일 안전밸브).

2. **판매계약서 화면에 「전자서명 요청」 버튼**
   - 선택한 차량들(`vehicle_ids`, 동일 바이어·동일 통화·export만 — 아니면 ERP가 422) → 위 호출.
   - 응답의 `signed_url`을 **바이어에게 전달**: board가 이미 가진 바이어 채널(카톡/SNS/이메일)로 링크 전송. **ERP는 전달 대행 안 함.**
   - `recipient_email`은 선택 — 안 보내면 ERP가 바이어 `contact_email`로 기본 설정.

3. (선택) **상태 뱃지** — `GET /api/internal/board/signing-requests?salesman_email=` 폴링해 "발송됨/열람됨/서명완료" 표시. 미구현 시 "전송함"만 노출해도 됨.

## 요청/응답 계약
**요청 body**:
```json
{ "salesman_email": "sales@heyman.com", "vehicle_ids": [1215, 1216], "recipient_email": "buyer@example.com" }
```
**응답 200**:
```json
{ "signed_url": "https://heysellcar.com/sign/<token>?expires=...&signature=...",
  "contract_no": "SC2607-01215", "buyer": { "id": 42, "name": "ABC TRADING" },
  "currency": "USD", "vehicle_count": 2, "status": "pending",
  "expires_at": "2026-07-17T09:00:00+09:00" }
```
**에러**: `403`(영업 스코프/퇴사) · `422`(차량 혼합 바이어/통화·non-export·타 영업) · `409 already_signed`(이미 서명된 묶음 재발급) · `401`(HMAC).

## board가 반드시 지킬 것
- `signed_url`은 **그대로 전달만** — 파싱·재서명·프록시·변조 금지(URL 자체가 인가).
- 서명 페이지를 board가 **호스팅하지 않음** — URL만.
- 계약서 바이트·바이어 여권ID·주소를 board로 끌어오지 않음(URL 전달이라 애초에 불필요).
- payload `vehicle_ids`는 **한 계약 묶음 전체**(all-or-nothing) — 부분 서명 없음. 차량 구성 바뀌면 다시 요청(ERP가 기존 pending 세션 자동 revoke 후 재발급).

## 빌드 순서
① ERP가 §10 엔드포인트 구현·배포(진행 중) → ② board가 이 패킷대로 client·버튼 구현·board repo 커밋 → ③ e2e(발급→전달→바이어 서명→ERP 증거메일).

## 상태
- **ERP측**: 서명 세션 발급 API·서명 페이지·CoC·증거메일 = 구현 중(2026-07-10). 배포되면 이 패킷 갱신.
- **board측**: 미착수 — board 세션에서 이 패킷 받아 진행.
