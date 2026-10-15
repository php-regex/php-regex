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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * analyze() runs the ReDoS analysis redos() runs, and takes the same
 * options for it: the report is not stuck in theoretical mode.
 */
final class RegexAnalyzeOptionsTest extends TestCase
{
    #[Test]
    public function test_analyze_takes_the_redos_options_of_redos(): void
    {
        $analyze = new \ReflectionMethod(Regex::class, 'analyze');
        $redos = new \ReflectionMethod(Regex::class, 'redos');

        $describe = static fn (\ReflectionMethod $method): array => array_map(
            static fn (\ReflectionParameter $parameter): array => [
                'name' => $parameter->getName(),
                'type' => (string) $parameter->getType(),
                'default' => $parameter->isDefaultValueAvailable() ? var_export($parameter->getDefaultValue(), true) : null,
            ],
            $method->getParameters(),
        );

        $this->assertSame($describe($redos), $describe($analyze));
    }

    #[Test]
    public function test_analyze_forwards_the_mode_to_the_redos_analysis(): void
    {
        $regex = Regex::create();

        $this->assertNull($regex->analyze('/(a+)+$/')->redos->confirmation, 'Theoretical by default.');

        $confirmed = $regex->analyze('/(a+)+$/', mode: RedosMode::Confirmed)->redos;
        $this->assertSame(RedosMode::Confirmed, $confirmed->mode);
        $this->assertInstanceOf(Confirmation::class, $confirmed->confirmation, 'A confirmed analysis runs the pattern.');
    }
}
