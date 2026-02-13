<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
