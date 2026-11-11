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
 * An assignment written after a read reaches it only in a loop that
 * holds both. A body may hold several loops, one after another or one
 * inside another: the loop that counts is the one the read stands in,
 * with those around it, and an assignment written once they have all
 * ended reaches no read of theirs.
 *
 * Every case runs through both strategies.
 */
final class AssignmentAfterTheLoopsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const READ = "preg_match('/^' . \$part . '$/', \$s);";

    /**
     * $part is "x" at every read: "y" is assigned after the last loop.
     *
     * @return iterable<string, array{loops: string}>
     */
    public static function provideLoopsEndedBeforeTheAssignment(): iterable
    {
        $loop = static fn (string $flag, string $body): string => '    while ($'.$flag.") {\n        ".$body."\n        \$".$flag." = false;\n    }\n";

        yield 'the first of three loops' => ['loops' => $loop('a', self::READ).$loop('b', '$a = true;').$loop('c', '$a = true;')];
        yield 'the second of three loops' => ['loops' => $loop('a', '$b = true;').$loop('b', self::READ).$loop('c', '$a = true;')];
        yield 'the third of three loops' => ['loops' => $loop('a', '$b = true;').$loop('b', '$c = true;').$loop('c', self::READ)];
        yield 'a loop inside another' => ['loops' => "    while (\$a) {\n        while (\$b) {\n            ".self::READ."\n            \$b = false;\n        }\n\n        \$a = false;\n    }\n"];
        yield 'the second of two loops inside another' => ['loops' => "    while (\$a) {\n        while (\$b) {\n            \$b = false;\n        }\n\n        while (\$c) {\n            ".self::READ."\n            \$c = false;\n        }\n    }\n"];
    }

    #[Test]
    #[DataProvider('provideLoopsEndedBeforeTheAssignment')]
    public function test_an_assignment_after_every_loop_of_the_read_does_not_reach_it(string $loops): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$a, bool \$b, bool \$c): void\n{\n    \$part = 'x';\n".$loops."\n    \$part = 'y';\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^x$/', 'at' => "'/^' . \$part . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$part . '$/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The assignment stands in the outer loop, after the inner one that
     * holds the read: the second turn of the outer loop reads "y".
     */
    #[Test]
    public function test_an_assignment_in_the_loop_around_the_loop_of_the_read_reaches_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $a, bool $b): void
            {
                $part = 'x';
                while ($a) {
                    while ($b) {
                        preg_match('/^' . $part . '$/', $s);
                        $b = false;
                    }

                    $part = 'y';
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }
}
