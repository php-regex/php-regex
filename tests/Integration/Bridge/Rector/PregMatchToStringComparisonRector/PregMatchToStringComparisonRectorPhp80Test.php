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

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregMatchToStringComparisonRector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Configured for PHP 8.0, the floor str_contains() and its siblings need:
 * the rule applies.
 */
final class PregMatchToStringComparisonRectorPhp80Test extends AbstractRectorTestCase
{
    #[Test]
    #[DataProvider('provideFixtures')]
    public function test_fixture_is_rewritten_or_left_alone(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return iterable<string, array{filePath: string}>
     */
    public static function provideFixtures(): iterable
    {
        foreach (glob(__DIR__.'/FixturePhp80/*.php.inc') ?: [] as $file) {
            yield basename($file, '.php.inc') => ['filePath' => $file];
        }
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/php80.php';
    }
}
