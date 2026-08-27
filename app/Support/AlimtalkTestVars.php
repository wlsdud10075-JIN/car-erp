<?php

namespace App\Support;

use App\Console\Commands\AlimtalkCapitalWeekly;
use App\Console\Commands\AlimtalkDailySummary;
use App\Console\Commands\AlimtalkMonthlyClosing;
use App\Console\Commands\AlimtalkReceivableStatus;
use App\Console\Commands\AlimtalkWeeklySummary;

/**
 * 테스트 발송용 변수 — **단일 출처** (jin 2026-08-27).
 *
 * 왜 만들었나: 기능설정의 테스트 발송이 `erp_daily_summary` 로 고정이었고, 게다가 **구버전 변수**
 *   (`선적전건수`·`선적후금액`·`미수합계`)를 넘기고 있었다. 2026-08-20 에 일일요약을 개편하면서
 *   테스트 발송만 안 따라온 것이다 — 누르면 새 변수가 안 채워져 카드가 깨진 채로 나갔다.
 *   (SKILLS §8 #38 의 그 형태 — 바꾼 쪽의 소비자를 안 훑었다.)
 *
 * 🧭 그래서 코드별로 손으로 나열하지 않는다. **템플릿이 스스로 선언한 `vars` 를 읽어** 채운다.
 *   새 템플릿이 늘어도 여기는 안 고쳐도 되고, 변수명이 바뀌면 자동으로 따라온다.
 *   집계형 5종만 실데이터 빌더가 있어 그것을 우선 쓰고, 나머지는 눈에 띄는 샘플 값을 넣는다.
 */
final class AlimtalkTestVars
{
    /** 실데이터 빌더가 있는 코드 — 대표·담당자가 실제로 받는 것과 **똑같이** 나간다. */
    private const REAL_BUILDERS = [
        'erp_daily_summary' => [AlimtalkDailySummary::class, 'buildVars'],
        'erp_weekly_summary' => [AlimtalkWeeklySummary::class, 'buildVars'],
        'erp_monthly_closing' => [AlimtalkMonthlyClosing::class, 'buildVars'],
        'erp_receivable_status' => [AlimtalkReceivableStatus::class, 'buildVars'],
        'erp_capital_weekly' => [AlimtalkCapitalWeekly::class, 'buildVars'],
    ];

    /** 이 코드는 실데이터로 나가나(true) 샘플로 나가나(false) — 화면이 그대로 알려준다. */
    public static function isRealData(string $code): bool
    {
        return isset(self::REAL_BUILDERS[$code]);
    }

    /**
     * 테스트 발송 변수. 실데이터 빌더가 있으면 그걸 쓰고, 없으면 템플릿 선언 변수로 샘플을 만든다.
     * 실데이터 빌더가 예외를 던지면(집계 대상 0건 등) 샘플로 떨어진다 — 테스트가 죽지 않게.
     */
    public static function for(string $code): array
    {
        $real = [];
        if (isset(self::REAL_BUILDERS[$code])) {
            try {
                [$class, $method] = self::REAL_BUILDERS[$code];
                $real = $class::$method();
            } catch (\Throwable) {
                $real = [];
            }
        }

        // ⚠️ 실데이터 빌더는 **빈 배열이나 일부만** 돌려줄 수 있다 — 자금보고는 스냅샷이 없으면 [] 다.
        //    그대로 보내면 `#{기준일}` 이 치환 안 된 채 나간다. 빠진 변수만 샘플로 메운다.
        //    (가드 = AlimtalkTestSendTest::test_every_template_gets_all_of_its_declared_variables)
        return $real + self::sample($code);
    }

    /**
     * 템플릿이 선언한 `vars` 를 훑어 샘플 값을 만든다.
     *   - 날짜류는 오늘, 금액류는 숫자+원, 건수류는 숫자, 목록류는 한 줄짜리 예시.
     *   - 값에 「(테스트)」를 넣지 않는다 — 카카오가 등록본과 대조하는 건 **문구 틀**이지 값이 아니지만,
     *     길이 제한(아이템 설명 20자 등)에 걸릴 수 있어 값은 짧게 유지한다.
     */
    private static function sample(string $code): array
    {
        $declared = AlimtalkTemplates::TEMPLATES[$code]['vars'] ?? [];
        $today = now()->toDateString();

        $vars = [];
        foreach ($declared as $name) {
            $vars[$name] = match (true) {
                str_contains($name, '목록') => '12가3456 · 1,000,000원',
                str_contains($name, '내역') => '12가3456 · 1,000,000원',
                str_contains($name, '날짜'), str_contains($name, '기준일'),
                str_contains($name, '일자'), str_contains($name, '월') => $today,
                str_contains($name, '금액'), str_contains($name, '매출'),
                str_contains($name, '미수'), str_contains($name, '잔액') => '1,000,000원',
                str_contains($name, '건수'), str_contains($name, '대수') => '1',
                default => '테스트',
            };
        }

        return $vars;
    }

    /**
     * 드롭다운 목록 — **등록(tmplId)된 템플릿만**. 안 뜨는 건 아직 승인·입력 전이라는 뜻이다.
     *
     * @return array<string, string> [코드 => '이름 (실데이터|샘플)']
     */
    public static function options(AlimtalkConfig $config): array
    {
        $out = [];
        foreach (AlimtalkTemplates::TEMPLATES as $code => $t) {
            if ($config->tmplId($code) === '') {
                continue;   // 등록 전 — 보내봐야 반려된다
            }
            $out[$code] = $t['name'].(self::isRealData($code) ? ' (실데이터)' : ' (샘플)');
        }

        return $out;
    }
}
