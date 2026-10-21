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

namespace PHPRegex\Tests\Integration\Bridge\PHPUnit;

use PHPRegex\Parser\PcreTarget;
use PHPRegex\PHPUnit\PatternLab;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The machinery behind the assertions: one parser for the process, so the
 * trees and the verdicts it keeps serve every assertion that reads the same
 * pattern again.
 */
final class PatternLabTest extends TestCase
{
    #[Test]
    public function test_the_parser_is_created_once_and_held_for_the_process(): void
    {
        $this->assertSame(PatternLab::parser(), PatternLab::parser());
    }

    /**
     * The assertions judge for the PHP running them, as the engine they
     * run each attempt on does.
     */
    #[Test]
    public function test_the_parser_judges_for_the_running_php(): void
    {
        $this->assertEquals(PcreTarget::runtime(), PatternLab::parser()->target());
    }

    /**
     * A pattern that does not parse fails the attempt with the library's
     * message, then the line at fault with a caret under it.
     */
    #[Test]
    public function test_a_pattern_that_does_not_parse_fails_with_its_message_and_snippet(): void
    {
        $attempt = PatternLab::run('/a(/', 'a', []);

        $this->assertNull($attempt->matched);
        $this->assertSame("Expected ) at end of input (found eof).\nLine 1: a(\n".str_repeat(' ', 10).'^', $attempt->failure);
    }
}
