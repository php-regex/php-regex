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

use PHPRegex\Tests\Support\PcreRule;
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
    use PcreRule;
    private const FILE = 'Target/markers.php';

    #[Test]
    public function test_a_marker_narrows_the_versions_a_call_is_judged_for(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
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

    /**
     * A guard on PHP_VERSION_ID around the call is read as `regex lint`
     * reads it, from the file: under PHP 8.4 and later, or after an early
     * return below it, nothing of the 8.2 series runs the call, which is not
     * judged; from 8.2.5, the call is judged for 8.2.5. A marker on the
     * line narrows what the guard keeps, and keeps nothing of 8.2.5 and
     * later below 8.2.3. Psalm's own narrowing
     * is not read: it narrows PHP_VERSION_ID under function_exists() too,
     * which a polyfill makes true on any version.
     */
    #[Test]
    public function test_a_guard_narrows_the_versions_a_call_is_judged_for(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
        $found = [];
        foreach (PsalmRun::inFile(PsalmRun::issues(['Target/guards.php'], '8.2'), 'Target/guards.php') as $issue) {
            if ('Trace' !== $issue->type) {
                $found[] = [$issue->line_from, $issue->type];
            }
        }

        $this->assertSame([
            [24, 'InvalidRegexPattern'],
            [26, 'InvalidRegexPattern'],
            [29, 'InvalidRegexPattern'],
            [32, 'InvalidRegexPattern'],
            [53, 'InvalidRegexPattern'],
        ], $found);
    }

    /**
     * A file PHP cannot parse holds no guard the plugin reads, the tree
     * Psalm recovers from it not being trusted to place one: the call is
     * judged for the whole range, and reported rather than passed over.
     */
    #[Test]
    public function test_the_guards_of_a_file_php_cannot_parse_are_not_read(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
        $found = [];
        foreach (PsalmRun::inFile(PsalmRun::issues(['Target/guards_unparsed.php'], '8.2'), 'Target/guards_unparsed.php') as $issue) {
            if ('Trace' !== $issue->type) {
                $found[] = [$issue->line_from, $issue->type];
            }
        }

        $this->assertSame([
            [22, 'InvalidRegexPattern'],
            [28, 'ParseError'],
        ], $found);
    }
}
