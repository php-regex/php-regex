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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Cli\Graph\MermaidDumper;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The label an edge carries for a character set: every range of the set is
 * listed, a printable endpoint is the character itself, and the first
 * printable ASCII characters — space above all — count as printable.
 */
final class CharSetLabelRenderingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLabels')]
    public function test_every_range_of_the_set_is_listed(string $pattern, string $label): void
    {
        $dump = (new MermaidDumper())->dump($this->nfaOf($pattern));

        $this->assertStringContainsString(': '.$label, $dump);
    }

    /**
     * @return iterable<string, array{pattern: string, label: string}>
     */
    public static function provideLabels(): iterable
    {
        yield 'a single character before a range' => ['pattern' => '/[ac-d]/', 'label' => '[ac-d]'];
        yield 'the space character stands alone' => ['pattern' => '/[ ]/', 'label' => '[ ]'];
        yield 'the last printable character' => ['pattern' => '/[~]/', 'label' => '[~]'];
    }

    private function nfaOf(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast)))
            ->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }
}
