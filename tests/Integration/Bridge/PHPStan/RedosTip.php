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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Redos\RedosAnalyzer;
use PHPUnit\Framework\Assert;

/**
 * The tip of a ReDoS error: the verdict on the first line, the attack when
 * the verdict has a witness, a certified repair when there is one, then the
 * recommendations, a blank line and the documentation links.
 */
final class RedosTip
{
    /**
     * @param string $verdict                 e.g. "critical, exponential (proven)", "high, polynomial degree 3 (proven)",
     *                                        "medium, heuristic"
     * @param string $recommendationsAndLinks the recommendations, a blank line, then the links
     */
    public static function expected(string $pattern, string $verdict, bool $withAttack, string $recommendationsAndLinks): string
    {
        $tip = 'Severity: '.$verdict.".\n";

        if ($withAttack) {
            $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
            Assert::assertNotNull($witness, $pattern.' has no witness');
            $tip .= 'Attack: '.$witness->render()."\n";
        }

        $repair = (new RedosRepairer())->repair($pattern)[0] ?? null;
        if (null !== $repair && $repair->isCertified()) {
            $tip .= \sprintf("Proven repair: %s (same subjects%s, linear).\n", $repair->pattern, true === $repair->sameMatches ? ', same matches' : '');
        }

        return $tip.$recommendationsAndLinks;
    }
}
