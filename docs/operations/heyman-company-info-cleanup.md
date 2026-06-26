# HEYMAN 양식 회사정보 일괄 정리

> 2026-06-25 기록 → **2026-06-26 주소·이메일 통일 실행 완료**. **트리거: "heyman 회사정보 정리 이어서"**

## ✅ 2026-06-26 실행 완료 (jin 확정 정보 반영)

**jin 확정 회사정보**:
- 주소(한글): `서울특별시 영등포구 선유동1로 50, 513호(당산동3가, THE PARK 365)`
- 주소(영문): `#513, THE PARK 365, 50 Seonyudong1-ro, Yeongdeungpo-gu, Seoul, Korea`
- 이메일: `heyman99888@gmail.com` (man99777@naver.com·ssancar9977@gmail.com 모두 교체)
- FAX: `0505-366-9977`(=82-505-366-9977, 양식 대부분 이미 366) / TEL·상호·대표자(조태신, heyman·ssancar 공동대표)는 유지
- ⚠️ **어제(6-25) 기록한 "매봉산로 31, 에스플랙스센터(상암동)" 주소는 폐기** — jin 이 6-26 에 선유동1로(당산동, THE PARK 365)로 정정.

**교체한 셀 (heyman 양식 12셀 + 등록증 2셀, `scripts/` 미보존 스크립트로 단일run 텍스트 치환·preCalc=false 저장)**:
container_contract/roro_contract `HBB340.!A2` · container_invoice_packing/roro_invoice_packing `INVOICE!B3` · sales_invoice `Invoice!A2`(Ssancar→Heyman LTD.+주소+이메일, 대표자 Cho Tae Shin 유지)·`E15` · clearance_set `한글등록증!E7`·`영문등록증!E7`·`차량인보이스!A3`·`차량팩킹!A3`·`Travel Services Invoice!A2`·`F18` · power_of_attorney `4.위임장!C24`(위임받는자=헤이맨. C19 평택=위임하는자=차주라 유지).

