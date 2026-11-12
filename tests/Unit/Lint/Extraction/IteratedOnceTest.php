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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a foreach iterates is read once, before the first turn: an
 * assignment the body makes to the variable it iterates does not reach
 * the loop, whatever the length of the name of the variable.
 *
 * PHP 8.4 (php -r): "$a = ['/x/']; foreach ($a as $p) { echo $p, "\n";
 * $a = ['/y/', '/z/']; }" prints "/x/" and nothing else.
 *
 * Both strategies read the same.
 */
final class IteratedOnceTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{variable: string}>
     */
    public static function provideNamesOfTheIteratedVariable(): iterable
    {
        yield 'a name of one letter' => ['variable' => '$a'];
        yield 'a name of two letters' => ['variable' => '$ab'];
        yield 'a longer name' => ['variable' => '$patterns'];
    }

    #[Test]
    #[DataProvider('provideNamesOfTheIteratedVariable')]
    public function test_an_assignment_in_the_body_does_not_reach_what_the_loop_iterates(string $variable): void
    {
        $code = "<?php\n\nfunction check(string \$s): void\n{\n    ".$variable." = ['/x/'];\n    foreach (".$variable." as \$p) {\n        preg_match(\$p, \$s);\n        ".$variable." = [\$s];\n    }\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/x/', 'at' => "'/x/'", 'location' => 'via $p'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$p', 'at' => '$p, $s)'],
        ], $code);
    }
}
