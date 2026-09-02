<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔒 브라우저 비밀번호 자동완성이 RRN·소유자명 칸을 채우는 것을 막는 정적 가드 (jin 2026-09-02 제보).
 *
 * 사고: RRN 칸이 `:type="show ? 'text' : 'password'"` 라 기본 상태가 type=password 고, 바로 앞이
 *      소유자명 텍스트칸이다. Chrome 비밀번호 관리자는 이 [text][password] 인접 쌍을 로그인 폼으로
 *      보고 **저장된 구글 자격증명을 통째로 채워 넣었다.**
 *
 * 🚨 `autocomplete="off"` 는 소용없다 — Chrome 은 비밀번호 자동완성에 한해 이 값을 **의도적으로 무시**한다.
 *    「기존 자격증명 채우기」 대상에서 빠지려면 `autocomplete="new-password"` 여야 한다.
 *
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 되돌려도 **화면은 정상 렌더되고 저장도 된다.**
 *    틀어지는 건 사용자 브라우저의 자동완성 동작뿐이라 정적 검사여야 한다.
 */
class CredentialAutofillGuardTest extends TestCase
{
    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function source(): string
    {
        return (string) file_get_contents(base_path(self::VIEW));
    }

    public function test_rrn_input_opts_out_of_credential_autofill(): void
    {
        // RRN 칸 = type 이 password 로 토글되는 유일한 입력.
        preg_match_all('/<input\b[^>]*nice_reg_owner_rrn[^>]*>/s', $this->source(), $m);

        $this->assertNotEmpty($m[0], 'RRN 입력칸을 못 찾았다 — 셀렉터가 낡았는지 확인할 것.');

        foreach ($m[0] as $tag) {
            $this->assertStringContainsString(
                'autocomplete="new-password"',
                $tag,
                'RRN 칸에 autocomplete="new-password" 가 없다. `off` 는 Chrome 이 무시하므로 '.
                '저장된 구글 비밀번호가 이 칸에 채워진다(2026-09-02 실사고).'
            );
        }
    }

    public function test_owner_name_inputs_are_not_offered_as_the_username_field(): void
    {
        preg_match_all('/<input\b[^>]*nice_reg_owner_name[^>]*>/s', $this->source(), $m);

        $this->assertNotEmpty($m[0], '소유자명 입력칸을 못 찾았다.');

        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('data-1p-ignore', $tag, '소유자명 칸의 자동완성 차단 속성이 빠졌다.');
            $this->assertStringContainsString('data-lpignore', $tag, '소유자명 칸의 자동완성 차단 속성이 빠졌다.');
        }
    }

    /** password 로 토글되는 칸이 새로 생기면 같은 사고가 재현된다 — 그때 이 테스트가 알려준다. */
    public function test_no_new_password_toggle_input_escapes_the_guard(): void
    {
        preg_match_all("/<input\b[^>]*:type=\"[^\"]*'password'[^\"]*\"[^>]*>/s", $this->source(), $m);

        foreach ($m[0] as $tag) {
            $this->assertStringContainsString(
                'autocomplete="new-password"',
                $tag,
                'password 로 토글되는 입력칸이 새로 생겼는데 autocomplete="new-password" 가 없다. '.
                '앞에 텍스트칸이 있으면 Chrome 이 로그인 폼으로 보고 자격증명을 채운다.'
            );
        }
    }
}
