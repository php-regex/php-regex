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

namespace RegexParser\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\HtmlHighlighterVisitor;
use RegexParser\NodeVisitor\ReDoSProfileNodeVisitor;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;
use RegexParser\Tests\TestUtils\PhpErrorOffset;

/**
 * "(*atomic_script_run:...)", short "(*asr:...)", is a script run whose body
 * is atomic: PCRE2 documents it as "(*sr:(?>...))". Its body is a pattern
 * like any other, whose groups count in the whole pattern (every verdict
 * and offset below is PHP's, on PCRE2 10.42 and 10.48).
 */
final class AtomicScriptRunTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusedBodies')]
    public function test_validate_checks_the_body(string $pattern, string $code, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''), \sprintf('%s should not compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, \sprintf('%s does not compile but was reported valid.', $pattern));
        $this->assertSame($code, $result->errorCode, $pattern);
        // The offset is PHP's on PCRE2 10.47 and later; the running PHP
        // decides, as the library follows the PCRE2 it links.
        $this->assertSame(PhpErrorOffset::of($pattern), $result->offset, $pattern);
        if (PhpErrorOffset::runsPcre1047()) {
            $this->assertSame($offset, $result->offset, $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, code: string, offset: int}>
     */
    public static function provideRefusedBodies(): iterable
    {
        yield 'unknown escape' => ['pattern' => '/(*asr:\\y)/', 'code' => 'regex.escape.unrecognized', 'offset' => 8];
        yield 'unknown escape, spelled out' => ['pattern' => '/(*atomic_script_run:\\y)/', 'code' => 'regex.escape.unrecognized', 'offset' => 22];
        yield 'reference to a missing group' => ['pattern' => '/(*asr:(a)\\2)/', 'code' => 'regex.backref.missing_group', 'offset' => 11];
        yield 'counts out of order' => ['pattern' => '/(*asr:a{2,1})/', 'code' => 'regex.quantifier.invalid_range', 'offset' => 11];
        yield 'level 251' => ['pattern' => '/'.str_repeat('(', 250).'(*asr:a)'.str_repeat(')', 250).'/', 'code' => 'regex.group.nested_too_deep', 'offset' => 256];
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_what_php_compiles(string $pattern): void
    {
        $this->assertSame(0, @preg_match($pattern, ''), \sprintf('%s should compile.', $pattern));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'group referred to after it' => ['pattern' => '/(*asr:(a))\\1/'];
        yield 'named group referred to after it' => ['pattern' => '/(*asr:(?<n>a))\\k<n>/'];
        yield 'repeated' => ['pattern' => '/(*asr:a)+/'];
        yield 'alternatives' => ['pattern' => '/(*atomic_script_run:a|b)/'];
        // Groups in any script run take numbers in the whole pattern.
        yield 'group in a script run referred to after it' => ['pattern' => '/(*sr:(a))(b)\\2/'];
        yield 'named group in a script run referred to after it' => ['pattern' => '/(*sr:(?<n>a))\\k<n>/'];
        yield 'level 250' => ['pattern' => '/'.str_repeat('(', 249).'(*asr:a)'.str_repeat(')', 249).'/'];
    }

    #[Test]
    public function test_parse_reads_an_atomic_script_run(): void
    {
        $node = Regex::create(['cache' => null])->parse('/(*asr:a+)/')->pattern;

        $this->assertInstanceOf(ScriptRunNode::class, $node);
        $this->assertTrue($node->atomic);
        $this->assertSame('a+', $node->script);
        $this->assertNotNull($node->content);

        $plain = Regex::create(['cache' => null])->parse('/(*sr:a+)/')->pattern;
        $this->assertInstanceOf(ScriptRunNode::class, $plain);
        $this->assertFalse($plain->atomic);
    }

    #[Test]
    public function test_compile_keeps_the_atomic_body(): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame('/(*asr:a+)/', $regex->parse('/(*asr:a+)/')->accept(new CompilerNodeVisitor()));
        $this->assertSame('/(*atomic_script_run:a+)/', $regex->parse('/(*atomic_script_run:a+)/')->accept(new CompilerNodeVisitor()));

        // Without the text it was read from, the long spelling.
        $tree = new RegexNode(new ScriptRunNode('a+', 0, 10, null, true), '', '/', 0, 10);
        $this->assertSame('/(*atomic_script_run:a+)/', $tree->accept(new CompilerNodeVisitor()));
    }

    #[Test]
    public function test_redos_reads_the_body_as_atomic(): void
    {
        $regex = Regex::create(['cache' => null]);

        $this->assertSame(ReDoSSeverity::CRITICAL, $regex->redos('/(*sr:(a+)+b)/')->severity);
        $this->assertSame($regex->redos('/(*sr:(?>(a+)+b))/')->severity, $regex->redos('/(*asr:(a+)+b)/')->severity);
        $this->assertNotSame(ReDoSSeverity::CRITICAL, $regex->redos('/(*asr:(a+)+b)/')->severity);
    }

    #[Test]
    public function test_redos_finds_nothing_in_an_empty_script_run(): void
    {
        $this->assertSame(ReDoSSeverity::SAFE, (new ScriptRunNode('', 0, 6, null, true))->accept(new ReDoSProfileNodeVisitor()));
    }

    #[Test]
    public function test_highlight_names_the_atomic_script_run(): void
    {
        $highlighted = (new ScriptRunNode('a', 0, 8, null, true))->accept(new HtmlHighlighterVisitor());

        $this->assertStringContainsString('atomic_script_run', $highlighted);
    }
}
