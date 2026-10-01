<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Toolkit;

use PhpRegex\Tests\Support\UpgradeGuide;
use PhpRegex\Toolkit\Upgrade\UpgradeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 1.3 → 2.0 map behind UPGRADE-2.0.md and the Rector set: every class 1.3
 * shipped has a 2.0 name or says what replaces it.
 */
final class UpgradeMapTest extends TestCase
{
    #[Test]
    #[DataProvider('provideClassesOf13')]
    public function test_every_class_of_1_3_has_a_2_0_name_or_a_replacement(string $class): void
    {
        $renamed = \array_key_exists($class, UpgradeMap::RENAMED);
        $removed = \array_key_exists($class, UpgradeMap::REMOVED);

        $this->assertTrue($renamed xor $removed, $class.' must be renamed or removed, once');
    }

    #[Test]
    public function test_the_map_names_no_class_1_3_did_not_ship(): void
    {
        $known = array_flip(array_map(static fn (array $row): string => $row[0], iterator_to_array(self::provideClassesOf13(), false)));

        $this->assertSame([], array_values(array_diff(array_keys(UpgradeMap::RENAMED + UpgradeMap::REMOVED), array_keys($known))));
    }

    #[Test]
    public function test_every_2_0_name_exists(): void
    {
        $missing = [];
        foreach (UpgradeMap::RENAMED as $new) {
            if (!class_exists($new) && !interface_exists($new) && !enum_exists($new) && !trait_exists($new)) {
                $missing[] = $new;
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function test_every_renamed_enum_case_exists_in_2_0(): void
    {
        $missing = [];
        foreach (UpgradeMap::ENUM_CASES as $enum => $cases) {
            $new = UpgradeMap::RENAMED[$enum] ?? $enum;
            foreach ($cases as $case) {
                if (!\defined($new.'::'.$case)) {
                    $missing[] = $new.'::'.$case;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function test_every_renamed_method_exists_in_2_0(): void
    {
        $missing = [];
        foreach (UpgradeMap::METHODS as [$class, , $new]) {
            if (!method_exists(UpgradeMap::RENAMED[$class] ?? $class, $new)) {
                $missing[] = $class.'::'.$new;
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function test_the_upgrade_guide_carries_the_map(): void
    {
        $guide = (string) file_get_contents(\dirname(__DIR__, 3).'/UPGRADE-2.0.md');

        $this->assertStringContainsString(UpgradeGuide::render(), $guide, 'Run "php tests/Tools/write_upgrade_map.php".');
    }

    #[Test]
    public function test_the_rector_set_reads_the_map(): void
    {
        $set = (string) file_get_contents(\dirname(__DIR__, 3).'/src/Toolkit/Resources/rector/upgrade-2.0.php');

        $this->assertStringContainsString('UpgradeMap::RENAMED', $set);
        $this->assertStringContainsString('UpgradeMap::ENUM_CASES', $set);
        $this->assertStringContainsString('UpgradeMap::METHODS', $set);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClassesOf13(): iterable
    {
        $lines = file(\dirname(__DIR__, 2).'/Fixtures/Upgrade/v1.3.0-classes.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $class) {
            yield $class => [$class];
        }
    }
}
