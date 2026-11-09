# 목록 화면 공용 부품 — 헤더 겹침 정렬 · 검색 (기획, 2026-11-09)

> jin 2026-11-09 «공용으로 넣을 코드를 정하는 거. 차량관리에 검색기능, 헤더에 오름차순/내림차순… 새로운 내용에 헤더들이 들어갈 때
> 기본적으로 적용되게 하려면 어떻게 만들어야 가능해?» → «기획 문서로 적어줘. 일단은 헤더 중복정렬과 검색을 공통으로».
> **코드 착수 전.** 착수는 jin 「착수」 지시 뒤, 순서는 §6.
> 🔗 **board 복사본 = board 레포 `meetings/list-screen-commons.md`**(board dev `d55222d`, 2026-11-09 — jin «board 쪽에도 저장해 달라»). 용어는 양 레포 공통. 이 파일이 정본이고 board 쪽은 복사본 표기 + 여기 링크. 바뀌면 board 세션에 알려 복사본을 맞춘다(스펙 복사 금지 원칙의 예외 — jin 지시).

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

## 1-B. 🏷️ 명칭 규칙 (jin 11-09 «토글이라 하면 헷갈릴 수 있잖아 — 뭐라고 불러야 해?»)

| 부르는 이름 | 가리키는 것 | 코드 이름 | 🚫 이렇게 부르지 않는다 |
|---|---|---|---|
| **필터칩** | 누르면 조건이 걸리는 버튼 하나(「판매중」「운항중」「말소필요」「계약금」) | `<x-erp.filter-chips>` · 트레이트 `ListFilters` | 토글(켜기/끄기 스위치만 뜻한다) · pill(코드 클래스명 `tab-pill` 과 섞인다) · 탭(화면 전환과 섞인다) |
| **필터칩줄** | 칩들이 늘어선 띠 한 줄(차량관리 「전체~거래완료 · 운항중 · 말소필요 …」) | 컴포넌트 한 번 호출 = 줄 하나 | 필터 바(검색칸이 있는 줄과 섞인다) |
| **필터축** | 필터칩줄 안에서 서로 독립적으로 걸리는 묶음(진행상태 · 운항 · 작업 · board 요청). 하나만 넣을 때도 「필터축」 | `filters()` 선언의 키 하나 | 그냥 「축」(이 레포엔 운항 축·발송 축·날짜 축 등 다른 축이 많다) · 그룹·카테고리 |
| **검색칸** | 글자를 치고 [검색] 을 누르는 칸 | `<x-erp.search-bar>` · 트레이트 `ListSearch` | 필터(칩과 섞인다) |
| **정렬 헤더** | 누르면 ▲▼ 가 바뀌는 표 머리칸 | `<x-erp.sort-th>` · 트레이트 `StackedHeaderSort`(기존) | 정렬 버튼·소트 |

**jin 이 쓰는 말(11-09 확정)** — 공용 부품 셋 = **검색공용 · 헤더정렬공용 · 필터칩공용**(한 줄에 여러 필터축이면 **필터칩줄공용**). 띄어쓰기 없이 「필터칩」「필터칩줄」로 쓴다.
요청 예: «바이어 화면에 필터칩공용 넣어줘, 필터축은 활성/비활성 하나» / «차량관리처럼 필터칩줄공용으로, 필터축은 진행상태·운항·작업».

칩의 **동작 종류** 넷은 그대로: 단일 선택 · 다중 선택 · 3상태 순환 · 켜기/끄기. 「켜기/끄기」 하나만 토글이라 불러도 된다 — 그래서 전체를 토글이라 부르면 헷갈렸던 것.
대화·문서·코드 주석·가이드 전부 이 표를 따른다. 가이드(`scripts/notion-guide-publish.php`)에 사용법을 적을 때도 「필터 칩」.

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

## 4-B. 필터 칩 — 진행상태·활성/비활성 같은 「누르면 걸리는 버튼」(구 표현 「토글형 필터(pill)」 → §1-B 명칭 규칙) (jin 11-09 «이런 것도 공용으로 가능한가?» → 가능)

지금 화면들의 토글 필터는 **네 가지 동작**뿐이다(실측 2026-11-09). 공용 부품은 이 넷을 「동작 종류」로 갖고, 화면은 **키·선택지·SQL** 만 선언한다.