**같이 처리한 별건 (3개 세트 system/karaba/heyman 공통 양식 결함)**:
- 말소증 `E21:G21` 하단 테두리 제거 (E21/E22 사이 진한 선 — 소유자명 박스 둘로 갈라보임).
- `차량인보이스!E22` Q'TY=1 노란 제거 (노란+매핑없음이라 엔진이 "샘플값 비움"으로 지워 수량이 빈칸이던 버그. 차량팩킹 E22=`=차량인보이스!E22` 가 미러).
- `말소증!K14` 용도 → `=구매리스트!B8` 동적연동 (Q'TY와 동일한 노란 리터럴 wipe 버그).
- **최대적재량 NICE 연동** (3세트): NICE `nice_reg_max_load`(`mxmmLdg`) 존재 확인 → `ClearanceSetMapping` `I9` 매핑 + 구매리스트 `H9`="최대적재량" 라벨 + 한글/영문등록증 `I23`=`=구매리스트!I9` cascade. (이전엔 static 0 — 이제 NICE 실값. 승용차=0/null, 화물차=실적재량. 검증: 차량#140 max_load 300 → I23=300.)
- **RORO/컨테이너 CONTRACT Adress·Phone·환율 매핑** (코드: `Container/RoroContractMapping` header): `F6`=컨사이니 address(없으면 바이어) / `F7`=contact_phone / `F9`=`DocValue::money(exchange_rate)`. 라벨 E9 "Dollar Rate"는 currencyAware가 "EUR Rate" 등으로 자동 변환. (그동안 라벨만 있고 값 셀이 매핑 안 돼 공란이었음 — SalesInvoiceMapping E8/E9/E10 패턴 미러.)

**MADAGASCAR**(container/roro `INVOICE!N14/N17` SSANCAR MADAGASCAR 현지파트너) = jin "그대로 둔다".

## ⏳ 추후 정리 보류 (jin 6-26: "3번으로, 추후 변경 가능하니 기억")

- **`deregistration_contract`(말소계약서) 전면 정리** — 동적 채움이 `B50`(차대번호) 하나뿐이라 나머지가 전부 샘플/레거시로 인쇄됨:
  - 헤더 `2.계약서!A2/A3` = 옛 인천(194-75 Okryendong Yeonsugu)·고양(63 Seonghyeon) 주소 / `E5` FAX = 031-499-1989(옛 시흥).
  - 하단 `C57` SELLER = **SUNGSIN MOTORS CO.LTD**(헤이맨/싼카 아님) / `A57` BUYER = AQABA(요르단) / `A18:C49` 차량 240UNIT 샘플 리스트 / 계약번호 YO7511008 등.
  - → 주소만 고치면 앞뒤 안 맞아서 jin 이 "지금은 그대로" 결정. 전체 재구성 시 SELLER 를 무엇으로 할지 jin 확인 필요.
- **`power_of_attorney` 위임하는자(차주) 동적화** — `C18`(이동석)·`C19`(평택 용이동)·`C20`(주민번호 710208-…) 가 샘플 정적. 실제 차주가 들어가야 하나 매핑 미연동. RRN 취급 주의 필요한 별건.

## 배경

heyman 양식 세트(`resources/templates/heyman/`)는 karaba 식 셀 치환으로 생성됐는데(`7fe2eb4`),
회사정보 셀 일부가 **SSANCAR 잔재**로 남거나 **주소·이메일이 서로 다르게** 박혀 있다.
SKILLS §12 원칙대로 회사정보는 매핑이 아니라 **템플릿 셀에 인쇄**돼 있어, 셀을 직접 고쳐야 한다.

## jin 에게 받을 정보 (2026-06-26)

```
영문 상호:     HEYMAN LTD.  (확인 필요)
대표자(영문):  ?
영문 주소:     ?   ← 진짜 주소 = 서울 마포구 매봉산로 31, 에스플랙스센터 시너지움 7층
                     1인 미디어파트너스 (상암동) 의 영문 표기
전화 / 팩스:   ?
이메일:        ?   ← 현재 man99777@naver.com(싼카)·ssancar9977@gmail.com 섞여 있음
```

확정 한글 정보(이미 반영됨): 상호 `주식회사 헤이맨` / 사업자번호 `535-87-01734` / 법인등록번호 `110111-7526176`.

## 고칠 셀 지도 (현재 상태 = 잘못)

| 파일 | 시트!셀 | 현재 내용 | 비고 |
|---|---|---|---|
| container_contract.xlsx | HBB340.!A2:D3 | HEYMAN + **시흥주소·man99777** | 오늘 이름만 HEYMAN(`0c01932`), 주소·이메일 싼카 |
| roro_contract.xlsx | HBB340.!A2:D3 | 동일 | 동일 |
| sales_invoice.xlsx | Invoice!A2 | **Ssancar 전체 블록** | 미수정(이름부터 싼카) |
| container_invoice_packing.xlsx | INVOICE!B3 | HEYMAN + **163 Sangidaehak-ro 시흥**(싼카 주소) | 주소 틀림 |
| roro_invoice_packing.xlsx | INVOICE!B3 | HEYMAN + **63 Seonghyeon-ro 고양** | 컨테이너와 또 다른 주소 |
| clearance_set.xlsx | 차량팩킹!A3 | HEYMAN + 시흥·man99777 | |
| clearance_set.xlsx | Travel Services Invoice!A2 / C16 | Heyman + 시흥 / HEYMAN CO LTD | |
| clearance_set.xlsx | 차량인보이스!A3 | 런타임 brandHeader 로 `Setting('sidebar_brand')` 치환 | 정적 아님 — sidebar_brand 값 확인 |
| container/roro_invoice | INVOICE!N14 / N17 | **SSANCAR MADAGASCAR** 현지법인 블록 | 이건 의도? jin 확인 |

> 참고: `config/company.php` 는 dead(매핑이 안 읽음, SKILLS §12). 회사정보는 전부 템플릿 셀 인쇄.
> `차량인보이스!A3` 만 동적(brandHeader). 나머지는 정적 셀 → 스크립트로 셀 치환.

## 작업 방식

1. jin 정보 받기 → 영문 회사 블록 1벌 확정.
2. 위 셀들을 스크립트로 일괄 치환 (RichText 셀은 첫 run 텍스트 교체로 서식 보존,
   `setPreCalculateFormulas(false)` 로 저장 — 통관 cascade 보존). 참고 스크립트:
   `scripts/heyman-doc-fixes-2026-06-25.php` 패턴.
3. 생성 검증(차명/통화 안 깨지나) → dev 커밋 → master cherry-pick 배포.
4. N14/N17 마다가스카르 블록은 jin 확인 후 처리.

## 같이 점검할 보류 건

- **영문등록증 P4 용도** = 현재 `자가용→Private` (SUBSTITUTE 수식). jin 이 "Private car" 등 다른 표기 원하는지 확인.
- **정산 paid_at 보정** 코드(`SettlementCkBatch::payoutDate` 등 5파일) = dev 에만, **실무자 검증 전이라 운영 미배포 보류**. (메모리 `project_board_settlement_paid_at`)

## 2026-06-25 배포 완료분 (참고 — 운영 라이브)

`4595188`(회사정보·용도/선적일 매핑) · `0dd1b6f`(서명/직인 heyman 전용+이중방지) ·
`5be0c6d`(비-USD 통화서식 `\€` 버그) · `5b02d7b`(말소신청서 34행 높이) ·
`010f53f`(제조사·연료·용도 영문) · `53d3ec6`(fullCalcOnLoad) ·
`719038b`(B15/D15 숫자화 + 선적 인보이스 ID·선적일) · `0c01932`(계약서 A2 HEYMAN).

핵심 교훈:
- **통화·토탈 빈칸** = 2원인. ① 양식 `\$#,##0`→`\€` 변환 시 멀티바이트 € 백슬래시 깨짐(`applyCurrency` 백슬래시 제거). ② `sale_price`(decimal=문자열)를 raw 로 넣으면 TYPE_STRING → Excel SUM 무시 → `DocValue::money()` 로 float. 더해서 ③ 저장 시 `preCalc=false`(fullCalcOnLoad=1)로 Excel 재계산 위임(크로스시트 cascade 캐시 불완전).
