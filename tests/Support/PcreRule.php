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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;

/**
 * The PCRE2 a version is judged with when no release is pinned, as every
 * entry point resolves it: the running PCRE2 for the running PHP's minor
 * version, else the one that version bundles. An expectation written for
 * PHP 8.4 reads 10.44 under any PHP but 8.4, and the release the running
 * engine links under PHP 8.4.
 */
trait PcreRule
{
    private static function judgedPcre(int $phpVersionId): string
    {
        return intdiv($phpVersionId, 100) === intdiv(\PHP_VERSION_ID, 100)
            ? PcreTarget::runtime()->pcreVersion
            : PcreTarget::bundledWith($phpVersionId)->pcreVersion;
    }

    /**
     * A "8.4/10.44" label as the rule judges it: the release replaced by
     * the running one when the version is the running PHP's minor.
     */
    private static function judgedLabel(string $label): string
    {
        [$php, $pcre] = explode('/', $label, 2);
        $parts = array_map(intval(...), explode('.', $php)) + [0, 0, 0];
        $phpVersionId = $parts[0] * 10000 + $parts[1] * 100 + $parts[2];

        return intdiv($phpVersionId, 100) === intdiv(\PHP_VERSION_ID, 100) ? $php.'/'.PcreTarget::runtime()->pcreVersion : $php.'/'.$pcre;
    }

    /**
     * A version of a report, a "php" and a "pcre" beside what else it
     * says, as the rule judges it.
     *
     * @param array{php: string, pcre: string} $row
     *
     * @return array{php: string, pcre: string}
     */
    private static function judgedRow(array $row): array
    {
        $row['pcre'] = explode('/', self::judgedLabel($row['php'].'/'.$row['pcre']), 2)[1];

        return $row;
    }

    /**
     * Skip a row written for the PCRE2 a version bundles under that
     * version's minor when the running PHP links another release: the rule
     * judges it with the running one there, and every other PHP keeps the
     * row.
     */
    private function requiresBundledPcre2(int $phpVersionId, string $subject): void
    {
        $bundled = PcreTarget::bundledWith($phpVersionId)->pcreVersion;
        if (intdiv($phpVersionId, 100) === intdiv(\PHP_VERSION_ID, 100) && PcreTarget::runtime()->pcreVersion !== $bundled) {
            $this->markTestSkipped(\sprintf('%s is verified against PHP %d.%d with the PCRE2 %s it bundles; PHP %s links PCRE2 %s.', $subject, intdiv($phpVersionId, 10000), intdiv($phpVersionId, 100) % 100, $bundled, \PHP_VERSION, \PCRE_VERSION));
        }
    }

    /**
     * The gate above for a row that judges every version from one minor to
     * another, each with the release it bundles: skipped under such a minor
     * only where the running PCRE2 and the bundled one read the rows apart,
     * on one of the features their patterns use, all from 10.43.
     */
    private function requiresBundledPcre2Within(int $from, int $through, string $subject): void
    {
        if (\PHP_VERSION_ID < intdiv($from, 100) * 100 || \PHP_VERSION_ID >= (intdiv($through, 100) + 1) * 100) {
            return;
        }

        $bundled = PcreTarget::bundledWith(\PHP_VERSION_ID);
        $runtime = PcreTarget::runtime();
        foreach ([PcreFeature::AsciiOptions, PcreFeature::OpenAndPaddedRepeatCounts, PcreFeature::CaselessRestrictModifier, PcreFeature::VariableLengthLookbehind] as $feature) {
            if ($bundled->supports($feature) !== $runtime->supports($feature)) {
                $this->requiresBundledPcre2(\PHP_VERSION_ID, $subject);
            }
        }
    }
}
