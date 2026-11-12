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

namespace PHPRegex\Linter;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPRegex\Parser\Validation\TargetVerdict;

/**
 * What the later PHP versions of a range find in a pattern the floor
 * accepts, decided once for the lint and the language server: the lowest
 * version that refuses it; else the lowest that reads it into another
 * tree; else, when the trees are compared, a change of meaning that keeps
 * the tree. One finding at most: a refusal hides what a later version
 * reads otherwise, and either hides a change of meaning.
 *
 * @internal
 */
final class RangeFindings
{
    /**
     * @param TargetVerdict|null       $refusedBy      the verdict of the lowest later version that refuses the pattern
     * @param string                   $refusedOn      the versions that refuse it, "PHP 8.5 and later"; empty when none does
     * @param PcreTarget|null          $readsOtherwise the lowest later version that reads the pattern into another tree
     * @param SilentMeaningChange|null $silentChange   the first change of meaning that keeps the tree
     */
    private function __construct(
        public readonly ?TargetVerdict $refusedBy = null,
        public readonly string $refusedOn = '',
        public readonly ?PcreTarget $readsOtherwise = null,
        public readonly ?SilentMeaningChange $silentChange = null,
    ) {}

    /**
     * The findings of the later versions of a range on a pattern the floor
     * accepts.
     *
     * @param RegexParser       $floor         the parser of the floor
     * @param list<RegexParser> $range         a parser for each version after the floor, in ascending order
     * @param RegexNode         $ast           the pattern as the floor parses it
     * @param bool              $comparesTrees whether another tree and a change of meaning are looked for
     */
    public static function of(RegexParser $floor, array $range, string $pattern, RegexNode $ast, bool $comparesTrees): self
    {
        if ([] === $range) {
            return new self();
        }

        $diff = (new RangeDiff($floor, $range))->diff($pattern, $comparesTrees);
        $refusedBy = $diff->refusedBy();
        if (null !== $refusedBy) {
            return new self($refusedBy, RangeWording::refusedOn($diff));
        }

        // RangeDiff compares the trees only when every version accepts the
        // pattern, and only when asked.
        if (null !== $diff->readsOtherwise) {
            return new self(readsOtherwise: $diff->readsOtherwise);
        }

        if (!$comparesTrees) {
            return new self();
        }

        $later = array_map(static fn (RegexParser $parser): PcreTarget => $parser->target(), $range);

        return new self(silentChange: SilentMeaningChange::first($ast, $floor->target(), $later));
    }

    /**
     * The finding in the words of lint and the language server, as a
     * command that reads one pattern says it: the refusal, an error, else
     * the other tree or the change of meaning, a warning; both null when
     * nothing is found.
     *
     * @return array{refusal: string|null, warning: string|null}
     */
    public function wording(): array
    {
        if (null !== $this->refusedBy) {
            return ['refusal' => RangeWording::refusal($this->refusedOn, $this->refusedBy->validation), 'warning' => null];
        }

        if (null !== $this->readsOtherwise) {
            return ['refusal' => null, 'warning' => RangeWording::readsOtherwise($this->readsOtherwise)];
        }

        if (null !== $this->silentChange) {
            return ['refusal' => null, 'warning' => RangeWording::silentChange($this->silentChange->target, $this->silentChange->message)];
        }

        return ['refusal' => null, 'warning' => null];
    }
}
