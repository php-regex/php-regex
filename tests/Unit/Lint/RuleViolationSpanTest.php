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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An issue placed in the pattern names the text it is about from its start
 * to its end, so an editor underlines the whole node: only the issues about
 * the modifiers, placed nowhere in the body, have neither.
 */
final class RuleViolationSpanTest extends TestCase
{
    private const PATTERNS = [
        '/[é]/', '/\p{L}/', '/é+/', '/a\d+?/', '/(?<n>a)+/', '/(?:a*)+/', '/^a|b/', '/a*+a/', '/a\bb/',
        '/(?=a)b/', '/a(?:)b/', '/(a)+/', '/\w/', '/[a]/', '/a  b/', '/".*?"/', '/x[aa]/', '/(a+)+$/',
        '/a{1}/', '/a|a/', '/a{0}b/', '/(?:a?)*b/', '/[a-Z]/', '/[\1]/', '/(.*)*x/', "/a\u{200B}b/u", '/(?=a+)a/',
    ];

    #[Test]
    public function test_an_issue_placed_in_the_body_ends_where_its_node_ends(): void
    {
        $parser = RegexParser::create();
        $ids = [];
        foreach (self::PATTERNS as $pattern) {
            $linter = new PatternLinter(['unicode.shorthandWithoutU' => true, 'charclass.single' => true, 'literal.multipleSpaces' => true, 'quantifier.lazyToClass' => true]);
            $parser->parse($pattern)->accept($linter);

            foreach ($linter->getIssues() as $issue) {
                $ids[$issue->id] = true;
                if (null === $issue->offset) {
                    $this->assertNull($issue->end, $issue->id.' on '.$pattern);

                    continue;
                }

                $this->assertNotNull($issue->end, $issue->id.' on '.$pattern.' names no end.');
                $this->assertGreaterThan($issue->offset, $issue->end, $issue->id.' on '.$pattern);
            }
        }

        $this->assertGreaterThanOrEqual(20, \count($ids), 'The patterns reach most rules.');
    }
}
