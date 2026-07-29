<?php

namespace Tests\Feature;

use App\Models\ReceivableHistory;
use Tests\TestCase;

/**
 * 🚨 회수방법 코드 화이트리스트 ↔ DB enum 정합 (2026-07-29).
 *
 * 2026-07-28 "적립금 채권관리 회수방법" 배포가 코드에만 'savings' 를 넣고 마이그레이션으로 enum 을
 * 안 늘려서, 3사 전부 적립금 사용이 죽어 있었다 — MySQL 이 `1265 Data truncated for column 'method'`
 * 를 내면서 회수이력 insert 가 실패하고, 그 여파로 차량 저장이 통째로 롤백돼 savings_used 가 0 으로 남았다.
 *
 * ⚠️ 이 부류는 **일반 기능 테스트로 절대 안 잡힌다** — 로컬/CI 는 SQLite 라 enum 을 강제하지 않아
 *    잘못된 값도 그냥 들어간다([[project_db_tier_mismatch]]). 그래서 DB 에 넣어보는 대신
 *    **마이그레이션 파일의 enum 문자열을 정적으로 읽어** 코드 상수와 대조한다(드라이버 무관).
 *
 * 회수방법을 추가할 때는 상수와 마이그레이션을 **같은 커밋에서** 함께 늘릴 것.
 */
class ReceivableMethodEnumTest extends TestCase
{
    /** 마이그레이션들에서 receivable_histories.method 의 최종 enum 값 목록을 뽑는다. */
    private function enumFromMigrations(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files);   // 파일명 = 타임스탬프 → 마지막에 이긴 정의가 최종

        $current = [];
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            if (! str_contains($src, 'receivable_histories')) {
                continue;
            }

            // ① 최초 생성: $table->enum('method', [...]);
            if (preg_match("/->enum\(\s*'method'\s*,\s*\[(.*?)\]/s", $src, $m)) {
                $current = $this->parseList($m[1]);
            }
            // ② 이후 변경: ALTER TABLE ... MODIFY COLUMN method ENUM(...) — up() 쪽만 (down 은 되돌림)
            $upBody = $this->upBody($src);
            if ($upBody !== null && preg_match('/MODIFY COLUMN method ENUM\((.*?)\)/is', $upBody, $m)) {
                $current = $this->parseList($m[1]);
            }
        }

        return $current;
    }

    private function upBody(string $src): ?string
    {
        // up() 의 본문만 — down() 의 옛 enum 을 최종값으로 오인하지 않도록.
        if (! preg_match('/function up\(\).*?\{(.*?)\n    \}/s', $src, $m)) {
            return null;
        }

        return $m[1];
    }

    private function parseList(string $raw): array
    {
        preg_match_all("/'([a-z_]+)'/i", $raw, $m);

        return $m[1];
    }

    public function test_code_whitelist_matches_migration_enum(): void
    {
        $migration = $this->enumFromMigrations();

        $this->assertNotEmpty($migration, 'receivable_histories.method enum 정의를 마이그레이션에서 못 찾았다.');

        sort($migration);
        $code = ReceivableHistory::METHODS;
        sort($code);

        $this->assertSame(
            $code,
            $migration,
            "ReceivableHistory::METHODS 와 DB enum 이 다르다.\n".
            '  코드: '.implode(',', $code)."\n".
            '  DB  : '.implode(',', $migration)."\n".
            '값을 추가했으면 ALTER TABLE ... MODIFY COLUMN method ENUM(...) 마이그레이션도 같이 넣을 것. '.
            '(SQLite 는 enum 을 강제하지 않아 이 불일치가 운영에서만 터진다.)'
        );
    }

    public function test_savings_is_allowed(): void
    {
        // 2026-07-28 회귀 자체를 못 박아둔다.
        $this->assertContains('savings', ReceivableHistory::METHODS);
        $this->assertContains('savings', $this->enumFromMigrations(), '적립금 회수방법이 DB enum 에 없다 — 3사에서 적립금 사용이 죽는다.');
    }
}
