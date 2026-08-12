<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Support\ColumnLabel;
use Tests\TestCase;

/**
 * 🇰🇷 관리자 화면에 영문 식별자가 그대로 새는 것을 막는다 (jin 2026-07-31 — "3~4번째 얘기").
 *
 * 감사로그·알림톡 로그는 DB 값(`action`·`column_name`·`error`)을 **원문 그대로** 화면에 찍는다.
 * 그래서 새 이벤트나 새 skip 사유를 추가할 때 사전을 같이 안 고치면 조용히 영문이 노출된다.
 * 사람이 기억으로 막는 걸 네 번 실패했으므로 여기서 기계가 막는다.
 *
 * ⚠️ 이 부류는 기능 테스트로 못 잡는다 — 화면은 값이 영문이어도 정상 렌더되기 때문이다.
 *    그래서 **소스를 정적으로 스캔**한다(DB·브라우저 불필요).
 */
class AdminUiKoreanLabelTest extends TestCase
{
    /** 업무 용어라 영문/기호가 정상인 예외 — 늘리기 전에 정말 예외인지 따져볼 것. */
    private const ALLOWED_NON_HANGUL = [
        'TAX D/C',          // 정식 업무 용어(운영 감사로그 1,534건). 한글로 바꾸면 오히려 못 알아본다.
        'Port of Loading',
        'B/L',
        'VSL',
        'ETA',
        'DHL',
        'CC',
        'VIN',
    ];

    private function hasHangul(string $s): bool
    {
        return (bool) preg_match('/[가-힣]/u', $s);
    }

    private function assertKoreanLabel(string $label, string $context): void
    {
        if (in_array($label, self::ALLOWED_NON_HANGUL, true)) {
            return;
        }
        $this->assertTrue(
            $this->hasHangul($label),
            "{$context} 의 라벨 '{$label}' 에 한글이 없습니다. config/column_labels.php 에 한글 라벨을 넣으세요."
        );
    }

    /**
     * 🚨 핵심 가드 — 코드가 기록하는 모든 커스텀 액션은 한글 라벨을 가져야 한다.
     * `AuditLog::recordEvent($x, 'foo_bar')` 리터럴을 전부 긁어 사전과 대조한다.
     */
    public function test_every_recorded_audit_action_has_a_korean_label(): void
    {
        // ⚠️ Volt 컴포넌트(.blade.php)도 감사로그를 남긴다 — `app/` 만 훑으면 그쪽이 통째로 빠진다
        //    (2026-08-12 실측: 위임 2건·가수금 3건이 스캔 밖이었다). `.blade.php` 도 확장자가 php 다.
        $scanDirs = [base_path('app'), base_path('resources/views/livewire')];

        $actions = [];
        foreach (array_merge(...array_map(fn ($d) => $this->phpFiles($d), $scanDirs)) as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/recordEvent\s*\([^,]+,\s*'([a-z0-9_]+)'/i", $src, $m)) {
                foreach ($m[1] as $a) {
                    $actions[$a] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        $this->assertNotEmpty($actions, 'recordEvent 호출을 하나도 못 찾았습니다 — 스캔 정규식을 확인하세요.');

        foreach ($actions as $action => $file) {
            $label = ColumnLabel::action($action);
            $this->assertNotSame(
                $action, $label,
                "감사로그 액션 '{$action}' ({$file}) 에 한글 라벨이 없어 화면에 영문으로 노출됩니다. ".
                "config/column_labels.php 의 'actions' 에 추가하세요."
            );
            $this->assertKoreanLabel($label, "액션 '{$action}'");
        }
    }

    /** 알림톡 로그의 skip 사유 코드는 한글 문장으로 보여야 한다(화면에 error 원문이 찍힌다). */
    public function test_alimtalk_skip_reasons_render_in_korean(): void
    {
        $codes = [];
        foreach ($this->phpFiles(base_path('app')) as $file) {
            $src = (string) file_get_contents($file);
            // ⚠️ AlimtalkLog 를 쓰는 파일로 한정 — 안 그러면 API 응답의 `'error' => 'unprocessable'`
            //    같은 무관한 키까지 잡아 오탐이 난다(실제로 났다). 오탐 나는 가드는 곧 무시당한다.
            if (! str_contains($src, 'AlimtalkLog')) {
                continue;
            }
            if (preg_match_all("/'error'\s*=>\s*'([a-z0-9_]+)'/i", $src, $m)) {
                $codes = array_merge($codes, $m[1]);
            }
        }

        foreach (array_unique($codes) as $code) {
            $log = new AlimtalkLog(['error' => $code]);
            $this->assertTrue(
                $this->hasHangul((string) $log->errorLabel()),
                "알림톡 skip 사유 '{$code}' 가 한글로 안 보입니다. AlimtalkLog::SKIP_REASONS 에 추가하거나 ".
                '처음부터 한글 문장으로 기록하세요.'
            );
        }
    }

    /** 사전에 들어 있는 라벨 자체가 영문이면 매핑이 있어도 의미가 없다. */
    public function test_dictionary_labels_are_korean(): void
    {
        foreach (['actions', 'models', 'assistant_intents'] as $group) {
            foreach (config("column_labels.$group", []) as $key => $label) {
                if (is_string($label)) {
                    $this->assertKoreanLabel($label, "{$group}.{$key}");
                }
            }
        }
    }

    /** 값이 박히는 동적 키(바이어명 등)도 한글로 풀려야 한다. */
    public function test_dynamic_column_keys_are_humanised(): void
    {
        $this->assertSame('바이어 (AUTO SCOUT)', ColumnLabel::column('Vehicle', 'buyer:AUTO SCOUT'));
        $this->assertSame('바이어 (AUTO SCOUT)', ColumnLabel::columnAny('buyer:AUTO SCOUT'));
    }

    /** 챗봇 질문 유형은 컬럼 사전이 아니라 전용 사전으로 풀린다(권한 밖 질문 접미사 포함). */
    public function test_assistant_intents_are_humanised(): void
    {
        $this->assertSame('미수 요약', ColumnLabel::assistantIntent('receivable_summary'));
        $this->assertSame('자금 현황 (권한없음)', ColumnLabel::assistantIntent('capital_status(denied)'));
        $this->assertSame('업무 가이드', ColumnLabel::assistantIntent('guide'));
    }

    /** @return string[] */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
