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

use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A condition the code tests before a call keeps, of the values a variable
 * may hold, those PHP lets through: the others never reach the call.
 *
 * The condition is read when PHP evaluates it on the value alone: the
 * variable, a byte of it ($p[0], $p[-1]), substr(), str_starts_with(),
 * str_ends_with(), str_contains(), strpos(), strlen(), literals, the
 * comparisons and the boolean operators. Any other condition keeps every
 * value. A value the condition lets through is linted as before, whether
 * it is a valid pattern or not; no value is ever dropped because the
 * others are patterns.
 *
 * PHP 8.4.27 and 7.3.33 (php -r): "ab"[5] and "ab"[-3] are "" with a
 * warning (a notice on 7.3); substr("abc", 5) is "" on 8.4 and false on
 * 7.3; strpos("abc", "") is 0 on 8.4 and false with a warning on 7.3;
 * "1 " == "1" is true on 8.4 and false on 7.3. The lint reads none of
 * those three as known.
 *
 * php/pie, ResolveDependencyWithComposer: the loop below calls preg_match()
 * with its fifth value alone, without a warning.
 *
 * PHP itself is the oracle of what reaches the call: the code of a case
 * runs, in a namespace of its own whose preg_match() records its pattern,
 * and every value it records is one the lint keeps.
 */
