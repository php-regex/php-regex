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

use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\Internal\PhpVersionGates;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\RegexParser;

/**
 * Judges a pattern on every PHP version a rule of the library changes at,
 * with every PCRE2 release from 10.40 to the newest the library knows: the
 * validator itself runs at each point, so the answer can never drift from
 * validate(). One answer comes from the running engine: a Unicode property
 * name ("\p{...}", "\P{...}") is looked up there, at every point, unless the
 * library knows the PCRE2 release that added it, which then decides at each
 * point. An undated name is accepted at every point when the running engine
 * knows it, and refused at every point when it does not.
 *
 *     $compatibility = (new CompatibilityChecker())->check('/(?<=a\Kb)c/');
 *     $compatibility->isValidEverywhere(); // false: PHP 8.5 refuses \K there
 *
 * A pattern is never a reason to throw: one no target can read, a missing
 * delimiter included, gets a verdict refusing it at every point.
 */
final class CompatibilityChecker
{
    private const OLDEST_RELEASE = 40;

    public function check(string $regex): PatternCompatibility
    {
        // Targets that read a pattern the same way share one cached tree:
        // a patch point runs the validator only.
        $cache = new ArrayCache();

        $verdicts = [];
        foreach (PhpVersionGates::POINTS as $php) {
            foreach (self::releases() as $release) {
                $parser = RegexParser::create(['php_version' => $php, 'pcre_version' => $release, 'cache' => $cache]);
                $verdicts[] = new TargetVerdict($parser->target(), $parser->validate($regex));
            }
        }

        return new PatternCompatibility($verdicts);
    }

    /**
     * 10.40 up to the newest release a PCRE2 behaviour the library knows
     * arrived in.
     *
     * @return list<string>
     */
    private static function releases(): array
    {
        $newest = self::OLDEST_RELEASE;
        foreach (PcreFeature::cases() as $feature) {
            $newest = max($newest, (int) explode('.', $feature->release())[1]);
        }

        return array_map(static fn (int $minor): string => '10.'.$minor, range(self::OLDEST_RELEASE, $newest));
    }
}
