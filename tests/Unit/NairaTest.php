<?php

namespace Tests\Unit;

use App\Support\Naira;
use PHPUnit\Framework\TestCase;

/** Messages name money the way the app shows it: no ".00" on whole naira. */
class NairaTest extends TestCase
{
    public function test_whole_naira_has_no_kobo(): void
    {
        $this->assertSame('₦20,000', Naira::format('20000.00'));
        $this->assertSame('₦1,500,000', Naira::format(1500000));
    }

    public function test_kobo_is_shown_when_there_is_some(): void
    {
        $this->assertSame('₦20,000.50', Naira::format('20000.50'));
    }
}
