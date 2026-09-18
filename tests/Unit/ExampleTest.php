<?php

namespace Tests\Unit;

use App\Support\India;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_indian_number_formats_and_duration_are_consistent(): void
    {
        $this->assertSame('+919876543210', India::phone('98765 43210'));
        $this->assertSame('+919876543210', India::phone('09876543210'));
        $this->assertSame('+919876543210', India::phone('919876543210'));
        $this->assertSame('+918012345678', India::phone('080 12345678'));
        $this->assertSame('—', India::duration(null));
        $this->assertSame('0m 00s', India::duration(0));
        $this->assertSame('2m 05s', India::duration(125));
    }
}
