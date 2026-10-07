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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * Past Psalm's maxShapedArraySize keys, Psalm keeps no array shape: the
 * plugin leaves such a $matches to the stub. A pattern the library cannot
 * judge (past its length limit) is left to the stub too, and a value is
 * written as a literal only below Psalm's maxStringLength.
 */
final class PsalmPluginBudgetTest extends TestCase
{
    private const DIRECTORY = 'Budget';

    private const ATTRIBUTES = ['maxShapedArraySize' => '4'];

    private const STRING_LENGTH_DIRECTORY = 'StringLength';

    private const STRING_LENGTH_ATTRIBUTES = ['maxStringLength' => '20'];

    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_plugin_keeps_a_shape_up_to_psalms_budget(string $file, int $from, int $to, string $variable, string $expected): void
    {
        $issues = array_values(array_filter(
            PsalmRun::inFile(PsalmRun::issues(PsalmRun::filesIn(self::DIRECTORY), '8.4', [], self::ATTRIBUTES), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $failures = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $failures), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    #[Test]
    public function test_plugin_leaves_a_pattern_past_the_length_limit_to_the_stub(): void
    {
        // PCRE2 compiles a comment of any length; the library stops reading at
        // its length limit. Psalm keeps the literal only below maxStringLength.
        $pattern = '/(a)(?#'.str_repeat('x', RegexParser::DEFAULT_MAX_PATTERN_LENGTH).')/';
        $this->assertSame(1, preg_match($pattern, 'a'), 'The engine must compile the row\'s pattern.');

        $directory = sys_get_temp_dir().'/php-regex-psalm-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/long_pattern.php', "<?php\n\ndeclare(strict_types=1);\n\nfunction long_pattern(string \$s): void\n{\n    if (preg_match('".$pattern."', \$s, \$m)) {\n        /** @psalm-trace \$m */\n    }\n}\n");

            $issues = PsalmRun::issues(['long_pattern.php'], '8.4', [], ['maxStringLength' => '200000'], false, $directory);
        } finally {
            @unlink($directory.'/long_pattern.php');
            @rmdir($directory);
        }

        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type));
        $this->assertCount(1, $traces);
        $this->assertSame('$m: array<array-key, string>', $traces[0]->message);
        // Neither reported: the library's limit is not PCRE's, which compiles the pattern.
        $this->assertSame([], array_map(PsalmRun::describe(...), array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type))));
    }

    /**
     * Psalm keeps a literal string only below maxStringLength bytes, and
     * refuses to build a longer one: a value at the limit is written as the
     * facts of its group, as Psalm types a string literal that long.
     */
    #[Test]
    #[DataProvider('provideStringLengthChecks')]
    public function test_plugin_writes_a_literal_only_below_psalms_string_length(string $file, int $from, int $to, string $variable, string $expected): void
    {
        $issues = array_values(array_filter(
            PsalmRun::inFile(PsalmRun::issues(PsalmRun::filesIn(self::STRING_LENGTH_DIRECTORY), '8.4', [], self::STRING_LENGTH_ATTRIBUTES), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $failures = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' !== $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $failures), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideStringLengthChecks(): iterable
    {
        return PsalmRun::typeChecks(PsalmRun::filesIn(self::STRING_LENGTH_DIRECTORY));
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideTypeChecks(): iterable
    {
        return PsalmRun::typeChecks(PsalmRun::filesIn(self::DIRECTORY));
    }
}
