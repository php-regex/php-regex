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

use PHPRegex\Redos\RedosAnalyzer;
use PHPUnit\Framework\Assert;

/**
 * The tip of a ReDoS error: the verdict on the first line, the attack when
 * the verdict has a witness, then the recommendations, a blank line and the
 * documentation links.
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

        return $tip.$recommendationsAndLinks;
    }
}
