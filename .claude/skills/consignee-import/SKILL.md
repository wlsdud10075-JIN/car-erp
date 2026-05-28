---
name: consignee-import
description: 컨사이니 일괄 업로드 양식(xlsx) 파일을 받아 바이어와 컨사이니를 한 번에 import한다. 사용자가 "양식 import해줘", "컨사이니 일괄 등록", "엑셀 파일로 컨사이니 추가" 같은 요청을 하거나, xlsx 경로(특히 바탕화면 컨사이니_업로드양식*.xlsx)를 가리킬 때 활성화.
---

# Consignee Import

사용자가 제공한 컨사이니 양식 xlsx 파일을 검증·import해서 바이어와 컨사이니를 일괄 등록한다.

deep-interview 2026-05-28 결정사항을 기반으로 한다. 자세한 결정 근거는 본 SKILL.md 끝의 "Background" 참조.

## 양식 (12컬럼)

```
A:바이어명*  B:컨사이니명*  C:국가  D:EORI NUMBER  E:TAX NUMBER  F:ID종류
G:ID번호  H:전화  I:이메일  J:영업담당자  K:주소  L:메모
```

표준 양식: `C:\Users\User\Desktop\컨사이니_업로드양식_v2.xlsx` (생성된 위치)

## 호출 절차

### 1. 파일 경로 확인

사용자가 양식 파일 경로를 명시하지 않으면 묻는다. 기본 경로는 `C:\Users\User\Desktop\컨사이니_업로드양식_v2.xlsx`.

### 2. dry-run 검증

```powershell
php artisan consignees:import "<경로>" --dry-run
```

출력:
- 파싱된 데이터 행 수
- 신규 바이어 후보 수
- ⚠️ 경고 (국가 미등록 등 — 진행 가능)
- ❌ 에러 (영업담당자 미등록, 같은 바이어에 다른 영업담당자, ID종류 enum 잘못 — 차단)

에러가 있으면 사용자에게 보고하고 양식 수정 또는 누락 master 데이터 등록 후 재시도.

### 3. 사용자 승인 후 실제 import

에러 없고 사용자 OK 받으면:

```powershell
php artisan consignees:import "<경로>"
```

또는 확인 프롬프트 생략하려면 `--force`.

영업담당자 컬럼(J)이 비어있는 행이 많고, 모두 한 명의 담당자에 묶고 싶다면:

```powershell
php artisan consignees:import "<경로>" --default-salesman=3
```

(salesmen 테이블의 id)

### 4. 결과 리포트

```
✅ import 완료
  바이어 신규: N건
  바이어 재사용: M건
  컨사이니 신규: K건
```

## 사전 확인 (사용자 환경 점검)

import 전에 다음이 준비됐는지 확인:

1. **영업담당자(salesmen) 등록 완료** — 양식 J 컬럼에 적힌 이름이 `salesmen.name`과 정확히 일치해야 함. 없으면 사용자가 admin UI에서 먼저 등록.
2. **국가(countries) 시드 완료** — `php artisan tinker --execute="echo Country::count();"` 가 199 이상이어야 함. 적으면 `php artisan db:seed --class=CountrySeeder` 실행.
3. **마이그 최신** — `php artisan migrate:status` 에 Pending 없어야 함 (특히 eori_number/tax_number 컬럼).

## 검증 규칙 (artisan command 내장)

- 바이어명·컨사이니명 빈 행 → 에러
- 국가 미등록 → 경고 (country_id=null로 저장)
- 영업담당자 미등록 → **에러 차단** (오타로 신규 영업담당자 생성 방지)
- 같은 바이어에 다른 영업담당자 → **에러 차단** (일관성 강제)
- ID종류 enum 외 값 → 에러 (rrn/passport/business)
- EORI/TAX 평문 저장. ID번호(id_value)는 자동 암호화 (Consignee 모델 cast).

## 동작 세부

- **바이어**: `name` 기준 find-or-create. 같은 이름이 여러 행에 나오면 1번만 생성, 이후 행은 같은 buyer_id로 컨사이니 연결.
- **신규 바이어**: name + salesman_id (있으면)만 채움. country/contact/address 등은 null. 사용자가 차후 admin UI에서 보강.
- **컨사이니**: 매 행 신규 create (중복 검사 없음 — 같은 이름 다른 행 = 별개 컨사이니로 간주).
- **트랜잭션**: 전체 import가 한 DB::transaction 안에서 실행. 1건이라도 실패하면 전부 롤백.

## Background (deep-interview 2026-05-28 결정)

| Q | 결정 |
|---|---|
| Q1: EORI/TAX 컬럼 위치 | consignees에 nullable 컬럼 2개 추가 (별도 테이블 X) |
| Q2: ID종류 결정 | 양식에 신규 컬럼 추가. enum: rrn/passport/business |
| Q3: 바이어 자동생성 시 채울 필드 | name + salesman_id (J컬럼 lookup). 나머지는 null |
| Q4: I컬럼 의미 | SSANCAR 영업담당자 (buyer.salesman_id), 컨사이니 contact_name 아님 |
| Q5: country 시드 | ISO 3166-1 200개 한국어명 1회 시드 + 검색형 dropdown |
| Q6: 형태 | Claude 스킬 (artisan command 기반). Livewire 화면 미구현 |
| Default-1 | 영업담당자 lookup 실패 → 에러 차단 |
| Default-2 | 양식 예시 행 — 사용자에게 "이 N행도 import?" 확인 |
| Default-3 | 같은 바이어 다른 영업담당자 → 에러 |
| Default-4 | 메모(L) 자유 텍스트 그대로 저장 |
| Default-5 | EORI/TAX = 평문, ID번호 = 암호화 유지 |

## Cleanup (import 실수 시 복구)

import 후 잘못된 걸 발견하면, 트랜잭션은 이미 commit됐으므로 수동 삭제:

```powershell
# 방금 import한 바이어·컨사이니 ID 확인 후 SoftDeletes로 삭제
php artisan tinker --execute="App\Models\Consignee::whereIn('id',[...])->delete(); App\Models\Buyer::whereIn('id',[...])->delete();"
```

또는 전체 한 번에:

```powershell
# 위험: created_at 기준 최근 1시간 이내 import만 골라 삭제 (다른 등록과 충돌 주의)
```

권장: import 전에 `php artisan db:backup` 실행 → 사고 시 복원.
