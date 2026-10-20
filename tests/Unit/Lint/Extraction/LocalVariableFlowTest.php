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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A value assigned to a local after its last read as a pattern never
 * reaches that read, unless a loop holding both runs the read again, or a
 * goto may jump back: such an assignment is not read. One before the read,
 * even overwritten, may be the value the read gets, and is read.
 */
final class LocalVariableFlowTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_variable_reused_for_another_value_after_the_read_reads_its_pattern_only(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/^\d+$/', 'at' => "'/^\\d+$/'", 'location' => 'via $type'],
        ], <<<'PHP'
            <?php
            function kind(string $s): string
            {
                $type = '/^\d+$/';
                if (preg_match($type, $s)) {
                    $type = 'int';
                } else {
                    $type = 'string';
                }

                return $type;
            }

            PHP);
    }

    #[Test]
    public function test_an_overwrite_before_the_read_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/second/', 'at' => "'/second/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php
            function f(string $s): int
            {
                $re = '/first/';
                $re = '/second/';

                return preg_match($re, $s);
            }

            PHP);
    }

    /**
     * @return iterable<string, array{body: string}>
     */
    public static function provideLoopsHoldingBoth(): iterable
    {
        yield 'foreach' => ['body' => "foreach (\$lines as \$line) {\n        preg_match(\$re, \$line);\n        \$re = '/later/';\n    }"];
        yield 'foreach, alternative syntax' => ['body' => "foreach (\$lines as \$line):\n        preg_match(\$re, \$line);\n        \$re = '/later/';\n    endforeach;"];
        yield 'for' => ['body' => "for (\$i = 0; \$i < 3; \$i++) {\n        preg_match(\$re, \$s);\n        \$re = '/later/';\n    }"];
        yield 'while, read in the condition' => ['body' => "while (preg_match(\$re, \$s)) {\n        \$re = '/later/';\n    }"];
        yield 'do-while' => ['body' => "do {\n        preg_match(\$re, \$s);\n        \$re = '/later/';\n    } while (\$s);"];
        yield 'for, alternative syntax' => ['body' => "for (\$i = 0; \$i < 3; \$i++):\n        preg_match(\$re, \$s);\n        \$re = '/later/';\n    endfor;"];
        yield 'while, alternative syntax' => ['body' => "while (\$s):\n        preg_match(\$re, \$s);\n        \$re = '/later/';\n    endwhile;"];
        yield 'foreach in foreach, alternative syntax' => ['body' => "foreach (\$lines as \$a):\n        foreach (\$lines as \$b):\n            preg_match(\$re, \$s);\n        endforeach;\n        \$re = '/later/';\n    endforeach;"];
        // A loop running a control structure without braces is not
        // measured: it may hold anything.
        yield 'foreach running an if without braces' => ['body' => "foreach (\$lines as \$line) if (\$line) {\n        preg_match(\$re, \$line);\n    }\n    \$re = '/later/';"];
        yield 'foreach, alternative syntax, holding a loop that runs an if' => ['body' => "foreach (\$lines as \$a):\n        foreach (\$lines as \$b) if (\$b) preg_match(\$re, \$s);\n    endforeach;\n    \$re = '/later/';"];
        yield 'a goto jumping back' => ['body' => "again:\n    preg_match(\$re, \$s);\n    \$re = '/later/';\n    if (\$s) {\n        goto again;\n    }"];
    }

    #[Test]
    #[DataProvider('provideLoopsHoldingBoth')]
    public function test_a_loop_holding_the_read_and_the_assignment_reads_the_later_value(string $body): void
    {
        $code = "<?php\nfunction f(string \$s, array \$lines): void\n{\n    \$re = '/first/';\n    ".$body."\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/later/', 'at' => "'/later/'", 'location' => 'via $re'],
        ], $code);
    }

    #[Test]
    public function test_a_loop_ended_before_the_assignment_does_not_bring_it_back(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/unbraced/', 'at' => "'/unbraced/'", 'location' => 'via $re'],
            ['pattern' => '/do-unbraced/', 'at' => "'/do-unbraced/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php
            function f(string $s, array $lines): void
            {
                $re = '/first/';
                foreach ($lines as $line) {
                    preg_match($re, $line);
                }
                $re = 'done';
                while ($s) {
                    $re = 'again';
                }
            }

            function g(string $s, array $lines): void
            {
                $re = '/unbraced/';
                foreach ($lines as $line) preg_match($re, $line);
                $re = 'done';
            }

            function h(string $s): void
            {
                $re = '/do-unbraced/';
                do preg_match($re, $s); while ($s);
                $re = 'done';
            }

            PHP);
    }

    /**
     * A do-while runs its body until the "while" that closes it, whatever
     * statement the body is: a control structure without braces is read to
     * its end, so an assignment after the loop does not reach the read,
     * which nikic/php-parser says as well.
     *
     * @return iterable<string, array{body: string}>
     */
    public static function provideDoLoopsRunningAControlStructure(): iterable
    {
        yield 'an if' => ['body' => 'do if ($s) preg_match($re, $s); while ($s);'];
        yield 'an if and an else' => ['body' => "do if (\$s) { preg_match(\$re, \$s); } elseif (\$t) \$t = 1; else { \$t = 2; }\n    while (\$s);"];
        yield 'an if, alternative syntax' => ['body' => "do if (\$s): preg_match(\$re, \$s); else: \$t = 1; endif;\n    while (\$s);"];
        yield 'a foreach' => ['body' => 'do foreach ($lines as $line) preg_match($re, $line); while ($s);'];
        yield 'a for, alternative syntax' => ['body' => "do for (\$i = 0; \$i < 3; \$i++): preg_match(\$re, \$s); endfor;\n    while (\$s);"];
        yield 'a while' => ['body' => 'do while ($t) preg_match($re, $s); while ($s);'];
        yield 'a while with no statement' => ['body' => 'do while (preg_match($re, $s)); while ($s);'];
        yield 'a do' => ['body' => 'do do preg_match($re, $s); while ($t); while ($s);'];
        yield 'a switch' => ['body' => "do switch (\$s) {\n        case 'a': preg_match(\$re, \$s);\n    } while (\$s);"];
        yield 'a try' => ['body' => "do try { preg_match(\$re, \$s); } catch (\\Exception \$e) { \$t = 1; } finally { \$t = 2; }\n    while (\$s);"];
        yield 'an else if' => ['body' => 'do if ($s) $t = 1; else if ($t) preg_match($re, $s); else $t = 2; while ($s);'];
        yield 'a switch, alternative syntax' => ['body' => "do switch (\$s):\n        case 'a': preg_match(\$re, \$s);\n    endswitch;\n    while (\$s);"];
        yield 'a declare' => ['body' => 'do declare(ticks=1) preg_match($re, $s); while ($s);'];
        yield 'an if, alternative syntax, holding another' => ['body' => "do if (\$s): if (\$t): preg_match(\$re, \$s); endif; endif;\n    while (\$s);"];
        yield 'an if, alternative syntax, holding another, no space before the parentheses' => ['body' => "do if(\$s): if(\$t): preg_match(\$re, \$s); endif; endif;\n    while (\$s);"];
    }

    #[Test]
    #[DataProvider('provideDoLoopsRunningAControlStructure')]
    public function test_a_do_loop_running_a_control_structure_ends_at_its_while(string $body): void
    {
        $code = "<?php\nfunction f(string \$s, string \$t, array \$lines): void\n{\n    \$re = '/first/';\n    ".$body."\n    \$re = 'done';\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
        ], $code);
    }

    /**
     * A do whose body the file leaves open, which only the tokenizer
     * reads, may hold anything: an assignment after the read reaches it.
     *
     * @return iterable<string, array{tail: string}>
     */
    public static function provideDoLoopsLeftOpen(): iterable
    {
        yield 'a while with no condition' => ['tail' => 'do while'];
        yield 'an if with no condition' => ['tail' => 'do if'];
        yield 'a try with no block' => ['tail' => 'do try'];
        yield 'an if, alternative syntax, never ended' => ['tail' => 'do if ($s): $t = 1;'];
        yield 'a switch, alternative syntax, never ended' => ['tail' => "do switch (\$s): case 'a': \$t = 1;"];
    }

    #[Test]
    #[DataProvider('provideDoLoopsLeftOpen')]
    public function test_a_do_loop_left_open_may_hold_anything(string $tail): void
    {
        $directory = $this->makeProject(['open.php' => "<?php\nfunction f(string \$s): void\n{\n    \$re = '/first/';\n    preg_match(\$re, \$s);\n    \$re = '/later/';\n    ".$tail."\n}\n"]);
        $patterns = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extract([$directory.'/open.php']),
        );

        $this->assertSame(['/first/', '/later/'], $patterns);
    }

    #[Test]
    public function test_the_last_of_several_reads_decides(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re'],
            ['pattern' => '/second/', 'at' => "'/second/'", 'location' => 'via $re'],
        ], <<<'PHP'
            <?php
            function f(string $s): void
            {
                $re = '/first/';
                preg_match($re, $s);
                $re = '/second/';
                preg_match($re, $s);
                $re = 'done';
            }

            PHP);
    }

    /**
     * A loop of alternative syntax the file never closes, which only the
     * tokenizer reads, may hold anything.
     */
    #[Test]
    public function test_a_loop_left_open_may_hold_anything(): void
    {
        $directory = $this->makeProject(['open.php' => "<?php\nfunction f(string \$s, array \$lines): void\n{\n    \$re = '/first/';\n    foreach (\$lines as \$line):\n        preg_match(\$re, \$line);\n        \$re = '/later/';\n}\n"]);
        $patterns = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extract([$directory.'/open.php']),
        );

        $this->assertSame(['/first/', '/later/'], $patterns);
    }
}
