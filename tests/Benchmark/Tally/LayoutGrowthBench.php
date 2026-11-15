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

namespace PHPRegex\Tests\Benchmark\Tally;

use PHPRegex\Explain\RailroadLayoutVisitor;
use PHPRegex\Optimizer\LayoutSplicer;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\DisplayEscaper;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Tally\Support\Ladders;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;
use Tally\Meter;

/**
 * The forms a tree is written back to: the railroad layout, the spliced
 * source of a rewritten pattern, and the escaped display of a pattern. Each
 * once merged or searched what it had written so far for every part.
 */
#[Warmup(1)]
final class LayoutGrowthBench
{
    use Ladders;

    public function __construct(private readonly Meter $meter) {}

    /**
     * Each child merged the whole layout so far: four times the children
     * took some sixteen times the work.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchRailroadSequence(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): SequenceNode => new SequenceNode(array_fill(0, $size, new DotNode(0, 1)), 0, $size));
        $tree->accept(new RailroadLayoutVisitor());
    }

    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchRailroadAlternation(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): AlternationNode => new AlternationNode(array_fill(0, $size, new DotNode(0, 1)), 0, $size));
        $tree->accept(new RailroadLayoutVisitor());
    }

    /**
     * Rewritten classes spliced back into the source the user wrote: only
     * the splice is counted, the parse and the rewrite are not.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchSpliceRewrittenClasses(int $size): void
    {
        $parser = ColdStart::regex()->parser();
        [$original, $rewritten] = $this->meter->exclude(static function () use ($parser, $size): array {
            $original = $parser->parse('/'.str_repeat('[aa][bb]', $size).'/x');
            $rewritten = $original->accept(new Rewriter(factorize: false));
            \assert($rewritten instanceof RegexNode);

            return [$original, $rewritten];
        });

        (new LayoutSplicer($parser))->splice($original, $rewritten);
    }

    /**
     * Comments dropped from the display of a pattern under (*ANY), each one
     * searching the rest of the pattern for the end of its line.
     */
    #[DataProvider('from2000')]
    #[Linear('size')]
    public function benchDisplayWithoutComments(int $size): void
    {
        // The escaper keeps what it wrote by pattern: the counted call must
        // write it again, as the first one did.
        (new \ReflectionProperty(DisplayEscaper::class, 'escapes'))->setValue(null, []);
        DisplayEscaper::escape('/(*ANY)'.str_repeat("#\n", $size).'a/x');
    }
}
