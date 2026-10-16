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
 * A pattern that is no literal at the call is read when the file alone
 * proves its value: a constant, a local variable whose every assignment is
 * a literal, a property no code of the file writes. It is reported once, at
 * the literal it comes from, with the first call that uses it as its source
 * and the way the call reaches it as its location.
 *
 * Every case runs through both strategies: the tokenizer and
 * nikic/php-parser must read the same patterns at the same offsets.
 */
final class InFileResolutionTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * A function no bail rule touches, so each negative case shows that the
     * resolution runs and only the case at hand is left out.
     */
    private const CONTROL = <<<'PHP'

        function control_resolution(string $s): int
        {
            $control = '/control/';

            return preg_match($control, $s);
        }

        PHP;

    #[Test]
    public function test_a_self_constant_is_read_at_its_declaration(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/self-constant/', 'at' => "'/self-constant/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], <<<'PHP'
            <?php

            namespace App;

            final class Matcher
            {
                private const RE = '/self-constant/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_constant_used_by_two_calls_is_reported_once(): void
    {
        // The source is the first call in file order, not the first one the
        // declaration meets.
        $this->assertExtractedByBoth([
            ['pattern' => '/two-calls/', 'at' => "'/two-calls/'", 'source' => 'preg_split()', 'location' => 'via self::RE'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/two-calls/';

                public function split(string $s): array
                {
                    return preg_split(self::RE, $s);
                }

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            PHP);
    }

    /**
     * static:: names the class the method is called on: a subclass may
     * declare its own constant.
     */
    #[Test]
    public function test_a_static_constant_of_a_non_final_class_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/self-in-open-class/', 'at' => "'/self-in-open-class/'", 'location' => 'via self::CONTROL'],
        ], <<<'PHP'
            <?php

            class Matcher
            {
                protected const RE = '/late-static/';
                private const CONTROL = '/self-in-open-class/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(static::RE, $s) && 1 === preg_match(self::CONTROL, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_static_constant_of_a_final_class_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/static-in-final-class/', 'at' => "'/static-in-final-class/'", 'source' => 'preg_match()', 'location' => 'via static::RE'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                protected const RE = '/static-in-final-class/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(static::RE, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_static_final_constant_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/final-constant/', 'at' => "'/final-constant/'", 'location' => 'via static::RE'],
        ], <<<'PHP'
            <?php

            class Matcher
            {
                final protected const RE = '/final-constant/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(static::RE, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_class_name_constant_of_the_same_file_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/class-name/', 'at' => "'/class-name/'", 'source' => 'preg_match()', 'location' => 'via Patterns::RE'],
            ['pattern' => '/fully-qualified/', 'at' => "'/fully-qualified/'", 'source' => 'preg_replace()', 'location' => 'via \App\Patterns::OTHER'],
        ], <<<'PHP'
            <?php

            namespace App;

            class Patterns
            {
                public const RE = '/class-name/';
                public const OTHER = '/fully-qualified/';
            }

            final class Matcher
            {
                public function run(string $s): bool
                {
                    preg_replace(\App\Patterns::OTHER, '', $s);

                    return 1 === preg_match(Patterns::RE, $s);
                }
            }
            PHP);
    }

    /**
     * There is no index of the other files: a constant they declare is no
     * value this file proves.
     */
    #[Test]
    public function test_a_class_name_constant_of_another_file_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], [
            'Matcher.php' => "<?php\n\nnamespace App;\n\nfinal class Matcher\n{\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(Patterns::RE, \$s);\n    }\n}\n".self::CONTROL,
            'Patterns.php' => "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const RE = '/other-file/';\n}\n",
        ], file: 'Matcher.php');
    }

    /**
     * A class of the same short name in another namespace of the file is
     * another class.
     */
    #[Test]
    public function test_a_class_name_constant_resolves_on_the_fully_qualified_name(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            namespace Lib {
                final class Patterns
                {
                    public const RE = '/another-namespace/';
                }
            }

            namespace App {
                function run(string $s): int
                {
                    return preg_match(Patterns::RE, $s);
                }

                function control_resolution(string $s): int
                {
                    $control = '/control/';

                    return preg_match($control, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_namespace_const_and_define_are_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/namespace-const/', 'at' => "'/namespace-const/'", 'source' => 'preg_match()', 'location' => 'via RE'],
            ['pattern' => '/defined/', 'at' => "'/defined/'", 'source' => 'preg_split()', 'location' => 'via DEFINED_RE'],
            ['pattern' => '/qualified-const/', 'at' => "'/qualified-const/'", 'source' => 'preg_grep()', 'location' => 'via \App\QUALIFIED'],
        ], <<<'PHP'
            <?php

            namespace App;

            const RE = '/namespace-const/';
            const QUALIFIED = '/qualified-const/';
            define('DEFINED_RE', '/defined/');

            function run(string $s): void
            {
                preg_match(RE, $s);
                preg_split(DEFINED_RE, $s);
                preg_grep(\App\QUALIFIED, [$s]);
            }
            PHP);
    }

    /**
     * A define() whose name or value the file does not spell declares
     * nothing: a variable name, a spread value, define(...) as a callable.
     */
    #[Test]
    public function test_a_define_the_file_does_not_spell_out_declares_nothing(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            define($name, '/named-by-a-variable/');
            define('SPREAD', ...$values);
            $define = define(...);

            function run(string $s): int
            {
                return preg_match(VARIABLE, $s) + preg_match(SPREAD, $s);
            }
            PHP.self::CONTROL);
    }

    /**
     * An argument named after a keyword, h(include: 1), runs no code the
     * file does not show: the variables of the body are still read.
     */
    #[Test]
    public function test_an_argument_named_after_include_or_eval_is_no_include(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/v/', 'at' => "'/v/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s): int
            {
                $x = h(include: 1, include_once: 2, require: 3, require_once: 4, eval: 5);
                $re = '/v/';

                return preg_match($re, $s);
            }

            PHP);
    }

    /**
     * PHP keeps its own value for a constant it defines: define('PHP_EOL',
     * ...) and a global const E_ALL change nothing, PHP 8.4 still gives
     * "\n" for PHP_EOL. A name PHP does not define is read.
     */
    #[Test]
    public function test_a_constant_php_defines_keeps_its_own_value(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/t/', 'at' => "'/t/'", 'location' => 'via TRUE_'],
        ], <<<'PHP_WRAP'
            <?php

            @define('PHP_EOL', '/(/');
            const E_ALL = '/(/';
            define('TRUE_', '/t/');

            function run(string $s): int
            {
                return preg_match(PHP_EOL, $s) + preg_match(E_ALL, $s) + preg_match(TRUE_, $s);
            }

            PHP_WRAP);
    }

    /**
     * An unqualified constant is the namespace's one when it is declared,
     * the global one otherwise, as PHP resolves it.
     */
    #[Test]
    public function test_an_unqualified_constant_falls_back_to_global(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/global/', 'at' => "'/global/'", 'location' => 'via GLOBAL_RE'],
            ['pattern' => '/namespaced/', 'at' => "'/namespaced/'", 'location' => 'via SHADOWED'],
        ], <<<'PHP'
            <?php

            namespace {
                const GLOBAL_RE = '/global/';
                const SHADOWED = '/global-shadowed/';
            }

            namespace App {
                const SHADOWED = '/namespaced/';

                function run(string $s): void
                {
                    preg_match(GLOBAL_RE, $s);
                    preg_match(SHADOWED, $s);
                }
            }
            PHP);
    }

    /**
     * A whole pattern built from constants is reported at the declaration
     * of the constant the call names; the part is no pattern of its own.
     */
    #[Test]
    public function test_a_constant_built_from_constants_is_read_at_its_declaration(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/abc+/i', 'at' => "'/'.self::PART", 'source' => 'preg_match()', 'location' => 'via self::RE'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const PART = 'abc';
                private const FLAGS = 'i';
                private const RE = '/'.self::PART.'+/'.self::FLAGS;

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            PHP);
    }

    #[Test]
    public function test_a_constant_cycle_reads_nothing_and_ends(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const A = self::B;
                private const B = '/'.self::A.'/';
                private const SELF_REFERENCE = self::SELF_REFERENCE;

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::A, $s) || 1 === preg_match(self::SELF_REFERENCE, $s);
                }
            }

            function control_resolution(string $s): int
            {
                $control = '/control/';

                return preg_match($control, $s);
            }
            PHP);
    }

    /**
     * Folding follows 32 constants and no more: K1 holds the literal, each
     * next one names the one before.
     *
     * @return iterable<string, array{depth: int, expected: list<string>}>
     */
    public static function provideConstantChains(): iterable
    {
        yield 'the call reaches the literal through 32 constants' => ['depth' => 32, 'expected' => ['/control/', '/deep/']];
        yield 'one constant more' => ['depth' => 33, 'expected' => ['/control/']];
        yield 'far past the limit' => ['depth' => 2000, 'expected' => ['/control/']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideConstantChains')]
    public function test_a_constant_chain_is_folded_up_to_the_depth_limit(int $depth, array $expected): void
    {
        $constants = "    private const K1 = '/deep/';\n";
        for ($i = 2; $i <= $depth; $i++) {
            $constants .= \sprintf("    private const K%d = self::K%d;\n", $i, $i - 1);
        }

        $code = "<?php\n\nfinal class Chain\n{\n".$constants
            ."\n    public function run(string \$s): int\n    {\n        return preg_match(self::K".$depth.", \$s);\n    }\n}\n".self::CONTROL;

        $patterns = $this->patternsReadByBoth($code);
        sort($patterns);

        $this->assertSame($expected, $patterns);
    }

    #[Test]
    public function test_a_constant_array_feeds_preg_replace_values_and_callback_array_keys(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/array-a/', 'at' => "'/array-a/'", 'source' => 'preg_replace()', 'location' => 'via self::RES'],
            ['pattern' => '/array-b/', 'at' => "'/array-b/'", 'source' => 'preg_replace()', 'location' => 'via self::RES'],
            ['pattern' => '/key-a/', 'at' => "'/key-a/'", 'source' => 'preg_replace_callback_array()', 'location' => 'via self::MAP'],
            ['pattern' => '/key-b/', 'at' => "'/key-b/'", 'source' => 'preg_replace_callback_array()', 'location' => 'via self::MAP'],
        ], <<<'PHP'
            <?php

            final class Cleaner
            {
                private const RES = ['/array-a/', '/array-b/'];
                private const MAP = ['/key-a/' => 'strtoupper', '/key-b/' => 'strtolower'];

                public function run(string $s): ?string
                {
                    $s = preg_replace(self::RES, '', $s);

                    return preg_replace_callback_array(self::MAP, (string) $s);
                }
            }
            PHP);
    }

    /**
     * Only a whole pattern goes to the declaration: a pattern the call
     * assembles is the call's, one per call even when two calls assemble
     * the same one.
     */
    #[Test]
    public function test_a_composite_at_the_call_is_reported_at_the_call(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/abc/', 'at' => "'/'.self::PART.'/', \$s)", 'source' => 'preg_match()'],
            ['pattern' => '/abc/i', 'at' => "'/'.self::PART.'/i'", 'source' => 'preg_match()'],
            ['pattern' => '/abc/', 'at' => "'/'.self::PART.'/', '', \$s)", 'source' => 'preg_replace()'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const PART = 'abc';

                public function run(string $s): void
                {
                    preg_match('/'.self::PART.'/', $s);
                    preg_match('/'.self::PART.'/i', $s);
                    preg_replace('/'.self::PART.'/', '', $s);
                }
            }
            PHP);
    }

    /**
     * An empty constant is no pattern, as an empty literal is not; a
     * constant no call uses is never looked at.
     */
    #[Test]
    public function test_an_empty_or_unused_constant_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const EMPTY = '';
                private const UNUSED = '/unused/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::EMPTY, $s);
                }
            }

            function control_resolution(string $s): int
            {
                $control = '/control/';

                return preg_match($control, $s);
            }
            PHP);
    }

    /**
     * The constant's literal is decoded as PHP decodes it, its flags and
     * its delimiter kept.
     */
    #[Test]
    public function test_a_resolved_constant_is_decoded_with_its_flags_and_delimiter(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => "/\u{e9}t\u{e9}/u", 'at' => '"/\u{e9}t\u{e9}/u"', 'location' => 'via self::ACCENTED'],
            ['pattern' => '#a/b#imx', 'at' => "'#a/b#imx'", 'location' => 'via self::HASH'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const ACCENTED = "/\u{e9}t\u{e9}/u";
                private const HASH = '#a/b#imx';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::ACCENTED, $s) && 1 === preg_match(self::HASH, $s);
                }
            }
            PHP);
    }

    /**
     * PHP takes the bytes 0x80 to 0xff for letters of a name.
     */
    #[Test]
    public function test_multibyte_class_and_constant_names_are_resolved(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/multibyte-self/', 'at' => "'/multibyte-self/'", 'location' => 'via self::MOTIF'],
            ['pattern' => '/multibyte-class/', 'at' => "'/multibyte-class/'", 'location' => 'via Modèle::DÉFAUT'],
        ], <<<'PHP'
            <?php

            final class Modèle
            {
                public const MOTIF = '/multibyte-self/';
                public const DÉFAUT = '/multibyte-class/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::MOTIF, $s) && 1 === preg_match(Modèle::DÉFAUT, $s);
                }
            }
            PHP);
    }

    /**
     * A part the file cannot prove makes the whole constant unknown.
     */
    #[Test]
    public function test_a_constant_with_an_unresolvable_part_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            namespace App;

            final class Matcher
            {
                private const RE = '/'.\Other\Patterns::PART.'/';
                private const GLOBAL_PART = '/'.UNDECLARED.'/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s) || 1 === preg_match(self::GLOBAL_PART, $s);
                }
            }

            function control_resolution(string $s): int
            {
                $control = '/control/';

                return preg_match($control, $s);
            }
            PHP);
    }

    /**
     * self:: in a trait is the class that uses it, which the trait cannot
     * know.
     */
    #[Test]
    public function test_self_in_a_trait_is_not_resolved(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            trait MatchesThings
            {
                private const RE = '/trait/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }

            function control_resolution(string $s): int
            {
                $control = '/control/';

                return preg_match($control, $s);
            }
            PHP);
    }

    #[Test]
    public function test_a_local_variable_with_literal_assignments_yields_each_literal(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'source' => 'preg_match()', 'location' => 'via $re'],
            ['pattern' => '/second/i', 'at' => "'/second/i'", 'source' => 'preg_match()', 'location' => 'via $re'],
            ['pattern' => '/con-cat/', 'at' => "'/con'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s, bool $flag, bool $other): int
            {
                $re = '/first/';
                if ($flag) {
                    $re = '/second/i';
                } elseif ($other) {
                    $re = '/con'.'-cat/';
                }

                return preg_match($re, $s);
            }
            PHP);
    }

    /**
     * An assignment of a constant the file proves counts as a literal; the
     * pattern is reported where the literal is.
     */
    #[Test]
    public function test_a_local_variable_assigned_a_constant_is_read_at_the_constant(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/from-constant/', 'at' => "'/from-constant/'", 'source' => 'preg_match()'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/from-constant/';

                public function run(string $s): bool
                {
                    $re = self::RE;

                    return 1 === preg_match($re, $s);
                }
            }
            PHP);
    }

    /**
     * Each rule names a way the variable may hold a value no literal of the
     * function gives: the variable is then not read at all.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideVariableBails(): iterable
    {
        yield 'parameter' => ['body' => "function bail(string \$re, string \$s): int\n{\n    \$re = '/bail/';\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'reference taken' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    \$alias = &\$re;\n    \$alias = \$s;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'assigned by reference' => ['body' => "function bail(string \$s, string \$x): int\n{\n    \$re = '/bail/';\n    \$re = &\$x;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'concatenating assignment' => ['body' => "function bail(string \$s, string \$x): int\n{\n    \$re = '/bail/';\n    \$re .= \$x;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'compound assignment of a literal' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    \$re .= 'i';\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'null coalescing assignment' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    \$re ??= '/other/';\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'global' => ['body' => "function bail(string \$s): int\n{\n    global \$re;\n    \$re = '/bail/';\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'static' => ['body' => "function bail(string \$s): int\n{\n    static \$re = '/bail/';\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'extract()' => ['body' => "function bail(string \$s, array \$data): int\n{\n    \$re = '/bail/';\n    extract(\$data);\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'compact()' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    \$vars = compact('re');\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'variable variable' => ['body' => "function bail(string \$s, string \$name): int\n{\n    \$re = '/bail/';\n    \$\$name = \$s;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'braced variable variable' => ['body' => "function bail(string \$s, string \$name): int\n{\n    \$re = '/bail/';\n    \${\$name} = \$s;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'list() destructuring' => ['body' => "function bail(string \$s, array \$parts): int\n{\n    \$re = '/bail/';\n    list(\$re) = \$parts;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'short destructuring' => ['body' => "function bail(string \$s, array \$parts): int\n{\n    \$re = '/bail/';\n    [, \$re] = \$parts;\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'foreach value' => ['body' => "function bail(string \$s, array \$all): int\n{\n    \$re = '/bail/';\n    foreach (\$all as \$re) {\n    }\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'foreach key' => ['body' => "function bail(string \$s, array \$all): int\n{\n    \$re = '/bail/';\n    foreach (\$all as \$re => \$unused) {\n    }\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'assignment of no literal' => ['body' => "function bail(string \$s, string \$x): int\n{\n    \$re = '/bail/';\n    if (\$x) {\n        \$re = \$x;\n    }\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'catch variable' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    try {\n    } catch (\\Exception \$re) {\n    }\n\n    return preg_match(\$re, \$s);\n}\n"];
        // preg_match() writes its third argument by reference: any call may
        // take a variable by reference, and the file does not say which.
        yield 'passed to a call' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    normalize_pattern(\$re);\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'written as an output argument' => ['body' => "function bail(string \$s): int\n{\n    \$re = '/bail/';\n    preg_match('/(x)/', \$s, \$re);\n\n    return preg_match(\$re, \$s);\n}\n"];
        yield 'post-increment' => ['body' => self::bailing('string $s', '$re++;')];
        yield 'pre-increment' => ['body' => self::bailing('string $s', '++$re;')];
        yield 'post-decrement' => ['body' => self::bailing('string $s', '$re--;')];
        yield 'pre-decrement' => ['body' => self::bailing('string $s', '--$re;')];
        // Code the file does not show runs in the body, and may write any
        // of its variables.
        yield 'eval' => ['body' => self::bailing('string $s, string $code', 'eval($code);')];
        yield 'include' => ['body' => self::bailing('string $s, string $file', 'include $file;')];
        yield 'require_once' => ['body' => self::bailing('string $s, string $file', 'require_once $file;')];
        yield 'unset' => ['body' => self::bailing('string $s', 'unset($re);')];
        yield 'captured by reference in a closure' => ['body' => self::bailing('string $s, string $x', "\$write = function () use (\$x, &\$re): void {\n        \$re = \$x;\n    };")];
        yield 'reference in an array literal' => ['body' => self::bailing('string $s', '$references = [&$re];')];
        yield 'keyed reference in an array literal' => ['body' => self::bailing('string $s', "\$references = ['pattern' => &\$re];")];
        yield 'foreach value by reference' => ['body' => self::bailing('string $s, array $all', "foreach (\$all as &\$re) {\n    }")];
        // Foo::$$name names a static property, no local variable; the token
        // strategy reads the "$" of $$name alone, and both strategies leave
        // the whole body unread the same way.
        yield 'static property named by a variable' => ['body' => self::bailing('string $s, string $name', 'PatternFixer::$$name = $s;')];
        yield 'global variable variable' => ['body' => self::bailing('string $s, string $name', 'global $$name;')];
    }

    /**
     * Any call may take its argument by reference, whatever its shape: the
     * variable passed is not read.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideCallShapeBails(): iterable
    {
        yield 'an offset of the variable' => ['body' => self::bailing('string $s', 'normalize_pattern($re[0]);')];
        yield 'a parenthesised variable' => ['body' => self::bailing('string $s', 'normalize_pattern(($re));')];
        yield 'a method' => ['body' => self::bailing('string $s, object $normalizer', '$normalizer->fix($re);')];
        yield 'a nullsafe method' => ['body' => self::bailing('string $s, ?object $normalizer', '$normalizer?->fix($re);')];
        yield 'a static method' => ['body' => self::bailing('string $s', 'PatternFixer::fix($re);')];
        yield 'a callable variable' => ['body' => self::bailing('string $s, callable $fix', '$fix($re);')];
        yield 'a constructor' => ['body' => self::bailing('string $s', 'new PatternFixer($re);')];
        yield 'an anonymous class constructor' => ['body' => self::bailing('string $s', "new class(\$re) {\n        public function __construct(string &\$pattern)\n        {\n        }\n    };")];
        yield 'the result of a call' => ['body' => self::bailing('string $s, object $factory', '$factory->fixer()($re);')];
        yield 'an array element' => ['body' => self::bailing('string $s, array $fixers', '$fixers[0]($re);')];
        yield 'a method named by a variable' => ['body' => self::bailing('string $s, object $normalizer, string $method', '$normalizer->{$method}($re);')];
        yield 'a closure called at once' => ['body' => self::bailing('string $s', '(function (string &$pattern): void {})($re);')];
        yield 'a short array callable' => ['body' => self::bailing('string $s', "['PatternFixer', 'fix'](\$re);")];
        yield 'a static method named fn' => ['body' => self::bailing('string $s', 'PatternFixer::fn($re);')];
        // Each of the four below writes the variable in PHP (checked: the
        // callee takes it by reference and the call returns the new
        // value): the parser strategy leaves it unread, the token strategy
        // reads '/bail/'.
        yield 'an array() callable' => ['body' => self::bailing('string $s', "array('PatternFixer', 'fix')(\$re);")];
        yield 'a string callable' => ['body' => self::bailing('string $s', "'normalize_pattern'(\$re);")];
        yield 'a static method named function' => ['body' => self::bailing('string $s', 'PatternFixer::function($re);')];
        yield 'an anonymous class constructor behind an attribute' => ['body' => self::bailing('string $s', "new #[\\AllowDynamicProperties] class(\$re) {\n        public function __construct(string &\$pattern)\n        {\n        }\n    };")];
    }

    #[Test]
    #[DataProvider('provideCallShapeBails')]
    public function test_a_local_variable_passed_to_a_call_of_any_shape_is_not_read(string $body): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\n".$body.self::CONTROL);
    }

    /**
     * What writes nothing, or another variable, leaves the variable read:
     * the rules are no blanket over the function.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideNoWrites(): iterable
    {
        yield 'another variable incremented' => ['body' => self::reading('string $s, int $count', '$count++;')];
        yield 'another variable unset' => ['body' => self::reading('string $s, array $cache', 'unset($cache);')];
        yield 'captured by value in a closure' => ['body' => self::reading('string $s', "\$read = function () use (\$re): string {\n        return \$re;\n    };")];
        yield 'other static variables' => ['body' => self::reading('string $s', 'static $cache = [], $hits = 0;')];
        yield 'another global' => ['body' => self::reading('string $s', 'global $config;')];
        yield 'a bitwise and' => ['body' => self::reading('string $s', '$common = $s & $re;')];
        yield 'a by-reference arrow function' => ['body' => self::reading('string $s', '$identity = fn &(array &$a): array => $a;')];
        yield 'a ternary in an arrow function' => ['body' => self::reading('string $s', '$pick = fn (bool $c): int => $c ? 1 : 2;')];
        // The arrow function ends at the ":" of the ternary around it: the
        // call after it is the function's own.
        yield 'an arrow function in a ternary' => ['body' => "function read(string \$s, bool \$lazy): int\n{\n    \$re = '/read/';\n\n    return \$lazy ? fn (): int => 0 : preg_match(\$re, \$s);\n}\n"];
        yield 'a parenthesised expression after an if' => ['body' => self::reading('string $s', "if ('' === \$s) (\$re) || throw new \\LogicException('No subject.');")];
        yield 'a parenthesised expression after a block' => ['body' => self::reading('string $s', "foreach (str_split(\$s) as \$char) {\n    }\n    (\$re) || throw new \\LogicException('No pattern.');")];
        yield 'a class name constant' => ['body' => self::reading('string $s', '$type = PatternFixer::class;')];
        yield 'named arguments spelled as keywords' => ['body' => self::reading('string $s', "configure(function: 'trim', class: PatternFixer::class, fn: 1);")];
        yield 'a function returning by reference' => ['body' => "function &read(string \$s): int\n{\n    \$re = '/read/';\n    \$found = preg_match(\$re, \$s);\n\n    return \$found;\n}\n"];
    }

    #[Test]
    #[DataProvider('provideNoWrites')]
    public function test_a_local_variable_is_read_when_no_write_reaches_it(string $body): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/read/', 'at' => "'/read/'", 'location' => 'via $re'],
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\n".$body.self::CONTROL);
    }

    /**
     * A catch at file scope writes no variable of a function body.
     */
    #[Test]
    public function test_a_catch_at_file_scope_leaves_the_functions_alone(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via $control'],
        ], "<?php\n\ntry {\n    bootstrap();\n} catch (\\Exception \$control) {\n}\n".self::CONTROL);
    }

    #[Test]
    #[DataProvider('provideVariableBails')]
    public function test_a_local_variable_is_not_read_when_a_bail_rule_holds(string $body): void
    {
        $this->assertExtractedByBoth(
            str_contains($body, "'/(x)/'") ? [
                ['pattern' => '/(x)/', 'at' => "'/(x)/'"],
                ['pattern' => '/control/', 'at' => "'/control/'"],
            ] : [
                ['pattern' => '/control/', 'at' => "'/control/'"],
            ],
            "<?php\n\n".$body.self::CONTROL,
        );
    }

    /**
     * The file scope is no function body: an included file, or a function
     * declaring the variable global, may write it.
     */
    #[Test]
    public function test_a_variable_at_file_scope_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\n\$re = '/file-scope/';\npreg_match(\$re, \$subject);\n".self::CONTROL);
    }

    /**
     * A closure is a function body of its own: it neither sees the
     * variables of the function around it nor writes them.
     */
    #[Test]
    public function test_a_variable_does_not_cross_a_closure_boundary(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/outer/', 'at' => "'/outer/'", 'location' => 'via $re'],
            ['pattern' => '/inner/', 'at' => "'/inner/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php

            function run(string $s, string $x): int
            {
                $re = '/outer/';
                $unseen = function (string $s): int {
                    return preg_match($re, $s);
                };
                $own = function (string $s): int {
                    $re = '/inner/';

                    return preg_match($re, $s);
                };
                $writer = function () use ($x): void {
                    $re = $x;
                };
                $captured = fn (string $s): int => preg_match($re, $s);

                return preg_match($re, $s);
            }
            PHP);
    }

    #[Test]
    public function test_preg_quote_concat_is_not_extracted(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'PHP'
            <?php

            function quoted(string $s, string $word): int
            {
                $re = '/'.preg_quote($word, '/').'/';
                preg_match('/'.preg_quote($word, '/').'/i', $s);

                return preg_match($re, $s);
            }

            function control_resolution(string $s): int
            {
                $control = '/control/';

                return preg_match($control, $s);
            }
            PHP);
    }

    #[Test]
    public function test_a_private_property_default_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/private-property/', 'at' => "'/private-property/'", 'source' => 'preg_match()', 'location' => 'via $this->re'],
            ['pattern' => '/protected-in-final/', 'at' => "'/protected-in-final/'", 'source' => 'preg_match()', 'location' => 'via $this->other'],
        ], <<<'PHP'
            <?php

            final class Matcher
            {
                private string $re = '/private-property/';
                protected string $other = '/protected-in-final/';

                public function run(string $s): bool
                {
                    return 1 === preg_match($this->re, $s) && 1 === preg_match($this->other, $s);
                }
            }
            PHP);
    }

    /**
     * @return iterable<string, array{write: string}>
     */
    public static function providePropertyWrites(): iterable
    {
        yield 'assignment' => ['write' => '$this->re = $s;'];
        yield 'assignment of a literal' => ['write' => "\$this->re = '/other/';"];
        yield 'reference' => ['write' => '$alias = &$this->re;'];
        yield 'unset' => ['write' => 'unset($this->re);'];
        yield 'concatenating assignment' => ['write' => "\$this->re .= 'i';"];
        yield 'null coalescing assignment' => ['write' => "\$this->re ??= '/x/';"];
        yield 'increment' => ['write' => '$this->re++;'];
        yield 'compound assignment through the result of a method' => ['write' => "\$this->self()->re .= 'i';"];
        yield 'short destructuring' => ['write' => '[$this->re] = [$s];'];
        yield 'list() destructuring' => ['write' => 'list($this->re) = [$s];'];
        yield 'foreach value' => ['write' => "foreach ([\$s] as \$this->re) {\n        }"];
        yield 'foreach key' => ['write' => "foreach ([\$s => 1] as \$this->re => \$unused) {\n        }"];
        yield 'passed to a call' => ['write' => 'normalize_pattern($this->re);'];
        yield 'passed through a nullsafe fetch' => ['write' => 'normalize_pattern($this?->re);'];
        yield 'passed behind a braced member' => ['write' => "normalize_pattern(\$this->{'inner'}->re);"];
        yield 'passed behind a member named by a variable' => ['write' => "\$name = \$s;\n        normalize_pattern(\$this->\$name->re);"];
    }

    /**
     * PHP writes the property whatever spells its name, and a method may
     * return $this. Checked with the engine: in a final class with
     * "private string $re = '/written/'", $this->$name = $v with $name 're',
     * $this->{'re'} = $v, and normalize($this->self()->re) with
     * normalize(string &$r) and self() returning $this, each leave
     * $this->re holding the new value. Both strategies read '/written/'
     * today.
     *
     * @return iterable<string, array{write: string}>
     */
    public static function provideComputedPropertyWrites(): iterable
    {
        yield 'a member named by a variable' => ['write' => '$this->$s = $s;'];
        yield 'a braced literal member' => ['write' => "\$this->{'re'} = \$s;"];
        yield 'a braced member named by a variable' => ['write' => '$this->{$s} = $s;'];
        yield 'an unset member named by a variable' => ['write' => 'unset($this->$s);'];
        yield 'passed through the result of a method' => ['write' => 'normalize_pattern($this->self()->re);'];
    }

    #[Test]
    #[DataProvider('provideComputedPropertyWrites')]
    public function test_a_property_written_under_a_computed_name_is_not_read(string $write): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\nfinal class Matcher\n{\n    private string \$re = '/written/';\n\n    public function change(string \$s): void\n    {\n        ".$write."\n    }\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s);\n    }\n}\n".self::CONTROL);
    }

    #[Test]
    #[DataProvider('providePropertyWrites')]
    public function test_a_property_written_in_the_file_is_not_read(string $write): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\nfinal class Matcher\n{\n    private string \$re = '/written/';\n\n    public function change(string \$s): void\n    {\n        ".$write."\n    }\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s);\n    }\n}\n".self::CONTROL);
    }

    #[Test]
    public function test_a_promoted_property_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\nfinal class Matcher\n{\n    public function __construct(private string \$re = '/promoted/')\n    {\n    }\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s);\n    }\n}\n".self::CONTROL);
    }

    /**
     * Only a final class, or a final property, proves the value. A subclass
     * may write a protected property of an open class; it may not write a
     * private one, which is left out all the same: a rule deliberately
     * stricter than PHP.
     */
    #[Test]
    public function test_a_property_of_a_non_final_class_via_this_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\nclass Matcher\n{\n    private string \$re = '/open-class/';\n    protected string \$other = '/open-class-protected/';\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s) && 1 === preg_match(\$this->other, \$s);\n    }\n}\n".self::CONTROL);
    }

    /**
     * Another file may write a public property: "never written in the file"
     * cannot see it.
     */
    #[Test]
    public function test_a_public_property_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], "<?php\n\nfinal class Matcher\n{\n    public string \$re = '/public/';\n\n    public function run(string \$s): bool\n    {\n        return 1 === preg_match(\$this->re, \$s);\n    }\n}\n".self::CONTROL);
    }

    /**
     * A function whose body assigns '/bail/' to $re, runs the statements,
     * then reads $re.
     */
    private static function bailing(string $parameters, string $statements): string
    {
        return 'function bail('.$parameters."): int\n{\n    \$re = '/bail/';\n    ".$statements."\n\n    return preg_match(\$re, \$s);\n}\n";
    }

    /**
     * The same with '/read/', a value the statements leave in place.
     */
    private static function reading(string $parameters, string $statements): string
    {
        return 'function read('.$parameters."): int\n{\n    \$re = '/read/';\n    ".$statements."\n\n    return preg_match(\$re, \$s);\n}\n";
    }
}
