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
 * A variable of the file level is read where it is written, and that may
 * be the value of another variable the pattern is read through: the
 * second one is read at the call, the first one in the assignment of the
 * second. What stands between the assignment of each and its own read is
 * what the list FileLevelImplicitCodeTest holds is asked about: the read
 * itself ends the span and is no part of it, even where the variable
 * read is none the file proves a string, an array or an integer.
 *
 * PHP 8.4: nothing runs in these files but the call, which is given the
 * patterns the file assigns.
 *
 * Every case runs through both strategies.
 */
final class FileLevelReadThroughACopyTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * PHP 8.4: preg_replace() is given ["/first/", "/second/"].
     */
    #[Test]
    public function test_an_array_of_patterns_copied_to_the_variable_read_is_read(): void
    {
        $code = "<?php\n\n\$all = ['/first/', '/second/'];\n\$copy = \$all;\npreg_replace(\$copy, '', \$subject);\n";

        $this->assertSame(['/first/', '/second/'], $this->patternsReadByBoth($code));
        $this->assertSame(['constant'], array_values(array_unique($this->statesReadByBoth($code))));
    }

    /**
     * PHP 8.4: preg_replace() is given ["/first/", "/second/"], a string
     * being assigned to another variable between the copy and the call.
     */
    #[Test]
    public function test_an_array_of_patterns_copied_then_a_string_assigned_is_read(): void
    {
        $code = "<?php\n\n\$all = ['/first/', '/second/'];\n\$copy = \$all;\n\$replacement = 'x';\npreg_replace(\$copy, \$replacement, \$subject);\n";

        $this->assertSame(['/first/', '/second/'], $this->patternsReadByBoth($code));
        $this->assertSame(['constant'], array_values(array_unique($this->statesReadByBoth($code))));
    }

    /**
     * PHP 8.4: preg_match() is given "/^1$/".
     */
    #[Test]
    public function test_an_integer_joined_in_the_value_of_the_variable_read_is_read(): void
    {
        $code = "<?php\n\n\$count = 1;\n\$copy = '/^' . \$count . '\$/';\npreg_match(\$copy, \$subject);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/^1$/', 'at' => "'/^' . \$count . '\$/'", 'source' => 'preg_match()', 'location' => 'via $copy'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$copy', 'at' => '$copy, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * PHP 8.4: preg_match() is given "/file-level/".
     */
    #[Test]
    public function test_a_pattern_copied_twice_is_read_through_the_last_copy(): void
    {
        $code = "<?php\n\n\$re = '/file-level/';\n\$first = \$re;\n\$second = (\$first);\npreg_match(\$second, \$subject);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/file-level/', 'at' => "'/file-level/'", 'source' => 'preg_match()', 'location' => 'via $second'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$second', 'at' => '$second, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }
}
