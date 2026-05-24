# 서류 자동기입 — 회귀 박제 + 사용자 체크리스트 (loop 플랜)

> /loop 플랜 — A안(PHPUnit 박제) + C안(사용자 브라우저 체크리스트)
> 작성: 2026-05-24
> 목적: 2026-05-24 전면 재구축한 서류 자동기입 9종(`DocumentFiller`)이 앞으로 깨지지 않게 PHPUnit 으로 박제 + 사용자가 따라하기 쉬운 다운로드 체크리스트 작성.

## 0. 현재 구현 (배경)

- 엔진 `app/Services/Documents/DocumentFiller.php` — 노란셀만 기입 + 노란 fill 제거 + 수식 보존(통관 cascade) + 매핑없는 노란셀 샘플 비움 + 병합앵커 + stripHyperlinks.
- 매핑 `app/Services/Documents/Mappings/*Mapping.php` (8개) + 공유 `DocValue`.
- 양식 `resources/templates/system/*.xlsx` (9개).
- 컨트롤러 `VehicleDocumentController` — 전 type `streamXlsx`. EXPORT_ONLY(인보이스+선적4), 다운로드당 DocumentAccessLog.
- 9종: deregistration / deregistration_contract / poa / invoice / clearance / container_invoice_packing / container_contract / roro_invoice_packing / roro_contract.
- 기존 회귀: **444 passed** (깨지면 즉시 종료).

## 1. 목표 (Success Criteria)

- 산출물 A: `tests/Feature/DocumentFillerTest.php` (신규) — 9종 각 핵심 셀 매핑 + 엔진 불변식 박제.
- 산출물 C: `docs/verification/2026-05-24-서류-다운로드-체크리스트.md` — 사용자가 차량 1대로 9종 다운받아 눈으로 확인하는 단계별 체크.
- 종료 시 전부 초록 + 444 유지(+신규).

## 2. 사전 결정사항

| 항목 | 값 |
|---|---|
| 프레임워크 | PHPUnit (Tests\TestCase + RefreshDatabase) |
| 검증 방식 | DocumentFiller 로 Spreadsheet 생성 → PhpSpreadsheet 로 셀 값 직접 assert (파일 저장 없이 in-memory `getCell()->getValue()`/`getCalculatedValue()`) |
| 수식 재계산 | `$ss->getActiveSheet()->getCell('E27')->getCalculatedValue()` 또는 Xlsx writer preCalc 후 재로드. in-memory getCalculatedValue 우선 |
| 차량 셋업 | export 차량 + Buyer/Consignee + 확정 FinalPayment (인보이스 DEPOSIT). 헬퍼 case 내부 |
| 정답 출처 | `app/Services/Documents/*` + 메모리 `project-document-mapping` (셀↔필드 표) |
| 노란 판정 | fill solid + RGB R>180·G>170·B<160 (엔진 isYellow 와 동일 휴리스틱) |

## 3. 작업 단위 (per iteration)

```
1. DocumentFillerTest 에 case 1개 작성
2. php artisan test --filter=DocumentFillerTest → 초록 확인
   · 빨강이면 (a) 코드-기대 불일치(=실제 버그 신호 → 로그+사용자알림) (b) 테스트 오작성(SKIP)
3. vendor/bin/pint --dirty
4. git commit -m "test: 서류 박제 — {case}"
5. 진행 로그 append
```

## 4. 금지 사항
- ❌ production 코드(`app/`,`resources/`,`routes/`,`migrations/`) 수정 (박제만)
- ❌ 기존 테스트 수정
- ✅ `DocumentFillerTest.php` 신규 + 체크리스트 md 작성만
- ✅ `dev` 직커밋 + pint

## 5. 종료 조건
- §7 전 케이스 통과 → 자동 종료
- 같은 case 3회 실패 → SKIP+로그
- 기존 444 중 하나라도 깨지면 즉시 종료 + 경고

## 6. 진행 로그
`docs/loop-runs/2026-05-24-document-autofill.md` — 매 iter 1줄.

## 7. 케이스 목록 (A — PHPUnit, 12건)

### 매입 (3)
| # | case | 검증 |
|---|---|---|
| 1 | 말소신청서 매핑 | L1 차명 / D6 소유자 / D7 RRN / D8 주소 / A11 차량번호 / E11 VIN / J11 주행 / A24 `=TODAY()` 수식 보존 / 노란 0 |
| 2 | 말소계약서 | B50 = VIN / 노란 0 |
| 3 | 위임장 | A5 차량번호 / E5 VIN / I5 차명 / L5 연식 |

### 판매 (1)
| 4 | 인보이스 | E5 바이어 / E6 Client / E7 여권 / E10 환율 / 차량행(A18·B18·C18·D18·E18·F18) / E24 commission / **E26 = -tax_dc(음수)** / **E29 = 확정 FP 합** / E27 SUBTOTAL·E34 BALANCE 재계산 / E4 `SC{ym}-{id}` |

### 통관 (1)
| 5 | 통관 SET | 구매리스트 B4 차량번호 / D4 영문번호(romanize) / B5 차명(brand+model) / D5 VIN / B7 말소일 / I8 weight_kg / G4·G5·G11 nice_raw / **D3·G3 매매업 공란** / 6 종속시트 cascade 전파(한글등록증 E4 등) |

### 선적 (4)
| 6 | 컨테이너 Invoice&Packing | 헤더(B9·I15·B16·E16·I17·D37) / 행21(C·D·F·H·J·L) / 행22 연료·배기 / 노란 0 |
| 7 | 컨테이너 Contract | F4 컨테이너 / F5 Client / 행16(B·C·E·F·G) |
| 8 | RORO Invoice&Packing | 헤더 / 행21 / C32 incoterms |
| 9 | RORO Contract | F4 / F5 / 행16 |

### 엔진 불변식 (3)
| 10 | 노란 fill 전 시트 제거 + 매핑없는 노란셀 샘플 비움(통관 D3 / 선적 미사용 행) | 전 문서 노란 0 + 샘플 잔존 X |
| 11 | EXPORT_ONLY 가드 | carpul 차량으로 invoice·선적4 요청 → 403 / 매입3·통관 → 통과 |
| 12 | DocumentAccessLog | 다운로드당 1행 + document_type 정확 |

## 8. 케이스 목록 (C — 사용자 체크리스트 md)

`docs/verification/2026-05-24-서류-다운로드-체크리스트.md` 작성:
- 사전: 차량 1대(export)에 매입·판매·통관·선적 필드 입력 + 확정 잔금 1건.
- 각 9종: "차량 편집 → 서류 탭 → {버튼} → 다운로드 → 열어서 {확인 포인트}" 체크박스.
- 확인 포인트 예: 노란색 없는지 / 차량번호·VIN 맞는지 / 통관은 등록증·말소증 시트까지 값 들어갔는지 / 인보이스 BALANCE 맞는지.
- **사용자 친화**: 셀 좌표 말고 "오른쪽 위 Invoice No 칸", "맨 아래 BALANCE" 처럼 위치로 설명.

## 9. 비고
- **#3 다중차량(선택형) 미구현** — 본 플랜은 현재 9종 단일차량 기준. #3 완료 후 case 13+ 로 다중행 박제 확장.
- 기존 `VehicleDocumentControllerTest`(5건, assertOk 위주)와 중복 회피 — 본 테스트는 **셀 값 검증**에 집중.
- NICE 연동 전이라 통관 NICE칸은 `nice_raw` 수동 셋업으로 검증(연동 후엔 자동).
