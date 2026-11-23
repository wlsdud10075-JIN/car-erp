# 서류 자동 생성 — xlsx 양식·매핑 (SKILLS §12)

> `DocumentFiller`·매핑·다중차량 서류·새 양식 채택 체크리스트. **서류 양식·매핑을 만지기 전에 읽는다.**
> 2026-11-23 `SKILLS.md` 에서 옮겨 왔다(본문 그대로, 절 번호도 그대로 — 다른 문서의 「SKILLS §N」은 여기의 같은 번호다).

## 12. 서류 자동 생성 — system xlsx 자동기입 (2026-05-24 전면 재구축)

> ⚠️ 기존 PDF(dompdf 5종) + CIPL(`VehicleCiplGenerator` 2종)은 **폐기**. 바탕화면 `system` 폴더 9종 xlsx 양식을 `resources/templates/system/` 으로 채택, **노란 배경 셀에만 차량 데이터 자동기입 + 노란 제거**(깔끔한 최종본). 라우트 `GET /erp/vehicles/{id}/documents/{type}` (`erp` 미들웨어) → 컨트롤러 `VehicleDocumentController::show()` 가 전 type 을 `DocumentFiller` 로 단일 처리.

| type | 서류 | 단계 | 채널 |
|---|---|---|---|
| `deregistration` | 자동차말소등록신청서 (별지17호) | 매입 | 전 채널 |
| `deregistration_contract` | 말소 계약서 | 매입 | 전 채널 |
| `poa` | 위임장 | 매입 | 전 채널 |
| `invoice` | Proforma Invoice | 판매 | export |
| `sales_contract` | 판매계약서 (다중차량, 1바이어·단일통화, 30슬롯 확장) | 판매 | export |
| `container_invoice_packing`/`container_contract` | 컨테이너 Invoice&Packing / Contract | 선적 | export |
| `roro_invoice_packing`/`roro_contract` | RORO Invoice&Packing / Contract | 선적 | export |
| `clearance` | 통관 SET (8시트, 구매리스트 마스터→6시트 자동연동) | 통관 | 전 채널 |
| `clearance_v2` | 통관 SET **Ver.2** (6시트 — 차량인보이스 탭 없음·차량팩킹 상단 오른쪽 블록 제거, jin 2026-10-15). 매핑은 Ver.1 과 **같은 것**(`ClearanceSetV2Mapping` 이 config 를 물려받음, brandHeader 만 없음). 양식 = `scripts/build-clearance-set-v2.php` 가 **각 세트 Ver.1 에서** 빌드(원본 통째 X — 노란칸·회사정보·직인 보존, 끊긴 수식 2개 구매리스트로 복구) · `--verify`. 가드 = `ClearanceSetV2Test` | 통관 | 전 채널 |

