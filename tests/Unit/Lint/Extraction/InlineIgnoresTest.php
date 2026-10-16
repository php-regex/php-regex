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

use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lines a comment silences: "@regex-ignore" its own, the last one of
 * the comment; "@regex-ignore-next-line" the one after it.
 */
final class InlineIgnoresTest extends TestCase
{
    private string|false $memoryLimit = false;

    protected function tearDown(): void
    {
        if (false !== $this->memoryLimit) {
            ini_set('memory_limit', $this->memoryLimit);
        }
    }

    /**
     * Only a comment holds a marker: the same words in a string, and the
     * comments around without them, silence nothing.
     */
    #[Test]
    public function test_only_a_comment_holding_a_marker_silences_a_line(): void
    {
        $content = <<<'CODE'
            <?php

            // A note with no marker.
            $help = 'Write @regex-ignore after a call to silence it.';
            preg_match('/same-line/', $s); // @regex-ignore
            /*
             * Silences the line after the comment.
             * @regex-ignore-next-line
             */
            preg_match('/next-line/', $s);
            preg_match('/not-silenced/', $s); # an unrelated comment
            CODE;

        $this->assertSame([5 => true, 10 => true], InlineIgnores::suppressedLines($content));
    }

    /**
     * A source too large to tokenize under memory_limit keeps no marker,
     * rather than stopping the process.
     */
    #[Test]
    public function test_a_source_over_the_memory_budget_silences_nothing(): void
    {
        // A megabyte of source needs some 60 MB of tokens.
        $content = "<?php\npreg_match('/a+/', \$s); // @regex-ignore\n".str_repeat("// padding\n", 100_000);
        $this->memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        $this->assertSame([2 => true], InlineIgnores::suppressedLines($content), 'Under no limit, the marker counts.');

        ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));

        $this->assertSame([], InlineIgnores::suppressedLines($content));
    }
}
