<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 파일 저장·복사가 **조용히 실패**했을 때 던진다 (2026-08-10).
 *
 * Laravel 디스크는 `config/filesystems.php` 에서 전부 `'throw' => false` 라,
 * `store()`·`put()`·`copy()`·`writeStream()` 이 실패해도 **예외가 아니라 `false`** 를 리턴한다.
 * 반환값을 검사하지 않으면 그대로 다음 줄로 흘러가 **DB 만 확정**되는데, 그 형태로
 * 연동 B 첨부가 "사진 행 15건 / S3 객체 0건" 상태로 쌓인 사고가 있었다.
 *
 * 더 나쁜 경우도 있다 — 서류·도장 교체는 새 파일 저장에 실패해도 **옛 파일을 삭제 목록에 올려서**,
 * 새것도 없고 멀쩡하던 옛것까지 잃는다. 그래서 반환값이 falsy 면 여기서 끊어 트랜잭션을 되돌린다.
 *
 * 호출부는 이 예외를 잡아 사용자에게 안내하고(500 대신 토스트), 이미 올라간 파일을 정리한다.
 * 복구 가능한 실패(네트워크·권한)라 재시도가 의미 있기 때문이다.
 */
class FileStoreFailedException extends RuntimeException
{
    public function __construct(public readonly string $target = '')
    {
        parent::__construct($target !== ''
            ? "File store failed: {$target}"
            : 'File store failed');
    }
}
