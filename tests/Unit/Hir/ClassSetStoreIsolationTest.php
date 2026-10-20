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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The directory the class scans are kept in is set for the whole process:
 * the PHPStan rule sets it to PHPStan's temporary directory, the Psalm
 * plugin to Psalm's cache, the CLI to --cache-dir. A test that builds one
 * of them, as through PHPStan's container, does not leave that store to
 * the tests after it, which would read and write it beside the other test
 * processes: the suite forgets it once each test ends.
 */
final class ClassSetStoreIsolationTest extends TestCase
{
    #[Test]
    public function test_the_phpstan_rule_keeps_the_class_scans_in_the_directory_it_is_given(): void
    {
        new RegexPatternRule(cacheDirectory: sys_get_temp_dir().\DIRECTORY_SEPARATOR.'php-regex-isolation-'.bin2hex(random_bytes(6)));

        $this->assertInstanceOf(ClassSetStore::class, self::store());
    }

    #[Test]
    #[Depends('test_the_phpstan_rule_keeps_the_class_scans_in_the_directory_it_is_given')]
    public function test_the_next_test_starts_with_the_class_scans_kept_in_memory_only(): void
    {
        $this->assertNull(self::store());
    }

    private static function store(): ?ClassSetStore
    {
        $store = (new \ReflectionProperty(ClassSetProvider::class, 'store'))->getValue();
        self::assertTrue(null === $store || $store instanceof ClassSetStore);

        return $store;
    }
}
