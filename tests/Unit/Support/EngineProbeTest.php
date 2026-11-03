<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Unit\Support;

use PHPRegex\Tests\Support\EngineProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EngineProbeTest extends TestCase
{
    /**
     * The probe does not depend on what the test runner's handler leaves
     * for error_get_last(): a pattern the engine refuses is refused under
     * every PHPUnit release, with the engine's own words.
     */
    #[Test]
    public function test_a_pattern_the_engine_refuses_is_not_compiled(): void
    {
        $this->assertFalse(EngineProbe::compiles('/(/'));
        $this->assertStringContainsString('missing closing parenthesis at offset 1', (string) EngineProbe::warning('/(/'));
        $this->assertTrue(EngineProbe::compiles('/a/'));
        $this->assertNull(EngineProbe::warning('/a/'));
    }

    #[Test]
    public function test_the_probe_leaves_the_runners_handler_in_place(): void
    {
        $before = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        EngineProbe::compiles('/(/');
        $after = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        $this->assertSame($before, $after);
    }
}
