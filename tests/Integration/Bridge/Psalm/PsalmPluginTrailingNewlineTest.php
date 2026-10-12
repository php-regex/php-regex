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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * A subject narrowed to the strings its pattern matches holds the "\n" that
 * $ and \Z let through before the end. A docblock type cannot spell that
 * newline, so the traces Psalm prints are read instead.
 */
final class PsalmPluginTrailingNewlineTest extends TestCase
{
    private const FILE = 'Newline/trailing_newline.php';

    #[Test]
    public function test_plugin_narrows_the_subject_with_the_final_newline(): void
    {
        $issues = PsalmRun::inFile(PsalmRun::issues([self::FILE]), self::FILE);

        $this->assertSame([
            "Trace 23:0 \$s: 'no'|'no\\n'|'yes'|'yes\\n'",
            "Trace 30:0 \$s: 'no'|'no\\n'|'yes'|'yes\\n'",
            'Trace 37:0 $s: non-falsy-string',
        ], array_map(static fn (IssueData $issue): string => \sprintf('%s %d:%d %s', $issue->type, $issue->line_from, $issue->column_from, $issue->message), $issues));
    }
}
