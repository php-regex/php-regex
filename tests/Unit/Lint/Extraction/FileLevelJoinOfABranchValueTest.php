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
 * At the file level a variable joined to a string between an assignment
 * and its read runs no code only when it holds a string there. One the
 * file assigns in a branch holds, where the branch did not run, whatever
 * it held before: an object of another file, whose __toString() may write
 * the variable the call reads. That a pattern is read through it
 * elsewhere proves nothing of the path that skipped the branch.
 *
 * PHP 8.4: with $flag false and $part an object whose __toString() sets
 * $GLOBALS['re'], "$title = $part . 'y';" runs it, and preg_match($re,
 * ...) is given what it wrote.
 *
 * Every case runs through both strategies.
 */
final class FileLevelJoinOfABranchValueTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{assignment: string}>
     */
    public static function provideAssignmentsABranchMaySkip(): iterable
    {
        yield 'in the body of an if' => ['assignment' => "if (\$flag) {\n    \$part = 'a';\n}"];
        yield 'in an if without braces' => ['assignment' => "if (\$flag) \$part = 'a';"];
        yield 'in an else' => ['assignment' => "if (\$flag) {\n    \$other = 'b';\n} else {\n    \$part = 'a';\n}"];
    }

    #[Test]
    #[DataProvider('provideAssignmentsABranchMaySkip')]
    public function test_a_variable_assigned_in_a_branch_proves_no_string_where_it_is_joined(string $assignment): void
    {
        $code = "<?php\n\n".$assignment."\n\$re = '/file-level/';\n\$title = \$part . 'y';\npreg_match(\$re, \$subject);\npreg_match('/' . \$part . '/', \$subject);\n";

        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertSame(['opaque', 'raw_shell'], $this->statesReadByBoth($code));
    }

    /**
     * Assigned by a statement of the file itself, the variable holds its
     * string on every path: the join runs nothing, and the call that
     * follows it is read. The second call reads the variable after the
     * first one ran: a call stands between, and it is a part not read.
     */
    #[Test]
    public function test_a_variable_assigned_by_a_statement_of_the_file_is_a_string_where_it_is_joined(): void
    {
        $code = "<?php\n\n\$part = 'a';\n\$re = '/file-level/';\n\$title = \$part . 'y';\npreg_match(\$re, \$subject);\npreg_match('/' . \$part . '/', \$subject);\n";

        $this->assertSame(['/file-level/'], $this->patternsReadByBoth($code));
        $this->assertSame(['constant', 'raw_shell'], $this->statesReadByBoth($code));
    }
}
