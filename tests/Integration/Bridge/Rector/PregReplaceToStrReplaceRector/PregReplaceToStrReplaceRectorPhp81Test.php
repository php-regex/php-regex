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

namespace PHPRegex\Tests\Integration\Bridge\Rector\PregReplaceToStrReplaceRector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Configured for PHP 8.1: preg_* refuses a pattern holding a raw NUL byte
 * before 8.2 ("Null byte in regex", false), where the string function
 * answers, so a raw NUL is left alone; the \x00 escape is not a raw NUL.
 */
final class PregReplaceToStrReplaceRectorPhp81Test extends AbstractRectorTestCase
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
        foreach (glob(__DIR__.'/FixturePhp81/*.php.inc') ?: [] as $file) {
            yield basename($file, '.php.inc') => ['filePath' => $file];
        }
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/php81.php';
    }
}
