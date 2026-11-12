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
 * define() takes any expression as the value of its constant, where a
 * "const" takes a constant expression only. The constant is read anywhere
 * in the file, far from the place its value is written: a variable, a
 * property, sprintf() or implode() written there is a part the file does
 * not prove, whatever the same expression reads as in an argument. What
 * is written around it is kept.
 *
 * Every case runs through both strategies.
 */
final class DefineValueTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{define: string, argument: string, state: string}>
     */
    public static function provideValuesNoConstantExpressionHolds(): iterable
    {
        yield 'a pattern around a parameter' => [
            'define' => "define('SHELL', '/^' . \$word . '$/');",
            'argument' => 'SHELL',
            'state' => 'raw_shell',
        ];

        yield 'a pattern around a variable assigned a literal' => [
            'define' => "\$part = 'a';\n    define('SHELL', '/^' . \$part . '$/');",
            'argument' => 'SHELL',
            'state' => 'raw_shell',
        ];

        yield 'a variable assigned a pattern' => [
            'define' => "\$re = '/^a$/';\n    define('WHOLE', \$re);",
            'argument' => 'WHOLE',
            'state' => 'opaque',
        ];

        yield 'sprintf() as the whole value' => [
            'define' => "define('FORMATTED', sprintf('/^%s$/', 'a'));",
            'argument' => 'FORMATTED',
            'state' => 'opaque',
        ];

        yield 'sprintf() as a part of the pattern' => [
            'define' => "define('PART', sprintf('%s', 'a'));",
            'argument' => "'/^' . PART . '$/'",
            'state' => 'raw_shell',
        ];

        yield 'implode() as the whole value' => [
            'define' => "define('JOINED', implode('|', ['/^a', 'b$/']));",
            'argument' => 'JOINED',
            'state' => 'opaque',
        ];

        yield 'implode() as a part of the pattern' => [
            'define' => "define('PARTS', implode('|', ['a', 'b']));",
            'argument' => "'/^' . PARTS . '$/'",
            'state' => 'raw_shell',
        ];
    }

    #[Test]
    #[DataProvider('provideValuesNoConstantExpressionHolds')]
    public function test_what_no_constant_expression_holds_is_a_part_the_file_does_not_prove(string $define, string $argument, string $state): void
    {
        $code = "<?php\n\nfunction boot(string \$word): void\n{\n    ".$define."\n}\n\nfunction run(string \$s): int\n{\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The property is read where an argument names it; in the value of
     * the define() it is a part the file does not prove, read before the
     * argument or after it.
     */
    #[Test]
    public function test_a_property_is_read_in_an_argument_and_not_in_the_value_of_a_define(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private string $part = 'a';

                public function boot(): void
                {
                    define('BEFORE', '/^' . $this->part . '-before$/');
                    define('AFTER', '/^' . $this->part . '-after$/');
                }

                public function run(string $s): int
                {
                    return preg_match(BEFORE, $s)
                        + preg_match('/^' . $this->part . '$/', $s)
                        + preg_match(AFTER, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^a$/', 'at' => "'/^' . \$this->part . '$/', \$s)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => 'BEFORE', 'at' => 'BEFORE, $s)'],
            ['state' => 'constant', 'expression' => "'/^' . \$this->part . '$/'", 'at' => "'/^' . \$this->part . '$/', \$s)"],
            ['state' => 'raw_shell', 'expression' => 'AFTER', 'at' => 'AFTER, $s)'],
        ], $code);
    }

    /**
     * A property read as the pattern itself, then a constant whose
     * define() joins a variable: what the first read went through leaves
     * the second as it is.
     */
    #[Test]
    public function test_a_property_read_first_leaves_the_value_of_a_define_as_it_is(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private string $re = '/^default$/';

                public function run(string $s): int
                {
                    $part = 'a';
                    define('SHELL', '/^' . $part . '$/');

                    return preg_match($this->re, $s) + preg_match(SHELL, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^default$/', 'at' => "'/^default$/'", 'location' => 'via $this->re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$this->re', 'at' => '$this->re, $s)'],
            ['state' => 'raw_shell', 'expression' => 'SHELL', 'at' => 'SHELL, $s)'],
        ], $code);
    }
}
