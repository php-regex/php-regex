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

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\RangeVerdict;

/**
 * How the lint and the language server word what the later PHP versions
 * of a range say of a pattern the floor accepts, so both read alike.
 *
 * @internal
 */
final class RangeWording
{
    /**
     * The versions of the range that refuse the pattern: "PHP 8.5 and
     * later", or each stretch of them, "PHP 8.4 and later, before 8.4.25;
     * PHP 8.5 and later". No rule of the library stops refusing a pattern
     * at a later PHP today; the stretch is named the day one does.
     */
    public static function refusedOn(RangeVerdict $diff): string
    {
        $runs = [];
        $from = null;
        foreach ($diff->later as $verdict) {
            if ($verdict->validation->isValid) {
                if (null !== $from) {
                    $runs[] = \sprintf('PHP %s and later, before %s', $from, ProjectTarget::phpLabel($verdict->target->phpVersionId));
                    $from = null;
                }

                continue;
            }

            $from ??= ProjectTarget::phpLabel($verdict->target->phpVersionId);
        }
        if (null !== $from) {
            $runs[] = \sprintf('PHP %s and later', $from);
        }

        return implode('; ', $runs);
    }

    /**
     * The message of a pattern the lowest later version reads into another
     * tree.
     */
    public static function readsOtherwise(PcreTarget $target): string
    {
        return \sprintf('From PHP %s (PCRE2 %s) the pattern parses differently: the same text means something else there.', ProjectTarget::phpLabel($target->phpVersionId), $target->pcreVersion);
    }

    /**
     * The message of a change of meaning that keeps the tree, from the
     * version it starts at: "From PHP 8.0 (PCRE2 10.35) " then what changes.
     */
    public static function silentChange(PcreTarget $target, string $change): string
    {
        return \sprintf('From PHP %s (PCRE2 %s) %s', ProjectTarget::phpLabel($target->phpVersionId), $target->pcreVersion, $change);
    }
}
