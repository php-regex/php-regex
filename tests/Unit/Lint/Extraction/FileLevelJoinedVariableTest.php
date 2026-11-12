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
 * Between the assignment of a variable of the file level and its read, a
 * variable joined to a string runs no code when it holds a string, and
 * may run any when it does not: an object is asked for its __toString(),
 * and a variable that is not assigned raises a warning a handler takes.
 * One assigned otherwise than a literal is known to hold strings only
 * when a pattern is read through it and the file proves what it holds
 * where it is joined: the read across it is made then, and not
 * otherwise.
 *
 * PHP 8.4 (php -r): with class Part { function __toString() {
 * $GLOBALS['re'] = '/(/'; return 'p'; } }, $re = '/x/'; $part = new
 * Part(); $label = $part . '!'; leaves "/(/" in $re; so does $label =
 * $undefined . '!'; under a set_error_handler() that writes it.
 *
 * Every case runs through both strategies.
 */
final class FileLevelJoinedVariableTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * $part is assigned a constant of the file, and a pattern is read
     * through it at the last line: it holds "abc" where it is joined to
     * "!", and $re is read across that line. The last argument stands
     * after a call, the first preg_match(): $part may have been written
     * since, and is a part known at run time only.
     */
    #[Test]
    public function test_a_variable_proved_to_hold_a_string_is_joined_without_running_code(): void
    {
        $code = <<<'PHP'
            <?php

            const WORD = 'abc';

            $part = WORD;
            $re = '/across/';
            $label = $part . '!';
            preg_match($re, $subject);
            preg_match('/^' . $part . '$/', $subject);
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/across/', 'at' => "'/across/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $subject)'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$part . '$/'"],
        ], $code);
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideJoinedVariablesNotProvedToHoldAString(): iterable
    {
        yield 'assigned the result of a call' => [
            'code' => "<?php\n\n\$part = make();\n\$re = '/across/';\n\$label = \$part . '!';\npreg_match(\$re, \$subject);\npreg_match('/^' . \$part . '$/', \$subject);\n",
        ];

        yield 'assigned only after it is joined' => [
            'code' => "<?php\n\n\$re = '/across/';\n\$label = \$part . '!';\npreg_match(\$re, \$subject);\n\$part = 'abc';\npreg_match('/^' . \$part . '$/', \$subject);\n",
        ];

        yield 'assigned a constant, no pattern read through it' => [
            'code' => "<?php\n\nconst WORD = 'abc';\n\n\$part = WORD;\n\$re = '/across/';\n\$label = \$part . '!';\npreg_match(\$re, \$subject);\n",
        ];
    }

    #[Test]
    #[DataProvider('provideJoinedVariablesNotProvedToHoldAString')]
    public function test_a_variable_not_proved_to_hold_a_string_leaves_the_read_across_it_unmade(string $code): void
    {
        $this->assertNotContains('/across/', $this->patternsReadByBoth($code));

        $arguments = $this->argumentsReadByBoth($code);
        $this->assertSame(['state' => 'opaque', 'expression' => '$re'], ['state' => $arguments[0]['state'], 'expression' => $arguments[0]['expression']], 'The first argument of the file is $re.');
    }
}
