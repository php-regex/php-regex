<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\LinterNodeVisitor;
use RegexParser\Regex;

final class LinterNodeVisitorTest extends TestCase
{
    public function test_linter_visitor_reports_no_issues_for_literal(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/abc/');
        $visitor = new LinterNodeVisitor();

        $ast->accept($visitor);

        $this->assertSame([], $visitor->getIssues());
    }

    public function test_the_classes_of_an_extended_class_are_linted(): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.45']);

        foreach (['/(?[ [aa] ])/', '/(?[ \\d - [bb] ])/', '/(?[ [dd] - \\d ])/', '/(?[ ![cc] ])/'] as $pattern) {
            $visitor = new LinterNodeVisitor();
            $regex->parse($pattern)->accept($visitor);

            $issueIds = array_map(static fn ($issue): string => $issue->id, $visitor->getIssues());
            $this->assertContains('regex.lint.charclass.redundant', $issueIds, $pattern);
        }
    }

    public function test_anchor_end_allows_optional_suffix(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/^use [^;{]+;$\n?/m');
        $visitor = new LinterNodeVisitor();

        $ast->accept($visitor);

        $issueIds = array_map(static fn ($issue): string => $issue->id, $visitor->getIssues());
        $this->assertNotContains('regex.lint.anchor.impossible.end', $issueIds);
    }

    public function test_anchor_end_reports_required_suffix(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/^foo$bar/');
        $visitor = new LinterNodeVisitor();

        $ast->accept($visitor);

        $issueIds = array_map(static fn ($issue): string => $issue->id, $visitor->getIssues());
        $this->assertContains('regex.lint.anchor.impossible.end', $issueIds);
    }
}
