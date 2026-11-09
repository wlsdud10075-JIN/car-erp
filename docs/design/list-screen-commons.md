# 목록 화면 공용 부품 — 헤더 겹침 정렬 · 검색 (기획, 2026-11-09)

> jin 2026-11-09 «공용으로 넣을 코드를 정하는 거. 차량관리에 검색기능, 헤더에 오름차순/내림차순… 새로운 내용에 헤더들이 들어갈 때
> 기본적으로 적용되게 하려면 어떻게 만들어야 가능해?» → «기획 문서로 적어줘. 일단은 헤더 중복정렬과 검색을 공통으로».
> **코드 착수 전.** 착수는 jin 「착수」 지시 뒤, 순서는 §6.

## 0. 한 줄

목록 화면마다 손으로 다시 만들던 **검색칸·헤더 정렬**을 「부품(동작) + 겉모양(컴포넌트) + 새 화면 틀(가드)」 세 층으로 만들어,
새 화면은 **무엇을 검색·정렬할지만 선언**하고 나머지는 저절로 따라오게 한다.

## 1. 지금 상태 (2026-11-09 실측)

| 기능 | 공용으로 있는 것 | 화면마다 복사되는 것 |
|---|---|---|
| 헤더 정렬(▲▼ 겹침, 최대 5단) | 동작 = `app/Support/StackedHeaderSort.php`(트레이트) — 재고관리·채권관리 사용 | 헤더 버튼 마크업 `$sortBtn` 클로저(화면마다 재작성) · **차량관리는 트레이트 안 쓰고 자체 구현**(같은 규칙, 기본 created_at desc) |
| 검색(버튼 눌러야 조회) | 검색어 정규화 `app/Support/SearchTerm.php`(trim) · 규칙 가드 `SearchRequiresButtonTest`(「치는 동안 조회 금지」) | 입력칸+Enter+버튼 마크업 · `searchNow()` · **검색 대상 컬럼 나열**(`orWhere`/`orWhereHas` 사슬) — 10개 화면 |
| 표 ↔ 폰 카드 페어 | 문서(SKILLS §11)만 | 두 벌 마크업을 매번 손으로 |

검색 대상의 실제 모양(화면별 `like` 호출 수 / 관계 검색 수): buyers 3/2 · consignees 2/2 · inventory 8/3 · receivables 5/0 · salesmen 3/0 · settlements 1/0 · transfers 2/5.
차량관리는 별도 — `search`(차번호 등) + `vinSearch`(차대번호 끝자리) + `ids`(누적 검색) 세 칸이 얽혀 있다.

## 2. 공용으로 만들 때 세 층

| 층 | 역할 | 이미 있나 |
|---|---|---|
| ① 부품(PHP 트레이트) | 프로퍼티·메서드·URL 상태·페이지 리셋·쿼리 적용 | 정렬 ✅ `StackedHeaderSort` / 검색 ❌ 신설 `ListSearch` |
| ② 겉모양(Blade 컴포넌트) | 검색칸+버튼, 정렬 헤더(▲▼·순번) 한 군데 | ❌ 신설 `<x-erp.search-bar>` · `<x-erp.sort-th>` |
| ③ 새 화면 틀 + 가드 | ①②가 꽂힌 빈 목록 화면 stub · 「틀을 안 쓰면 빨개지는」 정적 테스트 | ❌ 신설 |

「기본적으로 적용」의 실체는 **③** 이다 — 부품이 있어도 안 쓰면 그만이라, 새 화면의 출발점을 틀로 고정하고 테스트가 복사본을 잡아야 공용이 유지된다(`SearchRequiresButtonTest` 가 이미 그 역할을 검색 규칙에 대해 한다).

## 3. 헤더 겹침 정렬 — 화면이 넣는 것 (간단)

`StackedHeaderSort` 가 이미 요구하는 세 가지 그대로:

