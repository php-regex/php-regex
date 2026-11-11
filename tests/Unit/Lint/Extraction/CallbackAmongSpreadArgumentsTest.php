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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A function of PHP that takes its callbacks last, array_udiff_uassoc()
 * its two, calls a closure written among its last arguments when every
 * argument of the call stands at its position. A spread written after
 * the closure passes arguments the call does not count: the closure may
 * stand where an array is expected, and nothing says it is called and
 * let go. A closure passed by the name of the callback is called, after
 * a spread too.
 *
 * PHP 8.4 (php -r): array_udiff_uassoc([1, 2], [2], $closure,
 * ...['strcmp']) calls the closure; array_udiff_uassoc([1, 2], [2],
 * $closure, ...[[3], 'strcmp', 'strcmp']) throws "Argument #3 must be of
 * type array, Closure given". array_filter(...[['a', 'bb']], callback:
 * fn ($x) => 2 === strlen($x)) is [1 => 'bb'].
 *
 * Both strategies read the same.
 */
final class CallbackAmongSpreadArgumentsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_closure_followed_by_a_spread_is_not_known_to_be_one_of_the_last_callbacks(): void
    {
        $code = <<<'PHP'
            <?php

            final class Holder
            {
                const RE = '/holder/';

                public function run(array $xs, array $ys, array $rest): array
                {
                    return [
                        array_udiff_uassoc($xs, $ys, fn ($a, $b) => preg_match(self::RE, $a), 'strcmp'),
                        array_udiff_uassoc($xs, $ys, fn ($c, $d) => preg_match(self::RE, $c), ...$rest),
                        array_udiff($xs, fn ($e, $f) => preg_match(self::RE, $e), ...$rest),
                    ];
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/holder/', 'at' => "'/holder/'", 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $a)'],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $c)'],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $e)'],
        ], $code);
    }

    #[Test]
    public function test_a_closure_passed_by_the_name_of_the_callback_after_a_spread_is_called(): void
    {
        $code = <<<'PHP'
            <?php

            final class Holder
            {
                const RE = '/holder/';

                public function run(array $xs): array
                {
                    return [
                        array_filter(...[$xs], callback: fn ($a) => preg_match(self::RE, $a)),
                        array_filter(...[$xs], mode: fn ($b) => preg_match(self::RE, $b)),
                    ];
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/holder/', 'at' => "'/holder/'", 'location' => 'via self::RE'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $a)'],
            ['state' => 'opaque', 'expression' => 'self::RE', 'at' => 'self::RE, $b)'],
        ], $code);
    }
}
