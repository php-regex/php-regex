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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Parser\PcreFeature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The table of what changed in which release, in the PCRE concepts page,
 * lists every behaviour the code knows, with its release.
 */
final class PcreFeatureTableTest extends TestCase
{
    #[Test]
    public function test_the_table_lists_every_behaviour_with_its_release(): void
    {
        preg_match_all('/^\| `(\w+)` \| (10\.\d{2}) \|/m', (string) file_get_contents(__DIR__.'/../../docs/concepts/pcre.md'), $rows, \PREG_SET_ORDER);
        $documented = [];
        foreach ($rows as [, $name, $release]) {
            $documented[$name] = $release;
        }

        $known = [];
        foreach (PcreFeature::cases() as $feature) {
            $known[$feature->name] = $feature->release();
        }

        $this->assertSame($known, $documented);
    }
}
