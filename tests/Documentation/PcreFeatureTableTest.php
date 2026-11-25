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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The table of what changed in which release, in the PCRE concepts page,
 * lists every behaviour the code knows, with its release.
 */
#[Group('documentation')]
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

    /**
     * A case that says only where or how PCRE2 reports an error is tagged
     * internal, and its row says so: a minor release may merge it or
     * remove it.
     */
    #[Test]
    public function test_the_table_marks_the_internal_behaviours(): void
    {
        preg_match_all('/^\| `(\w+)` \| 10\.\d{2} \| (.*) \|$/m', (string) file_get_contents(__DIR__.'/../../docs/concepts/pcre.md'), $rows, \PREG_SET_ORDER);
        $marked = [];
        foreach ($rows as [, $name, $change]) {
            if (str_ends_with($change, ' *(internal)*')) {
                $marked[] = $name;
            }
        }

        $internal = [];
        foreach (PcreFeature::cases() as $feature) {
            if (1 === preg_match('/^\s*\*\s*@internal\b/m', (string) (new \ReflectionEnumUnitCase(PcreFeature::class, $feature->name))->getDocComment())) {
                $internal[] = $feature->name;
            }
        }

        $this->assertNotSame([], $internal);
        $this->assertContains('PossessiveRepeatGroupsClearedEachStart', array_diff(array_column(PcreFeature::cases(), 'name'), $internal), 'A matching behaviour stays public.');
        $this->assertSame($internal, $marked);
    }
}