### 엔진 — `App\Services\Documents\DocumentFiller`
- `spreadsheet(type)`: 템플릿 로드 → 전 visible 시트 노란셀 정리 → 매핑 셀 기입 → Spreadsheet. `filename(type)` 다운로드명.
- **테넌트별 양식 세트 (멀티회사, 2026-06-13)**: 템플릿 경로 = `resources/templates/{config('company.template_set','system')}/`. 회사정보(상호·계좌·SWIFT·매매업번호)는 매핑이 아니라 **템플릿 셀에 인쇄**돼 있음(`config/company.php`는 dead — 안 읽힘). 회사별로 다르게 찍으려면 `.env COMPANY_TEMPLATE_SET=karaba` + `resources/templates/karaba/`에 9종 사본(회사정보 셀만 교체). ssancar=`system`(default). 재생성=`scripts/generate-karaba-templates.php`(셀별 명시 치환맵, stripHyperlinks+preCalc=false 로 저장). [[project_karaba_multicompany]]
- **노란셀 분기(핵심)**: 수식(`=`)→값 보존·fill만 제거(통관 `=구매리스트!` cascade 보존) / 리터럴+매핑→값 기입·fill 제거 / 매핑없음→**샘플값 비움**·fill 제거(옛 샘플 잔존 방지).
- 병합셀은 좌상단 앵커 기준 기입. numberFormat·스타일 보존. 날짜 `DateTimeInterface→PHPToExcel`. `stripHyperlinks`로 외부링크(WebDAV file://) 잔재 제거(writer 깨짐 방어).
- 저장 `(new Xlsx($ss))->save('php://output')` — preCalc 기본 true → 수식·cross-sheet 자동 재계산(통관 마스터 cascade 의 근거).

### 매핑 = 데이터 — `App\Services\Documents\Mappings\*Mapping.php`
- 각 `::config()` → `['template','sheet','label','cells'=>[좌표=>fn(Vehicle)]]`. 새 서류는 Mapping 추가만(엔진 무수정).
- 공유 resolver `DocValue`: carName(model)·carNameFull(brand+model)·invoiceBuyer/Consignee(export 우선)·consigneeIdValue·confirmedReceived(확정 FP 합)·niceRaw(data_get)·destinationCountry·consigneeBlock·romanizePlate(한글번호판→로마자 "19더9065"→"19DEO9065").

### NICE 연동 — 현재 상태
- `nice_raw`(JSON, cast array): 전용컬럼 없는 NICE 필드(resValidPeriod·resSpecControlNo·maxPower·mtrsFomNm·fomNm)+engineSpec 원본. `DocValue::niceRaw($v,$key)`.
- `deregistration_date`(말소일)·`nice_spec_cylinders`(기통). NICE 연동 완료(`698f0c9`). 기통수·검사종료·제원관리번호·형식·최대출력은 **2026-10-02 부터 전용 컬럼 우선, nice_raw 폴백**(`DocValue::nice*` — 기본정보 탭에서 수정 가능, 죽은 칸 변속기·구동방식·축거·색상은 화면에서 제거). 구: raw 에서 서류 생성 시 파싱.
- 매매업등록번호(통관 D3·G3)는 NICE 비제공→공란(수기).

### 회사 정보 / 부호 주의
- `config/company.php` — 회사 고정정보(대부분 템플릿에 인쇄돼 있어 신규 매핑은 차량 데이터 위주).
- 인보이스 **TAX D/C 음수**(`-tax_dc`, 양식 SUM 에 더해지므로) / DEPOSIT 양수(확정입금 합) / Invoice No `SC{연월}-{id}` 자동.

### UI — 차량 편집 패널 "서류" 탭
- `editingId !== null` 조건. 그룹: 매입(3, 전채널)·판매(인보이스, export)·선적(4, export)·통관(SET). xlsx 즉시 다운로드. 미저장 차량 → 버튼 비활성.

### 다중차량 선적 서류 (#3, 2026-05-24 완료)
선적 4종은 **선택 N대 → 1서류** 지원. 차량목록 체크박스(export 차량)로 선택 → 상단 액션바 4버튼 → 1서류에 N대 자동 기입(최대 30대).
- **방식 = 오프라인 pre-extend + 런타임 removal-only**. 양식은 `scripts/extend_shipping_templates.php` 로 30슬롯 확장·커밋(1회 사람 검증). 런타임(`DocumentFiller::fillMulti`)은 N대 채우고 미사용 슬롯 `removeRow`+`garbageCollect` 로 트림 → 정확한 크기.
- **⚠️ removeRow 는 footer SUM range 자동축소 안 함**(실측). 그래서 footer 집계수식을 채운영역(`=SUM(I21:I<lastfilled>)`)으로 **removeRow 전에 명시 재기록** → 참조가 전부 제거구간 위라 안전. container_invoice 는 stride-3 명시리스트→range 변환. per-row 수식(`=H`·`=SUM(I,L)`·`=RIGHT(E,6)`·`=F+G`)은 슬롯에 박혀 자동 보존.
- **Mapping 스키마**: 선적 4종은 `header`(슬롯 위/아래 1회, primary 차량)+`multi`(`first`/`stride`/`count`/`footerAggregates`/`slotCells[offset][col]`). 비-선적 type 은 기존 `cells`(단일).
- 라우트 `GET /erp/vehicles/documents/{type}?ids=1,2,3` (`showMulti`, 선적 4종·export 한정·DocumentAccessLog 차량당 1행). 단일 라우트(`{id}/documents/{type}`)는 유지(1슬롯 트림본).
- 양식 슬롯 기하: container_invoice(stride3, footer I/J/K/L row111) / roro_invoice(stride1, footer row51) / contracts(stride1, footer F46·I46·I47). incoterms footer 좌표 확장본 기준: container D112, roro C52.

### 🧾 새 양식을 채택하거나 고칠 때 (2026-08-31 하루에 5건 나왔다 — §8 #71·#71-B·#71-C·#71-D)

양식 문제는 **예외도 로그도 없다** — 서류는 정상 생성되고 칸만 비거나 틀린다. 그래서 체크리스트로 돈다.

1. **샘플 잔재를 찾는다** — 9종 × 3세트를 값으로 전수 스캔(정규식). 화면에 안 보여도 있다(#71-B).
2. **찾은 것을 「매핑 여부 × 칸 색」으로 분류**한다 — 매핑 있고 노란칸이면 생성 시 덮이니 그대로 둔다.
   매핑 없고 **흰칸이면 그대로 인쇄**되고, 매핑 없고 **노란칸이면 비워진다**(#71).
3. **세 세트를 대조**한다 — `heyman` 에만 수식이 있던 식의 차이가 실재한다. 파생 스크립트는
   회사정보 셀만 치환하므로 이런 차이가 그대로 복제된다.
4. **그 칸에 실제로 뭐가 들어가나를 길이 분포로 실측**한다 — 규격 기준 폭은 다수를 자른다(#71-C).
5. **미러 시트를 grep** 한다 — 값은 따라오지만 **서식·폭은 안 따라온다**(#71-C).
6. **스크립트로 고치고 `--verify` 를 단다** — dry-run 기본, 배포 후 운영에서 verify 로 마지막 확인.
   제외는 「대상 목록」이 아니라 **값 비교**로(#71-D). 저장은 `stripHyperlinks` +
   **`setPreCalculateFormulas(false)`**(preCalc 켜면 cascade 가 값으로 굳어 죽는다).
7. **검증은 매핑 배열이 아니라 생성물의 셀**로(#37). 기능 테스트로는 원리상 못 잡는다.

### 남은 작업
- **통관 SET 다중차량**: 마스터 수식(`=구매리스트!`) 구조라 선적과 별개. 미착수.
