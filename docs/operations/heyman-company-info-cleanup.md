# HEYMAN 양식 회사정보 일괄 정리 (예정: 2026-06-26)

> 2026-06-25 세션 종료 시점 기록. **트리거: "heyman 회사정보 정리 이어서"**
> jin 이 내일(2026-06-26) **HEYMAN 정확한 영문 회사정보**를 주면 전 양식을 한 벌로 통일한다.

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
