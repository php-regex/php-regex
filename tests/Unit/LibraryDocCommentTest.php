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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A doc comment documents what follows it. One that another doc comment
 * follows documents nothing: a method written between a comment and its
 * own, or a comment rewritten above the old one, leaves it behind, and
 * neither the style fixer nor the static analysis sees it.
 */
final class LibraryDocCommentTest extends TestCase
{
    #[Test]
    public function test_no_doc_comment_is_left_behind_another(): void
    {
        $stray = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            foreach (LibrarySource::strayDocComments($tokens) as $line) {
                $stray[] = $path.':'.$line;
            }
        }

        $this->assertSame([], $stray);
    }

    /**
     * The suite is held to it too, but for its fixtures, whose layout is
     * what a test reads: two "@psalm-trace" comments stacked ask Psalm for
     * two traces.
     */
    #[Test]
    public function test_no_doc_comment_of_the_suite_is_left_behind_another(): void
    {
        $stray = [];
        foreach (LibrarySource::files('tests') as $path => $tokens) {
            if (1 === preg_match('~/Fixture[^/]*/~', $path)) {
                continue;
            }

            foreach (LibrarySource::strayDocComments($tokens) as $line) {
                $stray[] = $path.':'.$line;
            }
        }

        $this->assertSame([], $stray);
    }

    #[Test]
    public function test_the_scan_sees_a_doc_comment_another_follows(): void
    {
        $tokens = token_get_all("<?php\n/** A */\n\n/** B */\nfunction b() {}\n/** C */\n// D\nfunction c() {}\n/* E */\n/** F */\nfunction f() {}\n");

        $this->assertSame([2], LibrarySource::strayDocComments($tokens));
    }
}
