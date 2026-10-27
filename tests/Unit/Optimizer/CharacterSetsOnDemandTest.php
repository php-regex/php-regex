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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The character sets a repeat is compared with are built only once a
 * repeat is a candidate for being made possessive, under the flags of the
 * pattern visited, and forgotten with that pattern: most patterns, and
 * every one rewritten without the possessive option, never need them.
 */
final class CharacterSetsOnDemandTest extends TestCase
{
    #[Test]
    public function test_character_sets_are_not_built_without_a_candidate(): void
    {
        $rewriter = new Rewriter();
        $this->assertNull($this->characterSets($rewriter));

        $this->assertSame('/a+b/', $this->rewrite($rewriter, '/a+b/'));
        $this->assertNull($this->characterSets($rewriter));
    }

    #[Test]
    public function test_character_sets_are_built_for_a_candidate_and_forgotten_with_its_pattern(): void
    {
        $rewriter = new Rewriter(possessive: true);

        $this->assertSame('/a++b/', $this->rewrite($rewriter, '/a+b/'));
        $this->assertInstanceOf(CharSetAnalyzer::class, $this->characterSets($rewriter));

        $this->assertSame('/c/', $this->rewrite($rewriter, '/c/'));
        $this->assertNull($this->characterSets($rewriter));
    }

    private function rewrite(Rewriter $rewriter, string $pattern): string
    {
        return Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(PatternPrinter::asWritten());
    }

    private function characterSets(Rewriter $rewriter): mixed
    {
        $state = (new \ReflectionProperty($rewriter, 'state'))->getValue($rewriter);
        $this->assertIsObject($state);

        return (new \ReflectionProperty($state, 'charSets'))->getValue($state);
    }
}
