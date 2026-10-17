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

/**
 * "@regex-target" narrows the versions a call is judged for, as in
 * `regex lint`: Psalm analysing for PHP 8.2 judges the series 8.2, so a
 * marker keeping 8.4 and later keeps none of it, is reported as
 * InvalidRegexTarget, and leaves its call unjudged; one keeping 8.2.5 and
 * later judges the call for 8.2.5; a malformed one is reported, and its
 * call judged as if it held none.
 */
final class PsalmPluginTargetMarkerTest extends TestCase
{
    private const FILE = 'Target/markers.php';

    #[Test]
    public function test_a_marker_narrows_the_versions_a_call_is_judged_for(): void
    {
        $found = [];
        foreach (PsalmRun::inFile(PsalmRun::issues([self::FILE], '8.2'), self::FILE) as $issue) {
            if ('Trace' !== $issue->type) {
                $found[] = [$issue->line_from, $issue->type];
            }
        }

        $this->assertSame([
            [21, 'InvalidRegexTarget'],
            [22, 'InvalidRegexPattern'],
            [23, 'InvalidRegexPattern'],
            [23, 'InvalidRegexTarget'],
            [24, 'InvalidRegexPattern'],
        ], $found);
    }
}
