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
 * A preg_match() read as a boolean becomes the string function or the
 * comparison the automata prove it is; any other read of its int|false, and
 * any call the proof does not cover, is left alone.
 */
final class PregMatchToStringComparisonRectorTest extends AbstractRectorTestCase
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
        foreach (glob(__DIR__.'/Fixture/*.php.inc') ?: [] as $file) {
            yield basename($file, '.php.inc') => ['filePath' => $file];
        }
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/config/configured_rule.php';
    }
}