| 화면이 선언하는 것 | 예 |
|---|---|
| `sortableKeys()` 허용 키 목록 | `['name','salesman','country','created_at']` — 밖의 값은 조용히 무시(§8 #26) |
| `applySortKey($q, $col, $dir)` 키 → ORDER BY | 자기 컬럼은 그대로, 관계 칸은 이름 서브쿼리, 계산 칸은 SQL 식 |
| `afterSortChanged()` | computed 캐시 unset + `resetPage()` |

헤더 마크업은 ②로: `<th><x-erp.sort-th key="name">{{ __('buyer.col_name') }}</x-erp.sort-th></th>` — 컴포넌트가 `clickSort` 호출·▲▼·순번·호버를 그린다.
가드 = 「정렬 버튼의 key 가 전부 `sortableKeys()` 에 있나」 정적 검사(차량관리 `VehicleSortableColumnsTest` 를 전 화면으로 일반화).
차량관리 자체 구현은 **별도 단계**에서 트레이트로 옮긴다(가장 큰 화면, 기본 정렬 created_at desc 유지 필요).

## 4. 검색 — 화면이 넣는 것 (여기가 질문의 핵심)

검색이 화면마다 달라 보이는 건 **「어느 칸을 뒤지나」 하나뿐**이다. 나머지는 전부 같다.

**공용(트레이트 `ListSearch` + 컴포넌트 `<x-erp.search-bar>`)이 가져가는 것**
- `#[Url] public string $search = ''` 프로퍼티(주소창에 남아 새로고침·공유 가능)
- `searchNow()` — computed 캐시 unset + `resetPage()`. 🚫 프로퍼티와 같은 이름 금지(§8 #32)라 `search()` 가 아니라 `searchNow()` 고정
- 입력칸 `wire:model="search"` + `wire:keydown.enter="searchNow"` + [검색] 버튼 + [지우기] — 「치는 동안 조회 안 함」 규칙이 컴포넌트에 박혀서 `SearchRequiresButtonTest` 가 저절로 통과
- 검색어 정규화 `SearchTerm::of/like`(trim, 나중에 `%`·`_` 이스케이프도 여기)
- **쿼리 적용 `applySearch($q)`** — 화면이 준 「검색 대상 선언」을 읽어 `where(fn => orWhere … orWhereHas …)` 사슬을 **트레이트가 조립**한다

**화면이 선언하는 것 — 딱 하나**
```php
protected function searchColumns(): array
{
    return ['name', 'contact_email', 'contact_name', 'salesman.name', 'country.name'];
    //       ─── 자기 컬럼 ───                        ─── 「관계.컬럼」은 orWhereHas 로 ───
}
```
- 점(`.`)이 있으면 관계 검색(`orWhereHas('salesman', name like …)`), 없으면 자기 컬럼 `orWhere`.
- 표시되는 그 관계만 적는다(재고관리 주석의 교훈 — 통관·선적 바이어까지 넣으면 A 로 표시된 행이 B 로 검색된다).

**선언으로 안 되는 것 → 훅 하나로 덮어쓴다**
- 차량관리 「차대번호 끝 6자리」·「누적 검색(ids)」처럼 칸이 여러 개거나 규칙이 특수한 화면은 `applySearch($q)` 를 **오버라이드**해서 자기 식으로 쓴다. 공용 입력칸·버튼·정규화·가드는 그대로 받는다.
- 숫자 칸(금액·차대번호 뒷자리)에 `like` 를 걸 때 DB 별 캐스팅 차이가 있으면 그 화면이 훅에서 처리(SQLite 테스트 ↔ MySQL 운영 §8 #36).

가드 = 「목록 화면에 `wire:model="search"` 가 있으면 `<x-erp.search-bar>` 를 거쳐야 한다」 정적 검사 + 기존 `SearchRequiresButtonTest` 유지.

## 5. 새 화면의 모양 (착수 뒤 기대 결과)

```php
new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination, StackedHeaderSort, ListSearch;

    protected function searchColumns(): array { return ['name', 'contact_email', 'salesman.name']; }
    protected function sortableKeys(): array { return ['name', 'salesman', 'created_at']; }
    protected function applySortKey($q, string $col, string $dir) { /* salesman → 이름 서브쿼리 */ }

    #[Computed] public function rows() {
        return $this->applySort($this->applySearch(Buyer::query()->with('salesman')))->paginate($this->perPage);
    }
};
```
```blade
<x-erp.search-bar placeholder="{{ __('buyer.search_ph') }}" />
<th><x-erp.sort-th key="name">{{ __('buyer.col_name') }}</x-erp.sort-th></th>
```
검색·정렬 코드는 다시 쓰지 않는다. 화면은 **대상만** 적는다.

## 6. 착수 순서 (jin 「착수」 뒤) · 예상

| 단계 | 내용 | 예상 |
|---|---|---|
| ① 검색 트레이트 `ListSearch` + `applySearch` 선언 조립 · 단위 테스트(자기 컬럼·관계·빈 검색어·훅 오버라이드) | 반나절 |
| ② 컴포넌트 2개(`search-bar`·`sort-th`) + 기존 화면 교체(바이어·컨사이니·재고·채권·사내직원·정산·재무처리·선적요청·포워딩) — 동작 무변경, 기존 테스트가 가드 | 하루 |
| ③ 새 화면 stub(`stubs/erp-list.blade.php.stub` 또는 artisan 명령) + 정적 가드 2개(정렬 키 ↔ 버튼 · 검색칸 ↔ 컴포넌트) | 반나절 |
| ④ 차량관리 자체 정렬 → 트레이트 이관(기본 정렬 created_at desc 유지, `VehicleSortableColumnsTest` 승계) | 하루(별도) |

합 약 이틀 + 차량관리 하루. 배포는 ②가 끝날 때 한 번(화면 동작 무변경이라 위험 낮음), ④는 따로.

## 7. 이후 「공통 기능 하나 더」가 생기면

부품 하나(트레이트) + 겉모양 하나(컴포넌트) 를 만들어 **stub 에 꽂는다**. 새 화면은 자동, 기존 화면은 `use` 한 줄 + 컴포넌트 한 줄. 예: 목록 엑셀 내보내기 버튼 · 표시 열 선택(차량관리·재고관리에 이미 있는 localStorage 방식) · 폰 카드 페어.

## 8. 하지 않는 것 · 열린 것

- 🚫 전 화면 일괄 「자동 정렬」(어느 열이 정렬 가능한지는 화면마다 SQL 이 달라 선언이 필요하다 — 선언 한 배열로 줄이는 것까지가 목표).
- 🚫 검색을 `.live`(치는 동안 조회) 로 되돌리지 않는다(`SearchRequiresButtonTest`, 성능·배선 사고 이력 CLAUDE.md 15).
- 열린 것: (a) ②와 ④를 같은 배포로 묶을지 (b) 폰 카드 페어 컴포넌트까지 이번에 넣을지 (c) stub 를 artisan 명령으로 할지 파일 복사로 할지.
