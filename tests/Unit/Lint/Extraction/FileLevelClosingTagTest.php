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
 * A closing tag ends a statement as ";" does, and what follows it, up to
 * the next opening tag, is sent out: no assignment of a literal, and
 * nothing a variable of the file level is read across. The assignment of
 * the variable itself ends before its closing tag, which stands between
 * it and the read as any other would; its ";" stands there too, and
 * runs nothing.
 *
 * PHP 8.4: "<?php $re = '/x/' ?><?php echo $re;" prints "/x/", and so
 * does the same with each tag on a line of its own: the tag ends the
 * statement, and nothing is sent out between the two tags. The variable
 * is left unread because the list is closed.
 *
 * Every case runs through both strategies.
 */
final class FileLevelClosingTagTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{code: string, argument: string, state: string}>
     */
    public static function provideAssignmentsEndedByAClosingTag(): iterable
    {
        yield 'the two tags side by side' => [
            'code' => "<?php \$re = '/x/' ?><?php preg_match(\$re, \$s);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        yield 'the tags on lines of their own' => [
            'code' => "<?php\n\$re = '/x/'\n?>\n<?php\npreg_match(\$re, \$s);\n",
            'argument' => '$re',
            'state' => 'opaque',
        ];

        // The literals around the variable are still the shell of a part
        // the file does not prove.
        yield 'a part of the pattern' => [
            'code' => "<?php \$part = 'x' ?><?php preg_match('/^' . \$part . '\$/', \$s);\n",
            'argument' => "'/^' . \$part . '\$/'",
            'state' => 'raw_shell',
        ];
    }

    #[Test]
    #[DataProvider('provideAssignmentsEndedByAClosingTag')]
    public function test_a_variable_is_not_read_across_the_closing_tag_that_ends_its_assignment(string $code, string $argument, string $state): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideAssignmentsEndedByASemicolon(): iterable
    {
        yield 'the read right after the semicolon' => ['code' => "<?php\n\$re = '/x/';preg_match(\$re, \$s);\n"];
        yield 'another assignment between, no space around it' => ['code' => "<?php\n\$re = '/x/';\$other = 'y';preg_match(\$re, \$s);\n"];
        yield 'an if between, no space around it' => ['code' => "<?php\n\$re = '/x/';if (\$flag) { \$other = 'y'; }preg_match(\$re, \$s);\n"];
    }

    #[Test]
    #[DataProvider('provideAssignmentsEndedByASemicolon')]
    public function test_a_variable_is_read_across_the_semicolon_that_ends_its_assignment(string $code): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/x/', 'at' => "'/x/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }
}
