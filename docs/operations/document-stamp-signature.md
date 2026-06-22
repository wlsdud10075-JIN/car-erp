# 서류 양식 도장·서명 이미지 — 업로드 오버레이 + 위치/크기 조정

> 2026-06-22. **구현 완료**: 기능설정에서 도장/서명을 업로드하면 서류 생성 시 자동 오버레이.
> 크기·위치는 코드(Mapping)에서 **언제든 수정 가능** — 아래 "크기·위치 조정" 참조.

## ✅ 구현된 기능 — 기능설정 업로드 오버레이

- **기능설정 → "도장·서명"** (super 전용): 회사(template_set)별로 **서명**·**직인** 이미지 업로드/되돌리기.
- 서류 생성 시 `DocumentFiller::applyStamps/overlayStamp` 가 양식의 정해진 앵커에 업로드 이미지를 오버레이.
  - 업로드 **원본 비율 유지**하며 목표 박스 안에 fit(왜곡 방지). PNG 투명도 보존(path 기반 Drawing).
  - 미업로드 = 양식 기본 이미지 그대로(하위호환).
  - 선적 4종(removeRow)도 fill 前 적용이라 도장이 트림 위치로 함께 이동.
- 저장: `Setting` 키 `stamp_{set}_{role}` → 업로드 경로. 디스크 = `config('filesystems.vehicle_docs_disk')`.
- 테스트: `tests/Feature/StampOverlayTest.php`.

### 역할 ↔ 양식 ↔ 앵커 ↔ 목표크기 (현재값)

| role | 양식(type) | 시트 | 앵커 | 목표크기(pt) | Mapping 파일 |
|---|---|---|---|---|---|
| `signature` | deregistration_contract | 2.계약서 | A60 | 612×179 | `DeregistrationContractMapping` |
| `signature` | container_invoice_packing | INVOICE | H115 | 490×234 | `ContainerInvoicePackingMapping` |
| `signature` | roro_invoice_packing | INVOICE | H55 | 490×234 | `RoroInvoicePackingMapping` |
| `seal` | invoice (sales_invoice) | Invoice | B36 | **160×160** | `SalesInvoiceMapping` |
| `seal` | container_contract | HBB340. | B59 | **160×160** | `ContainerContractMapping` |
| `seal` | roro_contract | HBB340. | B59 | **160×160** | `RoroContractMapping` |

### ⚙️ 크기·위치 조정 (언제든 가능)

각 `app/Services/Documents/Mappings/*Mapping.php` 의 `'stamps'` 배열에서:
- **크기** = `'width'` / `'height'` (pt). 비율 유지 fit 이라 정사각 이미지면 둘 중 작은 쪽에 맞춰 축소.
  - 예: 직인을 더 작게 → `160` → `120`. 더 크게 → `200`.
- **위치** = `'anchor'` (셀 좌표, 예 `B36`). 다른 셀로 옮기면 그 셀 좌상단 기준으로 배치.
- 수정 후 `php artisan view:clear` 불필요(서버 코드라 즉시 반영), 실제 다운로드로 육안 확인 권장.
- ⚠️ 직인 목표 160 = jin 실측치(2026-06-22). 서명(A60·H115)은 미조정 — 크면 동일 방식으로 줄이면 됨.

---

> 아래는 **양식 자체의 기본 이미지**를 직접 교체하는 레거시 절차(업로드 기능 쓰면 보통 불필요).
> 목적: 회사별(테넌트별) **도장(직인)·서명 이미지** 위치 색인 + 양식 직접 교체.

## 테넌트 구조 (이미 존재)

서류 양식은 회사별로 폴더가 갈린다 — `config/company.php` 의 `template_set`(`.env COMPANY_TEMPLATE_SET`)이 결정.

| 회사 | template_set | 양식 폴더 |
|---|---|---|
| ssancar (기본) | `system` | `resources/templates/system/` |
| karaba | `karaba` | `resources/templates/karaba/` |

→ **도장/서명은 본질적으로 폴더(세트)별**이다. karaba 도장을 바꾸려면 `resources/templates/karaba/` 안의 파일만 교체하면 되고 system 은 영향 없음.

## 이미지가 박힌 양식 + 위치

| 양식 파일 | 시트 | 이미지(원본 zip 내부 경로) | 앵커 셀 | 크기(px) | 용도 |
|---|---|---|---|---|---|
| `deregistration_contract.xlsx` | `2.계약서` | `xl/media/image1.png` | **A60** | 612×179 | 도장/서명 블록 |
| `sales_invoice.xlsx` | `Invoice` | `xl/media/image1.(jpeg)` | A1 | 315×50 | 상단 로고/헤더 |
| `sales_invoice.xlsx` | `Invoice` | `xl/media/image2.(jpeg)` | A2 | 314×50 | 상단 로고/헤더 |
| `sales_invoice.xlsx` | `Invoice` | `xl/media/image3.(png)` | **B36** | 598×373 | 하단 도장/서명 |

(karaba 사본은 같은 앵커·시트지만 zip 내부 파일명이 해시로 다름 — 교체 시 파일명 신경 쓰지 말고 아래 절차의 openpyxl 방식 사용.)

나머지 양식(말소신청서·위임장·통관 SET·선적 4종)은 이미지 없음.

## 교체 절차 (이미지/사진 받으면 실행)

도장·서명은 양식 셀 위에 떠 있는 **임베드 이미지**라, 새 이미지를 같은 앵커 셀에 다시 앉히면 된다.
openpyxl 로 기존 이미지를 제거하고 새 이미지를 같은 위치에 삽입:

```python
# 예: karaba 말소계약서 도장 교체 (새 도장 = new_stamp.png)
import openpyxl
from openpyxl.drawing.image import Image

p = "resources/templates/karaba/deregistration_contract.xlsx"
wb = openpyxl.load_workbook(p)
ws = wb["2.계약서"]
ws._images = []                       # 기존 이미지 제거
img = Image("new_stamp.png")
ws.add_image(img, "A60")              # 같은 앵커 셀
wb.save(p)
```

⚠️ **주의**:
- openpyxl 전체 재저장은 부수효과 가능 → 교체 후 **반드시 실제 다운로드로 육안 확인**(도장 위치·크기·정렬).
- 크기를 원본과 맞추려면 `img.width=612; img.height=179` (위 표 px 참고) 지정.
- system·karaba 각각 따로 교체(공유 아님).
- 양식 파일은 git 추적 → 문제 시 `git checkout` 으로 복원.

## 참고: 유령 열 청소와의 관계

매입 3종 양식의 "좌우 정렬 흐트러짐"은 별개 문제로 `scripts/strip-phantom-columns.py` 가 해결(2026-06-22).
그 청소는 `<cols>` 만 건드려 이미지는 byte-동일 보존 → 도장에 영향 없음.
