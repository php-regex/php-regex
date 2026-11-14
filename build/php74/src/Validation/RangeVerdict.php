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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\PcreTarget;

/**
 * How the targets of a range judge one pattern (RangeDiff::diff()).
 */
final class RangeVerdict
{
    /**
     */
    public TargetVerdict $from;
    /**
     * @var list<TargetVerdict>
     *
     */
    public array $later;
    /**
     */
    public ?PcreTarget $readsOtherwise;
    /**
     * @var array<string, string>
     *
     */
    public array $judgedWith;

    /**
     * @internal built by RangeDiff::diff()
     *
     * @param TargetVerdict         $from           the first target's verdict
     * @param list<TargetVerdict>   $later          each later target's verdict, in ascending order; none when
     *                                              the first target refuses the pattern
     * @param PcreTarget|null       $readsOtherwise the lowest later target that accepts the pattern but reads it
     *                                              into another tree; null when every target reads it alike, or
     *                                              when one refuses it
     * @param array<string, string> $judgedWith     the releases of the range judged with another release's
     *                                              rules, "10.33" => "10.39"
     */
    public function __construct(TargetVerdict $from, array $later, ?PcreTarget $readsOtherwise, array $judgedWith)
    {
        $this->from = $from;
        $this->later = $later;
        $this->readsOtherwise = $readsOtherwise;
        $this->judgedWith = $judgedWith;
    }

    /**
     * The lowest later target that refuses a pattern the first accepts.
     */
    public function refusedBy(): ?TargetVerdict
    {
        foreach ($this->later as $verdict) {
            if (!$verdict->validation->isValid) {
                return $verdict;
            }
        }

        return null;
    }

    /**
     * Which releases of the range are judged with another release's rules,
     * for people; null when none is.
     */
    public function notice(): ?string
    {
        return RangeDiff::notice($this->judgedWith);
    }
}
