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
 * A file that declares ticks runs a tick function after each statement
 * that follows, and reads no variable of its file level after the
 * declare (FileLevelImplicitCodeTest holds the rule). The directive is
 * the name written in the parentheses of the declare, wherever the
 * declare stands and whatever it is written against; the same name
 * written anywhere else in the file declares nothing: a function, a
 * method, a case or a variable may be named ticks, and a method or a
 * case declare.
 *
 * PHP 8.4, with the bootstrap.php of FileLevelImplicitCodeTest required
 * before the file, which registers a tick function writing the global
 * $re: preg_match() is given "/(tick/" where ticks are declared, and
 * "/file-level/" where they are not.
 *
 * Every case runs through both strategies.
 */
final class FileLevelTicksDirectiveTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * PHP 8.4: "/(tick/" in each case, the declare written in the body
     * of a function included, which holds for the rest of the file.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideDeclaresOfTicksWrittenAgainstAnotherToken(): iterable
    {
        yield 'against the ";" of the statement before' => ['before' => '$limit = 10;declare(ticks=1);'];
        yield 'against the brace of a block' => ['before' => "if (\$flag) {declare(ticks=1);\n}"];
        yield 'against the brace of a function body' => ['before' => "function ticking(): void\n{declare(ticks=1);\n}"];
        yield 'against the brace that closes a body, in the body of a function' => ['before' => "function ticking(): void\n{\n    if (true) {\n    }declare(ticks=1);\n}"];
        yield 'after a space and its parenthesis' => ['before' => 'declare (ticks=1);'];
        yield 'after another directive, each on its line' => ['before' => "declare(\n    strict_types=1,\n    ticks=1\n);"];
    }

    #[Test]
    #[DataProvider('provideDeclaresOfTicksWrittenAgainstAnotherToken')]
    public function test_a_declare_of_ticks_is_read_whatever_it_is_written_against(string $before): void
    {
        $code = "<?php\n".$before."\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * PHP 8.4: "/file-level/" in each case: no ticks are declared.
     *
     * @return iterable<string, array{before: string}>
     */
    public static function provideNamesThatDeclareNoTicks(): iterable
    {
        yield 'strict types declared, then a function named ticks' => ['before' => "declare(strict_types=1);\n\nfunction ticks(): int\n{\n    return 1;\n}"];
        yield 'strict types declared, then a function named ticks, called' => ['before' => "declare(strict_types=1);\n\nfunction ticks(int \$every): int\n{\n    return \$every;\n}\n\n\$count = ticks(1);"];
        yield 'an encoding declared, then a constant named TICKS' => ['before' => "declare(encoding='UTF-8');\n\nconst TICKS = 1;"];
        yield 'an enum whose cases are named Declare and Ticks' => ['before' => "enum Directive\n{\n    case Declare;\n    case Ticks;\n}"];
        yield 'a class whose methods are named declare and ticks' => ['before' => "final class Directives\n{\n    public function declare(string \$name): void\n    {\n    }\n\n    public function ticks(): int\n    {\n        return 1;\n    }\n}"];
        yield 'a class whose method named ticks holds a variable named ticks' => ['before' => "final class Clock\n{\n    public function ticks(): int\n    {\n        \$ticks = 1;\n\n        return \$ticks;\n    }\n}"];
    }

    #[Test]
    #[DataProvider('provideNamesThatDeclareNoTicks')]
    public function test_a_name_written_out_of_a_declare_declares_no_ticks(string $before): void
    {
        $code = "<?php\n\n".$before."\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }
}
