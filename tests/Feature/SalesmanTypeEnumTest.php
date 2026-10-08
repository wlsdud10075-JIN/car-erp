<?php

namespace Tests\Feature;

use App\Models\Salesman;
use Tests\TestCase;

/**
 * 🚨 `salesmen.type` 코드 상수(Salesman::TYPES) ↔ DB enum 정합 (월정산 v3, 2026-10-09).
 *
 * ReceivableMethodEnumTest 와 같은 이유 — SQLite 는 enum 을 강제하지 않아 상수에만 'inspector' 를 넣어도
 * 로컬·CI 는 100% 통과하고 운영 MySQL 만 `1265 Data truncated` 로 죽는다(§8 #36). 마이그레이션 파일의
 * enum 문자열을 **정적으로 읽어** 상수와 대조한다. 유형을 늘릴 땐 상수와 ALTER 를 같은 커밋에.
 *
 * ⚠️ `salesmen` 을 FK 로 참조하는 다른 표의 마이그레이션(`->on('salesmen')`)에도 `->enum('type'` 이 있을 수 있어
 *    `Schema::create('salesmen'` / `Schema::table('salesmen'` 블록과 `ALTER TABLE salesmen` 문만 본다.
 */
class SalesmanTypeEnumTest extends TestCase
{
    private function enumFromMigrations(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files);

        $current = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            if (! preg_match("/Schema::(create|table)\('salesmen'/", $src) && ! str_contains($src, 'ALTER TABLE salesmen')) {
                continue;
            }
            $up = $this->upBody($src);
            if ($up === null) {
                continue;
            }
            if (preg_match("/Schema::(?:create|table)\('salesmen'.*?->enum\(\s*'type'\s*,\s*\[(.*?)\]/s", $up, $m)) {
                $current = $this->parseList($m[1]);
            }
            if (preg_match('/ALTER TABLE salesmen MODIFY COLUMN type ENUM\((.*?)\)/is', $up, $m)) {
                $current = $this->parseList($m[1]);
            }
        }

        return $current;
    }

    private function upBody(string $src): ?string
    {
        return preg_match('/function up\(\).*?\{(.*?)\n    \}/s', $src, $m) ? $m[1] : null;
    }

    private function parseList(string $raw): array
    {
        preg_match_all("/'([^']+)'/", $raw, $m);

        return $m[1];
    }

    public function test_code_types_match_the_db_enum(): void
    {
        $db = $this->enumFromMigrations();
        $this->assertNotEmpty($db, '마이그레이션에서 salesmen.type enum 을 못 찾았다');
        $this->assertSame(array_keys(Salesman::TYPES), $db,
            "Salesman::TYPES 와 DB enum 이 다르다 — 운영 MySQL 에서 1265 Data truncated 로 죽는다.\n코드: "
            .implode(',', array_keys(Salesman::TYPES))."\nDB  : ".implode(',', $db));
    }

    public function test_inspector_is_not_a_sales_person(): void
    {
        $this->assertArrayHasKey('inspector', Salesman::TYPES);
        $sm = new Salesman(['type' => 'inspector']);
        $this->assertTrue($sm->isInspector());
        $this->assertSame('per_unit', $sm->defaultSettlementType(), '검차직원은 정산이 없다 — 기본값 그대로(무해)');
    }
}
