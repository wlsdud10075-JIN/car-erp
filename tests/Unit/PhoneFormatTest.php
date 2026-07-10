<?php

namespace Tests\Unit;

use App\Support\PhoneFormat;
use PHPUnit\Framework\TestCase;

class PhoneFormatTest extends TestCase
{
    public function test_mobile_11_digits(): void
    {
        $this->assertSame('010-4613-6834', PhoneFormat::format('01046136834'));
    }

    public function test_corrects_wrong_hyphen(): void
    {
        // jin 2026-07-10 — 3-3-5 잔상(010-461-36834)이 들어와도 3-4-4 로 교정
        $this->assertSame('010-4613-6834', PhoneFormat::format('010-461-36834'));
    }

    public function test_mobile_10_digits(): void
    {
        $this->assertSame('011-123-4567', PhoneFormat::format('0111234567'));
    }

    public function test_seoul_landline(): void
    {
        $this->assertSame('02-1234-5678', PhoneFormat::format('0212345678'));
        $this->assertSame('02-123-4567', PhoneFormat::format('021234567'));
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(PhoneFormat::format(''));
        $this->assertNull(PhoneFormat::format('---'));
        $this->assertNull(PhoneFormat::format(null));
    }
}