| 동작 종류 | 지금 쓰는 곳 | 클릭 규칙 |
|---|---|---|
| 단일 선택 | 담당자 필터·바이어 B2B·정산상태 pill 등(`tab-pill` 7화면) | 하나만 켜짐, 다시 누르면 전체 |
| 다중 선택 | 재고관리 보관위치(`toggleLocationFilter`, `__none` 포함) · 차량관리 발송 필터 | 누를 때마다 켜고 끔, 전부 끄면 전체 |
| 3상태 순환 | 차량관리 진행상태 pill(`cycleProgress`, SKILLS §9) | 회색 → 보라(이것만) → 빨강+취소선(제외) → 회색. 포함은 단일·제외는 다중 |
| 켜기/끄기 하나 | 차량관리 미수만(`toggleUnpaidFilter`)·바이어미정 | 불리언 |

**jin 이 말한 그 줄 = 차량관리 필터 띠**(jin 11-09 «전체/매입중~거래완료/운항중/도착예정/말소필요/선적필요/바이어미정/대표계약금/매입잔금/계약금») 를 위 네 동작에 대면:

| 버튼 | 프로퍼티(축) | 동작 종류 | 조건 출처 | 메모 |
|---|---|---|---|---|
| 전체 | `progressFilter`·`excludeStatuses` 리셋 | 「전체」pill | — | 진행상태 축만 리셋(다른 축은 그대로) |
| 매입중 ~ 거래완료 (10) | `progressFilter` + `excludeStatuses` | **3상태 순환** | `progress_status_cache` · 「매입중」만 `scopeProgressStage`(넓음) | 엑셀 내보내기 `exclude` 와 짝(SKILLS §9) |
| 운항중 · 도착예정 | `sailingFilter` | **단일**(다시 누르면 해제) | `Vehicle::scopeSailing` | 진행상태와 직교 — 같이 걸린다 |
| 말소필요 · 선적필요(· 통관필요 · B/L필요 · 미수금) | `taskFilter` | **단일**(하나만 켜짐) | `Vehicle::scopeAction`(단일 출처, §8 #44) | 🚫 조건을 선언에 옮겨 적지 않는다 — 스코프를 부른다 |
| 바이어미정 | `taskFilter = 'buyer_undecided'` | **단일**(말소필요와 **같은 축**) | `buyer_undecided` 플래그 | 자리만 다른 줄, 축은 작업 필터 |
| 계약금 · 매입잔금 · 대표계약금(· 대금확인) | `boardFilters[]` | **다중** | `BoardRequest::TYPE_META` | pill 은 **요청이 있는 종류만** 뜬다(건수 0 이면 숨김) |

⇒ 전부 네 동작 안에 들어간다. 다만 이 띠가 가르쳐 주는 공용 요건이 셋 더 있다:
1. **한 띠에 축이 여러 개**(진행상태·운항·작업·board 요청)이고 서로 직교해 **동시에 걸린다** — 컴포넌트는 축 단위로 그리고, 쿼리 조립은 축을 전부 AND 로 잇는다. 「전체」는 **그 축만** 리셋.
2. **선택지 숨김 규칙** — board 요청처럼 「해당 건이 있는 종류만 보인다」 가 선언에 있어야 한다(`hide_when_zero`). 건수는 `counts` 로 같이 준다.
3. **내보내기와 짝** — 진행상태 축은 엑셀 내보내기에 같은 파라미터로 전달된다. 공용 필터는 「현재 걸린 필터를 URL 파라미터 묶음으로 내놓는」 메서드(`filterQueryString()`)를 가져 내보내기·딥링크가 그걸 쓴다.

**공용(트레이트 `ListFilters` + 컴포넌트 `<x-erp.filter-chips>`)이 가져가는 것**
- 필터 값의 `#[Url]` 보존(주소창에 남아 새로고침·링크 공유), 바뀔 때 computed 캐시 unset + `resetPage()`
- 네 가지 클릭 규칙의 상태 전이(단일/다중/3상태/불리언) — 지금 화면마다 손으로 쓴 `cycle…`/`toggle…` 메서드가 하나로 모인다
- pill 겉모양(켜짐·꺼짐·제외의 색, 취소선, 건수 뱃지 자리) — 차량관리 pill 과 같은 모양이 기준
- 「전체」 pill = 그 필터 리셋
- 쿼리 적용 `applyFilters($q)` — 선언을 읽어 `whereIn` / `whereNotIn` / 불리언 `where` 를 조립

**화면이 선언하는 것**
```php
protected function filters(): array
{
    return [
        'status'   => ['type' => 'single', 'column' => 'is_active',
                       'options' => ['1' => __('common.active'), '0' => __('common.inactive')]],
        'location' => ['type' => 'multi',  'column' => 'stock_location', 'options' => Vehicle::stockLocations(),
                       'none' => '__none'],                       // 「미지정」 토큰(재고관리 규칙 그대로)
        'progress' => ['type' => 'cycle',  'column' => 'progress_status_cache', 'options' => Vehicle::PROGRESS_STAGES,
                       'apply' => fn ($q, $include, $exclude) => $q->progressStage($include)->notProgressStages($exclude)],
        'unpaid'   => ['type' => 'bool',   'apply' => fn ($q) => $q->where('sale_unpaid_amount_krw_cache', '>', 0)],
    ];
}
```
- `column` 만 주면 공용이 `whereIn/whereNotIn/where` 를 만든다. SQL 이 컬럼 하나가 아니면(「매입중」은 미지급 잔금 전체 — `scopeProgressStage`) **`apply` 클로저**로 화면이 넘긴다. 🚫 스코프 조건을 선언에 옮겨 적지 말 것(§8 #44) — 스코프를 부른다.
- 선택지는 **단일 출처**에서 가져온다(`Vehicle::stockLocations()` 처럼 회사별로 다른 목록도 포함, SKILLS §14 보관위치 규칙).
- 건수 뱃지가 필요하면 `counts` 에 `[값 => 건수]` 를 주면 컴포넌트가 붙인다(대시보드 진행 단계 스트립은 **넓히지 않는다** — 그건 분포표, CLAUDE.md 「매입중만 필터에서 넓다」).

**마크업** — `<x-erp.filter-chips name="progress" />` 한 줄. 컴포넌트가 선언의 options·type 을 읽어 pill 을 그리고 클릭을 공용 메서드(`pillClick('progress', '판매중')`)로 보낸다.

**가드** — 「선언에 있는 필터 키만 URL 로 받는다」(밖의 값은 조용히 무시, §8 #26) · 「pill 의 값이 전부 options 안에 있다」 정적 검사. 차량관리 진행상태 pill 은 export 와 짝이라(`exclude` 파라미터를 엑셀에도 전달, SKILLS §9) 옮길 때 그 짝을 테스트로 묶는다.

**착수 순서에 넣는 자리** — §6 ①(검색 트레이트) 바로 뒤에 「①-B 필터 트레이트 + chips 컴포넌트(반나절~하루)」. 기존 화면 교체는 ② 와 같이. 차량관리 진행상태 pill(3상태 + export 짝)은 ④ 와 같이 별도.

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
| ①-B 필터 칩 트레이트 `ListFilters` + `<x-erp.filter-chips>`(단일·다중·3상태·켜기/끄기 네 동작) · 단위 테스트 | 반나절~하루 |
| ② 컴포넌트 3개(`search-bar`·`sort-th`·`filter-chips`) + 기존 화면 교체(바이어·컨사이니·재고·채권·사내직원·정산·재무처리·선적요청·포워딩) — 동작 무변경, 기존 테스트가 가드 | 하루 |
| ③ 새 화면 stub(`stubs/erp-list.blade.php.stub` 또는 artisan 명령) + 정적 가드 2개(정렬 키 ↔ 버튼 · 검색칸 ↔ 컴포넌트) | 반나절 |
| ④ 차량관리 자체 정렬 → 트레이트 이관(기본 정렬 created_at desc 유지, `VehicleSortableColumnsTest` 승계) | 하루(별도) |

합 약 이틀 반~사흘 + 차량관리 하루(정렬 이관 + 진행상태 pill 이관). 배포는 ②가 끝날 때 한 번(화면 동작 무변경이라 위험 낮음), ④는 따로.

## 7. 이후 「공통 기능 하나 더」가 생기면

부품 하나(트레이트) + 겉모양 하나(컴포넌트) 를 만들어 **stub 에 꽂는다**. 새 화면은 자동, 기존 화면은 `use` 한 줄 + 컴포넌트 한 줄. 예: 목록 엑셀 내보내기 버튼 · 표시 열 선택(차량관리·재고관리에 이미 있는 localStorage 방식) · 폰 카드 페어.

## 8. 하지 않는 것 · 열린 것

- 🚫 전 화면 일괄 「자동 정렬」(어느 열이 정렬 가능한지는 화면마다 SQL 이 달라 선언이 필요하다 — 선언 한 배열로 줄이는 것까지가 목표).
- 🚫 검색을 `.live`(치는 동안 조회) 로 되돌리지 않는다(`SearchRequiresButtonTest`, 성능·배선 사고 이력 CLAUDE.md 15).
- 열린 것: (a) ②와 ④를 같은 배포로 묶을지 (b) 폰 카드 페어 컴포넌트까지 이번에 넣을지 (c) stub 를 artisan 명령으로 할지 파일 복사로 할지.
