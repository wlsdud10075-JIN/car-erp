# 서류 양식 도장·서명 이미지 — 위치 및 교체 절차

> 목적: 회사별(테넌트별)로 **도장(직인)·서명 이미지**를 교체해야 할 때를 위한 위치 색인 + 교체 절차.
> 2026-06-22 작성. 신규 기능 아님 — 교체는 아래 수동 절차로 수행.

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