final class GuardedValuesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const VALUES = "['plain', '/slash/', '#hash#', '~t~i']";

    private const PIE = <<<'PHP_WRAP'
        <?php

        namespace Php\Pie;

        final class Advisor
        {
            public function advise(string $buildProvider): bool
            {
                $rpmProviders = [
                    'AlmaLinux',
                    'CentOS',
                    'Fedora Project',
                    'Red Hat, Inc.',
                    '|^Remi\'s RPM repository <https://rpms.remirepo.net/>|',
                    'Rocky Enterprise Software Foundation',
                ];
                foreach ($rpmProviders as $rpmProvider) {
                    if ($buildProvider === $rpmProvider || ($rpmProvider[0] === '|' && preg_match($rpmProvider, $buildProvider))) {
                        return true;
                    }
                }

                return false;
            }
        }

        PHP_WRAP;

    /**
     * @var list<string> the patterns the code under test passed to preg_match() while it ran
     */
    public static array $reached = [];

    private static int $runs = 0;

    /**
     * How many statements the run of the code under test may still take.
     */
    private static int $budget = 0;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    #[Test]
    public function test_the_values_a_guard_rejects_never_reach_the_call(): void
    {
        $remi = "|^Remi's RPM repository <https://rpms.remirepo.net/>|";
        $advise = static function (string $namespace, string $provider): mixed {
            $advisor = $namespace.'\\Advisor';
            $callable = [new $advisor(), 'advise'];

            return \is_callable($callable) ? $callable($provider) : null;
        };
        $this->assertSame([$remi], $this->reached(self::PIE, $advise, ['AlmaLinux', 'CentOS', "Remi's RPM repository <https://rpms.remirepo.net/>", 'Debian']));
        $this->assertTrue(EngineProbe::compiles($remi), 'PCRE compiles the pattern the loop passes.');
        foreach (['AlmaLinux', 'CentOS', 'Fedora Project', 'Red Hat, Inc.', 'Rocky Enterprise Software Foundation'] as $name) {
            $this->assertStringContainsString('Delimiter must not be alphanumeric', EngineProbe::warning($name) ?? '', 'PCRE refuses "'.$name.'" as a pattern: it would be an error, did it reach the call.');
        }

        $this->assertExtractedByBoth([
            ['pattern' => "|^Remi's RPM repository <https://rpms.remirepo.net/>|", 'at' => "'|^Remi", 'source' => 'preg_match()', 'location' => 'via $rpmProvider'],
        ], self::PIE);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$rpmProvider', 'at' => '$rpmProvider, $buildProvider)', 'source' => 'preg_match()'],
        ], self::PIE);
    }

    #[Test]
    public function test_the_lint_reports_nothing_on_the_loop_of_pie(): void
    {
        foreach ([PhpParserExtractionStrategy::class, TokenBasedExtractionStrategy::class] as $strategy) {
            $this->assertSame([], self::issues($this->lint($strategy, self::PIE)), $strategy);
        }
    }

    #[Test]
    public function test_the_lint_still_reports_a_value_the_guard_lets_through(): void
    {
        $code = self::looping("['plain', '|abc']", "if (\$p[0] === '|') {\n            \$found += preg_match(\$p, \$s);\n        }");
        $this->assertSame(['|abc'], $this->reached($code));
        $this->assertStringContainsString("No ending delimiter '|' found", EngineProbe::warning('|abc') ?? '');

        foreach ([PhpParserExtractionStrategy::class, TokenBasedExtractionStrategy::class] as $strategy) {
            $this->assertSame([[1, 'regex.delimiter.unclosed']], self::identifiers($this->lint($strategy, $code)), $strategy);
        }
    }

    /**
     * Each condition of the list, over the four values of VALUES: the
     * values it lets through.
     *
     * @return iterable<string, array{condition: string, kept: list<string>}>
     */
    public static function provideConditions(): iterable
    {
        yield 'the first byte' => ['condition' => "\$p[0] === '/'", 'kept' => ['/slash/']];
        yield 'the first byte, written on the right' => ['condition' => "'/' === \$p[0]", 'kept' => ['/slash/']];
        yield 'not the first byte' => ['condition' => "\$p[0] !== '/'", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'the last byte' => ['condition' => "\$p[-1] === '#'", 'kept' => ['#hash#']];
        yield 'a byte out of the value' => ['condition' => "\$p[6] === '/'", 'kept' => ['/slash/']];
        yield 'a loose comparison of two texts without a digit' => ['condition' => "\$p[0] == '#'", 'kept' => ['#hash#']];
        yield 'a loose difference' => ['condition' => "\$p[0] != 'p'", 'kept' => ['/slash/', '#hash#', '~t~i']];
        yield 'a double-quoted literal' => ['condition' => '$p[0] === "/"', 'kept' => ['/slash/']];
        yield 'the whole value' => ['condition' => "\$p !== 'plain'", 'kept' => ['/slash/', '#hash#', '~t~i']];
        yield 'the whole value, loosely' => ['condition' => "'plain' != \$p", 'kept' => ['/slash/', '#hash#', '~t~i']];
        yield 'the value as a boolean' => ['condition' => '$p', 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'str_starts_with()' => ['condition' => "str_starts_with(\$p, '~')", 'kept' => ['~t~i']];
        yield 'str_starts_with() of the global namespace' => ['condition' => "\\str_starts_with(\$p, '~')", 'kept' => ['~t~i']];
        yield 'str_ends_with()' => ['condition' => "str_ends_with(\$p, '/')", 'kept' => ['/slash/']];
        yield 'str_contains()' => ['condition' => "str_contains(\$p, 'has')", 'kept' => ['#hash#']];
        yield 'not str_contains()' => ['condition' => "!str_contains(\$p, 'a')", 'kept' => ['~t~i']];
        yield 'strpos() at the start' => ['condition' => "strpos(\$p, '/') === 0", 'kept' => ['/slash/']];
        yield 'strpos() somewhere' => ['condition' => "strpos(\$p, '#') !== false", 'kept' => ['#hash#']];
        yield 'strpos() in a literal' => ['condition' => "false !== strpos('/#~', \$p[0])", 'kept' => ['/slash/', '#hash#', '~t~i']];
        yield 'substr() of one byte' => ['condition' => "substr(\$p, 0, 1) === '#'", 'kept' => ['#hash#']];
        yield 'substr() from the end' => ['condition' => "substr(\$p, -1) === '/'", 'kept' => ['/slash/']];
        yield 'strlen() above' => ['condition' => 'strlen($p) > 5', 'kept' => ['/slash/', '#hash#']];
        yield 'strlen() at most' => ['condition' => 'strlen($p) <= 5', 'kept' => ['plain', '~t~i']];
        yield 'strlen() exactly' => ['condition' => 'strlen($p) === 4', 'kept' => ['~t~i']];
        yield 'strlen() below, written on the right' => ['condition' => '6 > strlen($p)', 'kept' => ['plain', '~t~i']];
        yield 'strlen() at least' => ['condition' => 'strlen($p) >= 7', 'kept' => ['/slash/']];
        yield 'strlen() below' => ['condition' => 'strlen($p) < 5', 'kept' => ['~t~i']];
        yield 'either of two' => ['condition' => "\$p[0] === '/' || \$p[0] === '#'", 'kept' => ['/slash/', '#hash#']];
        yield 'both of two' => ['condition' => "\$p[0] === '/' && \$p[-1] === '/'", 'kept' => ['/slash/']];
        yield 'both of two, by and' => ['condition' => "(\$p[0] === '~' and \$p[-1] === 'i')", 'kept' => ['~t~i']];
        yield 'either of two, by or' => ['condition' => "(\$p[0] === '~' or \$p[0] === '#')", 'kept' => ['#hash#', '~t~i']];
        yield 'a negated group' => ['condition' => "!(\$p[0] === '/' || \$p[0] === '#')", 'kept' => ['plain', '~t~i']];
        yield 'a known part beside an unknown one' => ['condition' => "\$p[0] === '/' && \$s !== ''", 'kept' => ['/slash/']];
        yield 'an unknown part beside a known one, by or' => ['condition' => "\$s === '' || \$p[0] === '/'", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'true and a byte' => ['condition' => "true && \$p[0] === '#'", 'kept' => ['#hash#']];
        yield 'null compared' => ['condition' => "null !== \$p && \$p[0] === '#'", 'kept' => ['#hash#']];
        yield 'an escape in a double-quoted literal' => ['condition' => '$p[0] === "\\x2F"', 'kept' => ['/slash/']];
        yield 'an offset written in hexadecimal' => ['condition' => "\$p[0x0] === '#'", 'kept' => ['#hash#']];
        yield 'names written in capitals' => ['condition' => "STR_STARTS_WITH(\$p, '~') === TRUE", 'kept' => ['~t~i']];
        yield 'a comment in the condition' => ['condition' => "\$p[0] /* the delimiter */ === '/'", 'kept' => ['/slash/']];
        yield 'a double negation' => ['condition' => "!(!(\$p[0] === '/'))", 'kept' => ['/slash/']];
        yield 'a trailing comma in a call' => ['condition' => "str_starts_with(\$p, '#',)", 'kept' => ['#hash#']];
        yield 'a negated byte' => ['condition' => '!$p[9]', 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'a byte of another variable' => ['condition' => "\$s[0] !== 'x' && \$p[0] === '/'", 'kept' => ['/slash/']];
        yield 'a call with an argument not known' => ['condition' => "str_contains(\$p, \$s) && \$p[0] === '#'", 'kept' => ['#hash#']];
        yield 'strlen() of an integer' => ['condition' => "strlen(12) === 2 && \$p[0] === '#'", 'kept' => ['#hash#']];
        yield 'substr() from a text offset' => ['condition' => "substr(\$p, '0', 1) === '#' && \$p[-1] === '#'", 'kept' => ['#hash#']];
        yield 'a nowdoc' => ['condition' => "\$p[0] === <<<'X'\n            /\n            X", 'kept' => ['/slash/']];
        yield 'a heredoc' => ['condition' => "\$p[-1] === <<<X\n            #\n            X", 'kept' => ['#hash#']];
        yield 'str_starts_with() of an integer' => ['condition' => "str_starts_with(\$p, 1) && \$p[0] === '#'", 'kept' => ['#hash#']];
        yield 'a call of a variable' => ['condition' => "\$p[0] === '/' && \$given('x')", 'kept' => ['/slash/']];
    }

    /**
     * @param list<string> $kept
     */
    #[Test]
    #[DataProvider('provideConditions')]
    public function test_a_condition_keeps_the_values_php_lets_through(string $condition, array $kept): void
    {
        $code = self::looping(self::VALUES, 'if ('.$condition.") {\n            \$found += preg_match(\$p, \$s);\n        }");

        $this->assertKeepsWhatPhpPasses($code, $kept);
        $this->assertSame($kept, $this->patternsReadByBoth($code));
        $this->assertSame([self::state($kept)], $this->statesReadByBoth($code));
    }

    /**
     * A condition the lint does not evaluate, or one whose result PHP 7
     * and PHP 8 do not agree on, keeps every value.
     *
     * @return iterable<string, array{values: string, condition: string}>
     */
    public static function provideConditionsNotRead(): iterable
    {
        yield 'a parameter' => ['values' => self::VALUES, 'condition' => '$p[0] === $s'];
        yield 'a constant' => ['values' => self::VALUES, 'condition' => '$p[0] === PHP_EOL'];
        yield 'a concatenation' => ['values' => self::VALUES, 'condition' => "\$p[0].'x' === '/x'"];
        yield 'a cast' => ['values' => self::VALUES, 'condition' => "(string) \$p === 'plain'"];
        yield 'a spaceship' => ['values' => self::VALUES, 'condition' => "0 === (\$p[0] <=> '/')"];
        yield 'xor' => ['values' => self::VALUES, 'condition' => "(\$p[0] === '/' xor \$s === '')"];
        yield 'a bitwise and' => ['values' => self::VALUES, 'condition' => "\$p[0] === '/' & \$p[-1] === '/'"];
        yield 'an offset that is no literal' => ['values' => self::VALUES, 'condition' => "\$p[\$s] === '/'"];
        yield 'a named argument' => ['values' => self::VALUES, 'condition' => "str_starts_with(haystack: \$p, needle: '/')"];
        yield 'a spread argument' => ['values' => self::VALUES, 'condition' => "str_starts_with(...[\$p, '/'])"];
        yield 'a function the list does not hold' => ['values' => self::VALUES, 'condition' => "ctype_alpha('x'.\$p)"];
        yield 'substr() past the end' => ['values' => self::VALUES, 'condition' => "substr(\$p, 10) === ''"];
        yield 'strpos() of an empty needle' => ['values' => self::VALUES, 'condition' => "strpos(\$p, '') === 0"];
        yield 'strpos() with an offset' => ['values' => self::VALUES, 'condition' => "strpos(\$p, '/', 1) === false"];
        yield 'a loose comparison of numeric texts' => ['values' => "['1', '/1/']", 'condition' => "\$p == '1 '"];
        yield 'a loose comparison of a text and an integer' => ['values' => "['1', '/1/']", 'condition' => '$p == 1'];
        yield 'an ordering of texts' => ['values' => self::VALUES, 'condition' => "\$p < 'q'"];
        yield 'str_contains() of an empty needle, which a polyfill of PHP 7 may answer otherwise' => ['values' => self::VALUES, 'condition' => "!str_contains(\$p, '')"];
        yield 'str_starts_with() of an empty needle' => ['values' => self::VALUES, 'condition' => "!str_starts_with(\$p, '')"];
        yield 'a nowdoc joined to a text' => ['values' => self::VALUES, 'condition' => "\$p[0] === <<<'X'\n            /\n            X . ''"];
        yield 'a heredoc that interpolates' => ['values' => self::VALUES, 'condition' => "\$p[0] === <<<X\n            \$s\n            X"];
        yield 'an arrow function, whose body takes the and after it' => ['values' => self::VALUES, 'condition' => "fn () => 0 and \$p[0] === '|'"];
        yield 'an arrow function in parentheses before &&' => ['values' => self::VALUES, 'condition' => "(fn () => 0 and \$p[0] === '|') && \$s !== ''"];
    }

    #[Test]
    #[DataProvider('provideConditionsNotRead')]
    public function test_a_condition_not_read_keeps_every_value(string $values, string $condition): void
    {
        $code = self::looping($values, 'if ('.$condition.") {\n            \$found += preg_match(\$p, \$s);\n        }");
        $all = $this->patternsReadByBoth(self::looping($values, '$found += preg_match($p, $s);'));

        $this->assertNotSame([], $all);
        $this->assertSame($all, $this->patternsReadByBoth($code));
    }

    /**
     * Where the read stands: the right operand of && and "and" runs where
     * the left one holds, of || and "or" where it fails; a branch of an
     * "if" where its condition holds and the earlier ones fail; the rest of
     * a block after an "if" that only leaves it.
     *
     * @return iterable<string, array{loop: string, kept: list<string>}>
     */
    public static function providePositions(): iterable
    {
        yield 'the right operand of &&, as pie writes it' => ['loop' => "if (\$s === \$p || (\$p[0] === '/' && preg_match(\$p, \$s))) {\n            \$found++;\n        }", 'kept' => ['/slash/']];
        yield 'the right operand of "and"' => ['loop' => "\$p[0] === '#' and \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'the right operand of ||' => ['loop' => "if (\$p[0] !== '/' || preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['/slash/']];
        yield 'the right operand of "or"' => ['loop' => "\$p[0] !== '#' or \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'a chain of &&' => ['loop' => "if (\$p[0] === '/' && \$p[-1] === '/' && preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['/slash/']];
        yield 'the middle operand of a chain of &&' => ['loop' => "if (\$p[0] !== 'p' && \$p[-1] === '#' && preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['#hash#']];
        yield 'the middle operand of a chain of ||' => ['loop' => "if (\$p[0] === 'p' || \$p[0] === '/' || preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['#hash#', '~t~i']];
        yield 'the middle operand of a chain of "and"' => ['loop' => "\$p[0] !== 'p' and \$p[-1] === 'i' and \$found += preg_match(\$p, \$s);", 'kept' => ['~t~i']];
        yield 'the middle operand of a chain of "or"' => ['loop' => "\$p[0] === 'p' or \$p[0] === '~' or \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/', '#hash#']];
        yield 'a read in the middle of a chain' => ['loop' => "if (\$p[0] === '/' && preg_match(\$p, \$s) && \$p[-1] === '/') {\n            \$found++;\n        }", 'kept' => ['/slash/']];
        yield 'a chain after an operand longer than the reach' => ['loop' => 'if ($s'.str_repeat(' . $s', 300)." !== '' && \$p[0] === '/' && preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['/slash/']];
        yield 'a ternary in the right operand of "or"' => ['loop' => "\$p[0] === '#' or \$found += \$s === '' ? 1 : preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '~t~i']];
        yield '|| then &&' => ['loop' => "if (\$p[0] === '~' || \$p[-1] === '#' && preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['#hash#']];
        yield '&& then ||' => ['loop' => "if (\$p[0] === '/' && \$p[-1] === '/' || preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'a group of && then ||' => ['loop' => "if ((\$p[0] === '/' && \$p[-1] === '/') || preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'the branch of an if' => ['loop' => "if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['/slash/']];
        yield 'the branch of an if of one statement' => ['loop' => "if (\$p[0] === '/') \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/']];
        yield 'an elseif' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        } elseif (\$p[0] === '#') {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['#hash#']];
        yield 'an else if' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        } else if (\$p[-1] === 'i') {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['~t~i']];
        yield 'an else' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        } elseif (\$p[0] === '#') {\n            \$found -= 2;\n        } else {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['plain', '~t~i']];
        yield 'an if inside an if' => ['loop' => "if (\$p[0] !== 'p') {\n            if (\$p[-1] !== '/') {\n                \$found += preg_match(\$p, \$s);\n            }\n        }", 'kept' => ['#hash#', '~t~i']];
        yield 'after an early continue' => ['loop' => "if (\$p[0] !== '/') {\n            continue;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/']];
        yield 'after an early continue of one statement' => ['loop' => "if (!str_starts_with(\$p, '#')) continue;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'after an early break' => ['loop' => "if (\$p[0] === 'p') {\n            break;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/', '#hash#', '~t~i']];
        yield 'after an early throw' => ['loop' => "if (\$p[0] !== '~') {\n            throw new \\LogicException('no');\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['~t~i']];
        yield 'after an early return' => ['loop' => "if (\$p[0] !== '#') {\n            return 0;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'after an if with an else, which leaves nothing' => ['loop' => "if (\$p[0] !== '/') {\n            continue;\n        } else {\n            \$found--;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'after an if whose body does more than leave' => ['loop' => "if (\$p[0] !== '/') {\n            \$found--;\n            continue;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'before the guard' => ['loop' => "\$found += preg_match(\$p, \$s);\n        if (\$p[0] !== '/') {\n            continue;\n        }", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'out of the branch' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'the left operand of &&' => ['loop' => "if (preg_match(\$p, \$s) && \$p[0] === '/') {\n            \$found++;\n        }", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'after "print", which binds tighter than "and"' => ['loop' => "print \$p[0] !== '/' and \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'an assignment on the left of "and"' => ['loop' => "\$is = \$p[0] === '/' and \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'a ternary on the left of "and"' => ['loop' => "\$s === '' ? \$p[0] === '/' : true and \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'an early exit that is the body of another if' => ['loop' => "if (\$s !== '') if (\$p[0] !== '/') continue;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'an early exit after an else' => ['loop' => "if (\$s === '') {\n            \$found--;\n        } else if (\$p[0] !== '/') continue;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'an early exit in a block' => ['loop' => "{\n            if (\$p[0] !== '/') {\n                continue;\n            }\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['/slash/']];
        yield 'out of the block of an early exit' => ['loop' => "if (\$s !== '') {\n            if (\$p[0] !== '/') {\n                continue;\n            }\n            \$found--;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'after an early exit, past a later if and its else' => ['loop' => "if (\$p[0] !== '/') {\n            continue;\n        }\n        if (\$s === '') {\n            \$found--;\n        } else {\n            \$found -= 2;\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/']];
        yield 'after an early exit, past a later if of one statement and its else' => ['loop' => "if (\$p[0] !== '/') {\n            continue;\n        }\n        if (\$s === '') \$found--; else \$found -= 2;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/']];
        yield 'after an early exit, past a later if of the alternative syntax' => ['loop' => "if (\$p[0] !== '#') {\n            continue;\n        }\n        if (\$s === ''):\n            \$found--;\n        else:\n            \$found -= 2;\n        endif;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'after an early exit, past a later foreach of the alternative syntax' => ['loop' => "if (\$p[0] !== '~') {\n            continue;\n        }\n        foreach ([1, 2] as \$step):\n            \$found += \$step;\n        endforeach;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['~t~i']];
        yield 'after an early exit by exit' => ['loop' => "if (\$p[0] !== '/') {\n            exit(1);\n        }\n        \$found += preg_match(\$p, \$s);", 'kept' => ['/slash/']];
        yield 'after an early exit by die' => ['loop' => "if (\$p[0] !== '#') die;\n        \$found += preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'the && of an if of one statement' => ['loop' => "if (\$s !== '') \$p[0] === '/' && preg_match(\$p, \$s) && \$found++;", 'kept' => ['/slash/']];
        yield 'the && of a while of one statement' => ['loop' => "while (\$found < 0) \$p[0] === '#' && preg_match(\$p, \$s) && \$found++;", 'kept' => ['#hash#']];
        yield 'a read in the condition of an elseif' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        } elseif (preg_match(\$p, \$s)) {\n            \$found++;\n        }", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'an else after several elseif' => ['loop' => "if (\$p[0] === '/') {\n            \$found--;\n        } elseif (\$p[0] === '#') {\n            \$found -= 2;\n        } elseif (\$p[0] === 'p') {\n            \$found -= 3;\n        } else {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['~t~i']];
        yield 'an empty branch before the else' => ['loop' => "if (\$p[0] === '/') {\n        } else {\n            \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'an early break in a case of a switch' => ['loop' => "switch (\$s) {\n            case 'x':\n                if (\$p[0] !== '#') {\n                    break;\n                }\n                \$found += preg_match(\$p, \$s);\n        }", 'kept' => ['#hash#']];
    }

    /**
     * @param list<string> $kept
     */
    #[Test]
    #[DataProvider('providePositions')]
    public function test_a_guard_holds_where_php_runs_the_read_after_it(string $loop, array $kept): void
    {
        $code = self::looping(self::VALUES, $loop);

        $this->assertKeepsWhatPhpPasses($code, $kept);
        $this->assertSame($kept, $this->patternsReadByBoth($code));
        $this->assertSame([self::state($kept)], $this->statesReadByBoth($code));
    }

    #[Test]
    public function test_each_read_keeps_the_values_of_its_own_branch(): void
    {
        $code = self::looping(self::VALUES, "if (\$p[0] === '#') {\n            \$found += preg_match(\$p, \$s);\n        } else {\n            \$found += preg_match(\$p, \$given);\n        }");

        $this->assertExtractedByBoth([
            ['pattern' => 'plain', 'at' => "'plain'", 'location' => 'via $p'],
            ['pattern' => '/slash/', 'at' => "'/slash/'", 'location' => 'via $p'],
            ['pattern' => '#hash#', 'at' => "'#hash#'", 'location' => 'via $p'],
            ['pattern' => '~t~i', 'at' => "'~t~i'", 'location' => 'via $p'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$p', 'at' => '$p, $s)'],
            ['state' => 'finite_set', 'expression' => '$p', 'at' => '$p, $given)'],
        ], $code);
    }

    #[Test]
    public function test_a_guard_no_value_passes_leaves_nothing_known(): void
    {
        $code = self::looping("['plain', 'other']", "if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }");

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['opaque'], $this->statesReadByBoth($code));
    }

    /**
     * The ternary, "?:" and the arms of a match are read by the PHP parser
     * only: the tokenizer does not see where their branches end, and keeps
     * every value, as it does for the guards on the PHP version.
     *
     * @return iterable<string, array{loop: string, kept: list<string>}>
     */
    public static function provideExpressionBranches(): iterable
    {
        yield 'the true arm of a ternary' => ['loop' => "\$found += \$p[0] === '/' ? preg_match(\$p, \$s) : 0;", 'kept' => ['/slash/']];
        yield 'the false arm of a ternary' => ['loop' => "\$found += \$p[0] !== '#' ? 0 : preg_match(\$p, \$s);", 'kept' => ['#hash#']];
        yield 'the right arm of ?:' => ['loop' => "\$found += (\$p[0] === '/' ?: preg_match(\$p, \$s));", 'kept' => ['plain', '#hash#', '~t~i']];
        yield 'an arm of match (true)' => ['loop' => "\$found += match (true) {\n            str_starts_with(\$p, '#'), str_starts_with(\$p, '~') => preg_match(\$p, \$s),\n            default => 0,\n        };", 'kept' => ['#hash#', '~t~i']];
        yield 'the default arm of match (true)' => ['loop' => "\$found += match (true) {\n            str_starts_with(\$p, '#') => 0,\n            default => preg_match(\$p, \$s),\n        };", 'kept' => ['plain', '/slash/', '~t~i']];
        yield 'an arm of a match on a byte' => ['loop' => "\$found += match (\$p[0]) {\n            '/' => preg_match(\$p, \$s),\n            default => 0,\n        };", 'kept' => ['/slash/']];
        yield 'an arm after another of a match on a byte' => ['loop' => "\$found += match (\$p[-1]) {\n            '/' => 0,\n            '#', 'i' => preg_match(\$p, \$s),\n            default => 0,\n        };", 'kept' => ['#hash#', '~t~i']];
        yield 'an arm whose condition the lint does not read' => ['loop' => "\$found += match (true) {\n            \$s === '' => 0,\n            default => preg_match(\$p, \$s),\n        };", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'match (true) on a value that is no boolean' => ['loop' => "\$found += match (true) {\n            strpos(\$p, '/') => 0,\n            default => preg_match(\$p, \$s),\n        };", 'kept' => ['plain', '/slash/', '#hash#', '~t~i']];
        yield 'an arm after the default one' => ['loop' => "\$found += match (\$p[0]) {\n            default => 0,\n            '#' => preg_match(\$p, \$s),\n        };", 'kept' => ['#hash#']];
        yield 'the default arm written first' => ['loop' => "\$found += match (\$p[0]) {\n            default => preg_match(\$p, \$s),\n            '#', '/' => 0,\n        };", 'kept' => ['plain', '~t~i']];
        yield 'the branch of an if in the alternative syntax' => ['loop' => "if (\$p[0] === '/'):\n            \$found += preg_match(\$p, \$s);\n        endif;", 'kept' => ['/slash/']];
        yield 'the else of an if in the alternative syntax' => ['loop' => "if (\$p[0] === '/'):\n            \$found--;\n        else:\n            \$found += preg_match(\$p, \$s);\n        endif;", 'kept' => ['plain', '#hash#', '~t~i']];
    }

    /**
     * @param list<string> $kept
     */
    #[Test]
    #[DataProvider('provideExpressionBranches')]
    public function test_the_php_parser_reads_the_branches_of_an_expression(string $loop, array $kept): void
    {
        $code = self::looping(self::VALUES, $loop);
        $this->assertKeepsWhatPhpPasses($code, $kept);

        if (class_exists(ParserFactory::class)) {
            $this->assertSame($kept, $this->patternsReadBy(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code));
        }

        $this->assertSame(['plain', '/slash/', '#hash#', '~t~i'], $this->patternsReadBy(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code));
    }

    /**
     * A guard narrows a read only when nothing writes the variable between
     * the two: an assignment there may give it a value the guard never saw,
     * and so may the head of a loop.
     *
     * @return iterable<string, array{code: string, kept: list<string>}>
     */
    public static function provideWritesBetween(): iterable
    {
        yield 'an assignment after the guard' => [
            'code' => self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] === '/') {\n        \$p = \$given === 'x' ? 'c' : '/d/';\n        \$found += preg_match(\$p, \$s);\n    }"),
            'kept' => ['/a/', 'b', 'c', '/d/'],
        ];
        yield 'an assignment in the condition' => [
            'code' => self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] === '/' && (\$p = \$given === 'x' ? 'c' : '/d/') && preg_match(\$p, \$s)) {\n        \$found++;\n    }"),
            'kept' => ['/a/', 'b', 'c', '/d/'],
        ];
        yield 'an assignment later in a loop the guarded read runs in' => [
            'code' => self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] === '/') {\n        while (\$found < 3) {\n            \$found += preg_match(\$p, \$s);\n            \$p = \$given === 'x' ? 'c' : '/d/';\n        }\n    }"),
            'kept' => ['/a/', 'b', 'c', '/d/'],
        ];
        yield 'a guard before the loop that assigns the variable' => [
            'code' => self::running("\$p = '/z/';\n    if (\$p[0] === '/') {\n        foreach (['plain', '/slash/'] as \$p) {\n            \$found += preg_match(\$p, \$s);\n        }\n    }"),
            'kept' => ['plain', '/slash/'],
        ];
        yield 'an assignment before the guard' => [
            'code' => self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] === '/') {\n        \$found += preg_match(\$p, \$s);\n    }"),
            'kept' => ['/a/'],
        ];
        yield 'an assignment before the guard in the loop that runs both' => [
            'code' => self::running("while (\$found < 3) {\n        \$p = \$given === '' ? '/a/' : 'b';\n        if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }\n    }"),
            'kept' => ['/a/'],
        ];
        yield 'an assignment after the guarded stretch' => [
            'code' => self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] !== '/') {\n        return 0;\n    }\n    \$found += preg_match(\$p, \$s);"),
            'kept' => ['/a/'],
        ];
    }

    /**
     * @param list<string> $kept
     */
    #[Test]
    #[DataProvider('provideWritesBetween')]
    public function test_a_write_between_the_guard_and_the_read_keeps_every_value(string $code, array $kept): void
    {
        $this->assertKeepsWhatPhpPasses($code, $kept);
        $this->assertSame($kept, $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_closure_in_the_guarded_stretch_reads_its_own_variable(): void
    {
        $code = self::looping("['plain', '/slash/']", "if (\$p[0] === '/') {\n            \$read = static function (): int {\n                \$p = 'inner';\n\n                return preg_match(\$p, 'x');\n            };\n            \$found += \$read();\n        }");

        $arrow = self::looping(self::VALUES, "if (\$p[0] === '/') {\n            \$found += (static fn (string \$p): int => preg_match(\$p, 'x'))('/');\n        }\n        \$found += preg_match(\$p, \$s);");

        $this->assertSame(['inner'], $this->patternsReadByBoth($code));
        $this->assertSame(['plain', '/slash/', '#hash#', '~t~i'], $this->patternsReadByBoth($arrow));
        $this->assertSame(['opaque', 'finite_set'], $this->statesReadByBoth($arrow));
    }

    #[Test]
    public function test_a_variable_of_the_file_level_is_narrowed_too(): void
    {
        // At the file level, an array access between the head and the read
        // leaves the target unread whatever guards it: a comparison of the
        // variable itself is among the code FileLevelCode lets stand there.
        $target = "<?php\n\n\$s = 'x';\nforeach (['plain', '/slash/'] as \$p) {\n    if (\$p !== 'plain') {\n        preg_match(\$p, \$s);\n    }\n}\n";
        $plain = "<?php\n\n\$p = PHP_INT_SIZE > 4 ? 'plain' : '/slash/';\nif (\$p === '/slash/') {\n    preg_match(\$p, 'x');\n}\n";

        $this->assertSame(['/slash/'], $this->patternsReadByBoth($target));
        $this->assertSame(['/slash/'], $this->patternsReadByBoth($plain));
    }

    #[Test]
    public function test_a_function_of_the_namespace_is_not_read_as_php_s(): void
    {
        $code = "<?php\n\nnamespace App;\n\nfunction str_starts_with(string \$haystack, string \$needle): bool\n{\n    return true;\n}\n\nfunction run(string \$s): int\n{\n    \$found = 0;\n    foreach (['plain', '/slash/'] as \$p) {\n        if (str_starts_with(\$p, '/')) {\n            \$found += preg_match(\$p, \$s);\n        }\n    }\n\n    return \$found;\n}\n";

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['opaque'], $this->statesReadByBoth($code));
    }

    #[Test]
    public function test_a_function_of_php_the_list_holds_leaves_the_variable_read(): void
    {
        $code = self::looping("['plain', '/slash/']", "if (strlen(\$p) > 0 && str_contains(\$p, 'a') && substr(\$p, 0, 1) !== '' && strpos(\$p, 'a') !== false && !str_ends_with(\$p, '!') && !str_starts_with(\$p, '!')) {\n            \$found += preg_match(\$p, \$s);\n        }");

        $this->assertSame(['plain', '/slash/'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_the_next_case_of_a_switch_is_out_of_the_rest_of_the_block(): void
    {
        $code = self::looping(self::VALUES, "switch (\$s) {\n            case 'x':\n                if (\$p[0] !== '#') {\n                    break;\n                }\n                \$found += preg_match(\$p, \$s);\n                break;\n            case 'y':\n                \$found += preg_match(\$p, \$given);\n        }");

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$p', 'at' => '$p, $s)'],
            ['state' => 'finite_set', 'expression' => '$p', 'at' => '$p, $given)'],
        ], $code);
    }

    #[Test]
    public function test_an_early_exit_in_a_foreach_of_the_alternative_syntax_holds_up_to_its_end(): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$found = 0;\n    foreach (".self::VALUES." as \$p):\n        if (\$p[0] !== '/') {\n            continue;\n        }\n        \$found += preg_match(\$p, \$s);\n    endforeach;\n\n    return \$found;\n}\n";

        $this->assertSame(['/slash/'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_guard_on_a_composed_read_keeps_the_values_that_pass(): void
    {
        $code = self::looping(self::VALUES, "if (\$p[0] === '/') {\n            \$found += preg_match(\$p.'i', \$s);\n        }");

        $this->assertSame(['/slash/i'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_variable_assigned_in_the_guarded_stretch_keeps_the_values_that_pass(): void
    {
        $inside = self::looping(self::VALUES, "if (\$p[0] === '#') {\n            \$re = \$p;\n            \$found += preg_match(\$re, \$s);\n        }");
        $before = self::looping(self::VALUES, "\$re = \$p;\n        if (\$p[0] === '#') {\n            \$found += preg_match(\$re, \$s);\n        }");

        $this->assertSame(['#hash#'], $this->patternsReadByBoth($inside));
        $this->assertSame(['plain', '/slash/', '#hash#', '~t~i'], $this->patternsReadByBoth($before));
    }

    #[Test]
    public function test_a_guard_on_the_key_target_keeps_the_keys_that_pass(): void
    {
        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$found = 0;\n    foreach (['/key/' => 1, 'name' => 2] as \$re => \$weight) {\n        if (\$re[0] === '/') {\n            \$found += \$weight * preg_match(\$re, \$s);\n        }\n    }\n\n    return \$found;\n}\n";

        $this->assertSame(['/key/'], $this->patternsReadByBoth($code));
    }

    /**
     * A loop inside another takes the same target: in the inner body, a
     * guard holds for the values of the inner loop; a guard in the outer
     * body, before the inner head, holds for none of them.
     */
    #[Test]
    public function test_a_loop_inside_another_takes_the_target_again(): void
    {
        $inner = self::running("foreach (['/a/', 'b'] as \$re) {\n        foreach (['/c/', 'd'] as \$re) {\n            if (\$re[0] === '/') {\n                \$found += preg_match(\$re, \$s);\n            }\n        }\n    }");
        $outer = self::running("foreach (['/a/', 'b'] as \$re) {\n        if (\$re[0] === '/') {\n            foreach (['/c/', 'd'] as \$re) {\n                \$found += preg_match(\$re, \$s);\n            }\n        }\n    }");

        $this->assertSame(['/c/'], $this->patternsReadByBoth($inner));
        $this->assertSame(['/c/', 'd'], $this->patternsReadByBoth($outer));
    }

    /**
     * Thousands of guards: a chain of conditions before one read, and a
     * read under each of as many conditions, in one loop. Eight times the
     * guards take about eight times as long, not eight times eight.
     */
    #[Test]
    public function test_guards_are_read_in_linear_time(): void
    {
        $shapes = [
            'a chain' => static fn (int $guards): string => self::looping(self::VALUES, 'if ('.str_repeat("\$p[0] !== 'x' && ", $guards)."preg_match(\$p, \$s)) {\n            \$found++;\n        }"),
            'siblings' => static fn (int $guards): string => self::looping(self::VALUES, str_repeat("if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }\n        ", $guards)),
            'an elseif chain' => static fn (int $guards): string => self::looping(self::VALUES, 'if ($p === \'v0\') {'.implode('} elseif ($p === \'v', array_map(static fn (int $i): string => $i."') {\n            \$found += preg_match(\$p, \$s);\n        ", range(1, $guards)))."} else {\n            \$found += preg_match(\$p, \$given);\n        }"),
            'early exits between reads' => static fn (int $guards): string => self::looping(self::VALUES, implode('', array_map(static fn (int $i): string => "if (\$p === 'v".$i."') {\n            continue;\n        }\n        \$found += preg_match(\$p, \$s);\n        ", range(1, $guards)))),
            'a long condition' => static fn (int $guards): string => self::looping(self::VALUES, 'if ('.str_repeat('$p !== \'x\' && ', $guards)."\$s !== '') {\n            \$found += preg_match(\$p, \$s);\n        }"),
            'chains of chains' => static fn (int $guards): string => self::looping(self::VALUES, implode(' || ', array_fill(0, intdiv($guards, 10), implode(' && ', array_fill(0, 10, "\$p !== 'x'")))).' || $found += preg_match($p, $s);'),
            'match arms' => static fn (int $guards): string => self::looping(self::VALUES, "\$found += match (\$p) {\n".implode('', array_map(static fn (int $i): string => "            'v".$i."' => preg_match(\$p, \$s),\n", range(1, $guards)))."            default => preg_match(\$p, \$given),\n        };"),
        ];

        foreach ([PhpParserExtractionStrategy::class, TokenBasedExtractionStrategy::class] as $strategy) {
            if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
                continue;
            }

            foreach ($shapes as $shape => $code) {
                $small = min(array_map(fn (): float => $this->timed($strategy, $code(250)), range(1, 3)));
                $large = min(array_map(fn (): float => $this->timed($strategy, $code(2000)), range(1, 3)));

                $this->assertLessThan(24 * $small, $large, \sprintf('%s, %s: %.3fs for 250 guards, %.3fs for 2000.', $strategy, $shape, $small, $large));
            }
        }
    }

    #[Test]
    public function test_a_guard_holds_in_its_own_loop_of_a_target_assigned_by_two(): void
    {
        $code = self::running("foreach (['/a/', 'b'] as \$p) {\n        if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }\n    }\n    foreach (['/c/', 'd'] as \$p) {\n        \$found += preg_match(\$p, \$given);\n    }");

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$p', 'at' => '$p, $s)'],
            ['state' => 'finite_set', 'expression' => '$p', 'at' => '$p, $given)'],
        ], $code);
    }

    #[Test]
    public function test_an_item_of_several_values_keeps_those_that_pass(): void
    {
        $code = self::running("foreach ([\$given === '' ? '/a/' : 'b', \$given === 'x' ? 'c' : '#d#'] as \$p) {\n        if (\$p[0] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }\n        if (\$p[0] !== '/') {\n            \$found += preg_match(\$p, \$given);\n        }\n    }");

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$p', 'at' => '$p, $s)'],
            ['state' => 'finite_set', 'expression' => '$p', 'at' => '$p, $given)'],
        ], $code);
        $this->assertSame(['/a/', 'b', 'c', '#d#'], $this->patternsReadByBoth($code));
    }

    /**
     * A function the condition does not evaluate may take the variable by
     * reference: passed to it, the variable holds nothing the lint reads,
     * whatever guards the read.
     */
    #[Test]
    public function test_a_call_given_the_variable_leaves_it_unread(): void
    {
        $code = self::looping(self::VALUES, "if (ctype_alpha(\$p)) {\n            \$found += preg_match(\$p, \$s);\n        }");

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['opaque'], $this->statesReadByBoth($code));
    }

    /**
     * A function of the namespace another file of the project declares is
     * the one PHP calls: the call is not str_starts_with() of PHP, and it
     * may write the variable.
     */
    #[Test]
    public function test_a_function_another_file_declares_is_not_read_as_php_s(): void
    {
        $reader = "<?php\n\nnamespace App;\n\nfunction run(string \$s): int\n{\n    \$found = 0;\n    foreach (['plain', '/slash/'] as \$p) {\n        if (str_starts_with(\$p, '/')) {\n            \$found += preg_match(\$p, \$s);\n        }\n    }\n\n    return \$found;\n}\n";
        $polyfill = "<?php\n\nnamespace App;\n\nfunction str_starts_with(string \$haystack, string \$needle): bool\n{\n    return true;\n}\n";

        foreach ([PhpParserExtractionStrategy::class, TokenBasedExtractionStrategy::class] as $strategy) {
            if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
                continue;
            }

            $alone = $this->makeProject(['src/Reader.php' => $reader]);
            $both = $this->makeProject(['src/Reader.php' => $reader, 'src/Polyfill.php' => $polyfill]);
            $extractor = new PatternExtractor(new $strategy([], PatternFunctionRegistry::native()));
            $read = static fn (string $project): array => array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $extractor->extract([$project.'/src'], [], null, 1)));

            $this->assertSame(['/slash/'], $read($alone), $strategy);
            $this->assertSame([], $read($both), $strategy);
        }
    }

    /**
     * mbstring.func_overload, on PHP 7, reads substr(), strpos() and
     * strlen() by character: a value past ASCII is kept whatever they
     * give, one within ASCII reads the same either way.
     *
     * @return iterable<string, array{condition: string, kept: list<string>}>
     */
    public static function provideOverloadedFunctions(): iterable
    {
        yield 'substr()' => ['condition' => "substr(\$p, 1, 1) === 'é'", 'kept' => ['/é(/']];
        yield 'strpos()' => ['condition' => "strpos(\$p, '(') === 2", 'kept' => ['/é(/']];
        yield 'strlen()' => ['condition' => 'strlen($p) === 5', 'kept' => ['/é(/']];
        yield 'a byte, which no setting overloads' => ['condition' => "\$p[1] === 'o'", 'kept' => ['/ok/']];
    }

    /**
     * @param list<string> $kept
     */
    #[Test]
    #[DataProvider('provideOverloadedFunctions')]
    public function test_a_value_past_ascii_is_kept_by_what_mbstring_may_overload(string $condition, array $kept): void
    {
        $code = self::looping("['/é(/', '/ok/']", 'if ('.$condition.") {\n            \$found += preg_match(\$p, \$s);\n        }");

        $this->assertKeepsWhatPhpPasses($code, $kept);
        $this->assertSame($kept, $this->patternsReadByBoth($code));
    }

    /**
     * The right operand of "or" and "and" ends at the ":" of the "case" or
     * of the ternary it stands in: what follows runs whatever they hold.
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideColonsAfterALowOperator(): iterable
    {
        yield 'or in a case label' => ['loop' => "switch (true) {\n            case \$p[0] === '|' or \$p[0] === '~':\n                \$found += preg_match(\$p, \$s);\n                break;\n        }"];
        yield 'and in a case label' => ['loop' => "switch (\$given) {\n            case \$p[0] === '|' and 1:\n                \$found += preg_match(\$p, \$s);\n        }"];
        yield 'or in the middle arm of a ternary' => ['loop' => "\$found += \$s === '' ? \$given or \$p[0] !== '|' or 1 : preg_match(\$p, \$s);"];
        yield 'and in the middle arm of a ternary' => ['loop' => "\$found += \$s === '' ? \$given and \$p[0] === '|' and 1 : preg_match(\$p, \$s);"];
    }

    #[Test]
    #[DataProvider('provideColonsAfterALowOperator')]
    public function test_a_low_operator_guards_nothing_past_the_colon_around_it(string $loop): void
    {
        $code = self::looping("['|a(|', '#b#']", $loop);

        $this->assertContains('|a(|', $this->reached($code));
        $this->assertKeepsWhatPhpPasses($code, ['|a(|', '#b#']);
        $this->assertSame(['|a(|', '#b#'], $this->patternsReadByBoth($code));
    }

    /**
     * parse_str() and mb_parse_str() given one argument write the
     * variables of the scope, on PHP 7, as extract() does.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideScopeWriters(): iterable
    {
        yield 'parse_str()' => ['call' => "parse_str('p=c(');"];
        yield 'mb_parse_str()' => ['call' => "mb_parse_str('p=c(');"];
        yield 'parse_str() of a spread' => ['call' => "parse_str(...['p=c(']);"];
    }

    #[Test]
    #[DataProvider('provideScopeWriters')]
    public function test_a_call_that_writes_the_scope_leaves_the_variable_unread(string $call): void
    {
        $code = self::running("\$p = \$given === '' ? '|a|' : 'b(';\n    if (\$p[0] === '|') {\n        ".$call."\n        \$found += preg_match(\$p, \$s);\n    }");
        $out = self::running("\$p = \$given === '' ? '|a|' : 'b(';\n    parse_str(strtolower('p=c('), \$query);\n    if (\$p[0] === '|') {\n        \$found += preg_match(\$p, \$s);\n    }");

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['|a|'], $this->patternsReadByBoth($out));
    }

    #[Test]
    public function test_an_early_exit_ending_the_file_guards_nothing(): void
    {
        $code = "<?php\n\n\$v = '/a/';\npreg_match(\$v, 'x');\nif (\$v !== 'b') {\n    return;\n}";

        $this->assertSame(['/a/'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_match_with_no_arm_is_read(): void
    {
        $code = self::running("\$p = \$given === '' ? '/a/' : 'b';\n    \$found += \$given === 'x' ? match (\$s) {} : 0;\n    if (\$p[0] === '/') {\n        \$found += preg_match(\$p, \$s);\n    }");

        $this->assertSame(['/a/'], $this->patternsReadByBoth($code));
    }

    /**
     * A callable taken from extract() or parse_str() runs nothing: PHP
     * refuses to call extract() or compact() dynamically, and parse_str()
     * so called with one argument, which only PHP 8.1 and later write.
     *
     * @return iterable<string, array{callable: string}>
     */
    public static function provideCallablesOfScopeWriters(): iterable
    {
        yield 'parse_str(...)' => ['callable' => 'parse_str(...)'];
        yield 'mb_parse_str(...)' => ['callable' => 'mb_parse_str(...)'];
        yield 'extract(...)' => ['callable' => 'extract(...)'];
        yield 'compact(...)' => ['callable' => 'compact(...)'];
    }

    #[Test]
    #[DataProvider('provideCallablesOfScopeWriters')]
    public function test_a_callable_taken_from_a_scope_writer_leaves_the_variables_read(string $callable): void
    {
        $code = self::running("\$p = '/b/';\n    \$parser = ".$callable.";\n    \$found += preg_match(\$p, \$s);");

        $this->assertSame(['/b/'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_guarded_parameter_stays_unknown(): void
    {
        $code = "<?php\n\nfunction run(string \$p, string \$s): int\n{\n    if (\$p[0] === '/') {\n        return preg_match(\$p, \$s);\n    }\n\n    return 0;\n}\n";

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['opaque'], $this->statesReadByBoth($code));
    }

    /**
     * The tokenizer reads files the PHP parser refuses: a condition broken
     * there narrows nothing, and the reading goes on.
     *
     * @return iterable<string, array{code: string, patterns: list<string>}>
     */
    public static function provideBrokenConditions(): iterable
    {
        $all = ['plain', '/slash/', '#hash#', '~t~i'];

        yield 'two equalities in a row' => ['code' => self::looping(self::VALUES, "if (\$p[0] == '/' == true) {\n            \$found += preg_match(\$p, \$s);\n        }"), 'patterns' => $all];
        yield 'nothing after &&' => ['code' => self::looping(self::VALUES, "\$ok = \$p[0] === '/' && ;\n        \$found += preg_match(\$p, \$s);"), 'patterns' => $all];
        yield 'two && in a row' => ['code' => self::looping(self::VALUES, "if (\$p[0] === '/' && && preg_match(\$p, \$s)) {\n            \$found++;\n        }"), 'patterns' => $all];
        yield 'an argument left empty' => ['code' => self::looping(self::VALUES, "if (str_starts_with(\$p, , '/')) {\n            \$found += preg_match(\$p, \$s);\n        }"), 'patterns' => $all];
        yield 'an empty offset' => ['code' => self::looping(self::VALUES, "if (\$p[] === '/') {\n            \$found += preg_match(\$p, \$s);\n        }"), 'patterns' => $all];
        // The parenthesis closes the loop's head for the tokenizer: the
        // target is read nowhere, as it was before any guard was read.
        yield 'a parenthesis closing nothing' => ['code' => self::looping(self::VALUES, "\$ok = \$p[0] === '/') && \$found += preg_match(\$p, \$s);"), 'patterns' => []];
        // At the file level, where no other bracket is open around them.
        yield 'a parenthesis closing nothing at the file level' => ['code' => "<?php\n\n\$v = '/a/';\n\$ok = \$v[0] === '/') && preg_match(\$v, 'x');\n", 'patterns' => []];
        yield 'an operand left open at the end of the file' => ['code' => "<?php\n\n\$v = '/a/';\npreg_match(\$v, 'x');\n\$ok = \$v[0] === '/' && (\$v", 'patterns' => ['/a/']];
        yield 'an early exit before a parenthesis left open' => ['code' => "<?php\n\n\$v = '/a/';\nif (\$v !== 'b') {\n    return;\n}\npreg_match(\$v, 'x');\n(", 'patterns' => []];
        yield 'an operand left open' => ['code' => self::looping(self::VALUES, "\$found += preg_match(\$p, \$s);\n        \$ok = \$p[0] === '/' && (\$s"), 'patterns' => $all];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideBrokenConditions')]
    public function test_a_broken_condition_narrows_nothing(string $code, array $patterns): void
    {
        $this->assertSame($patterns, $this->patternsReadBy(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code));
    }

    #[Test]
    public function test_a_condition_in_a_class_body_guards_nothing(): void
    {
        $code = "<?php\n\nfinal class Flags\n{\n    private const BOTH = true && false;\n\n    public function run(string \$s): int\n    {\n        \$found = 0;\n        foreach (".self::VALUES." as \$p) {\n            \$found += preg_match(\$p, \$s);\n        }\n\n        return \$found;\n    }\n}\n";

        $this->assertSame(['plain', '/slash/', '#hash#', '~t~i'], $this->patternsReadByBoth($code));
    }

    #[Test]
    public function test_a_body_with_a_goto_narrows_nothing(): void
    {
        $code = self::running("\$p = \$given === '' ? '/a/' : 'b';\n    if (\$p[0] === '/') {\n        \$found += preg_match(\$p, \$s);\n    }\n    goto end;\n    end:");

        $this->assertSame(['/a/', 'b'], $this->patternsReadByBoth($code));
    }

    /**
     * Every pattern PHP passes to preg_match() when the code runs is one
     * of those the lint keeps.
     *
     * @param list<string> $kept
     */
    private function assertKeepsWhatPhpPasses(string $code, array $kept): void
    {
        $this->assertSame([], array_values(array_diff($this->reached($code), $kept)), 'PHP passes to preg_match() a value the lint leaves out.');
    }

    /**
     * The patterns PHP passes to preg_match() when the code runs, once
     * for each subject and each $given of the samples ('', 'x' and a
     * function name): the code runs in a namespace of its own, whose
     * preg_match() records its pattern and matches. A throw ends a run,
     * and so do exit and die, written as one, and a loop past 100,000
     * statements; warnings and output are left out.
     *
     * @param (\Closure(string, string, string): mixed)|null $run     how a run calls the code, given its namespace, the subject and $given:
     *                                                                the method run() of Matcher, or the function run()
     * @param list<string>                                   $samples the subjects
     *
     * @return list<string>
     */
    private function reached(string $code, ?\Closure $run = null, array $samples = ['', 'x']): array
    {
        $namespace = 'PHPRegex\\Tests\\Oracle\\Guarded'.++self::$runs;
        $body = str_replace(['exit(1);', 'die;'], ['throw new \\RuntimeException();', 'throw new \\RuntimeException();'], (string) preg_replace('/^<\?php\s*(?:namespace [^;]+;)?/', '', $code));
        $recorder = "function preg_match(string \$pattern, string \$subject = '', mixed ...\$rest): int\n{\n    \\".self::class."::\$reached[] = \$pattern;\n\n    return 1;\n}\n\n";
        require $this->makeProject(['oracle.php' => "<?php\n\nnamespace ".$namespace.";\n\ndeclare(ticks=1);\n\n".$recorder.$body]).'/oracle.php';

        $run ??= static function (string $namespace, string $s, string $given): mixed {
            $matcher = $namespace.'\\Matcher';
            $callable = class_exists($matcher, false) ? [new $matcher(), 'run'] : $namespace.'\\run';

            return \is_callable($callable) ? $callable($s, $given) : null;
        };
        self::$reached = [];
        $tick = static function (): void {
            if (0 === --self::$budget) {
                throw new \RuntimeException('A loop of the code never ends.');
            }
        };
        register_tick_function($tick);
        set_error_handler(static fn (): bool => true);
        ob_start();

        try {
            foreach ($samples as $s) {
                foreach (['', 'x', 'strlen'] as $given) {
                    self::$budget = 100_000;

                    try {
                        $run($namespace, $s, $given);
                    } catch (\Throwable) {
                        // The run ends here, as it would in PHP.
                    }
                }
            }
        } finally {
            ob_end_clean();
            restore_error_handler();
            unregister_tick_function($tick);
        }

        return array_values(array_unique(self::$reached));
    }

    /**
     * The class of the snippets, whose method run() loops over the values
     * with $p, then returns $found.
     */
    private static function looping(string $values, string $body): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    public function run(string \$s, string \$given): int\n    {\n        \$found = 0;\n        foreach (".$values." as \$p) {\n        ".$body."\n        }\n\n        return \$found;\n    }\n}\n";
    }

    /**
     * A function that runs the statements, then returns $found.
     */
    private static function running(string $statements): string
    {
        return "<?php\n\nfunction run(string \$s, string \$given): int\n{\n    \$found = 0;\n    ".$statements."\n\n    return \$found;\n}\n";
    }

    /**
     * @param list<string> $kept
     */
    private static function state(array $kept): string
    {
        return match (\count($kept)) {
            0 => 'opaque',
            1 => 'constant',
            default => 'finite_set',
        };
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private function timed(string $strategy, string $code): float
    {
        $file = $this->makeProject(['guards.php' => $code]).'/guards.php';
        $extractor = new $strategy([], PatternFunctionRegistry::native());

        CpuClock::timed(static fn (): array => $extractor->extract([$file]), $elapsed);

        return max($elapsed, 0.001);
    }

    /**
     * @return list<string>
     */
    private function patternsReadBy(ExtractorInterface $strategy, string $code): array
    {
        $directory = $this->makeProject(['subject.php' => $code]);

        return array_map(static fn (array $read): string => $read['pattern'], $this->readWith($strategy, [$directory.'/subject.php']));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private function lint(string $strategy, string $code): LintReport
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $project = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}', 'src/a.php' => $code]);
        $target = ProjectTarget::resolve(null, null, [], $project, []);
        $parser = RegexParser::create($target->regexOptions() + ['runtime_pcre_validation' => false]);
        $service = new LintService(
            new AnalysisService($parser, range: $target->rangeParsers($parser), judged: $target->judgedRange($parser)),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy()))]),
        );
        $request = new LintRequest([$project.'/src'], [], 1, checkOptimizations: false, analysisWorkers: 1);

        return $service->analyze($service->collectPatterns($request), $request);
    }

    /**
     * The message of each issue.
     *
     * @return list<string>
     */
    private static function issues(LintReport $report): array
    {
        $issues = [];
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                $issues[] = $issue->message;
            }
        }

        return $issues;
    }

    /**
     * How many issues of each identifier, sorted.
     *
     * @return list<array{int, string|null}>
     */
    private static function identifiers(LintReport $report): array
    {
        $counts = [];
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                $id = $issue->id() ?? '';
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        ksort($counts);

        $identifiers = [];
        foreach ($counts as $id => $count) {
            $identifiers[] = [$count, '' === $id ? null : $id];
        }

        return $identifiers;
    }
}
