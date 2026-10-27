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
}
