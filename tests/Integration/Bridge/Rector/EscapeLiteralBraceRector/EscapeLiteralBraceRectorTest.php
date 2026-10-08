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

namespace PHPRegex\Tests\Integration\Bridge\Rector\EscapeLiteralBraceRector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Configured for PHP 8.2 (PCRE2 10.40): "{,3}" and "{ 2 }" are text there
 * and quantifiers from PCRE2 10.43 (PHP 8.4). The brace is escaped, which
 * reads as text in every release.
 */
final class EscapeLiteralBraceRectorTest extends AbstractRectorTestCase
{
    #[Test]
    #[DataProvider('provideFixtures')]
    public function test_fixture_is_rewritten_or_left_alone(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * The running PCRE2 reads "\{" as the text PCRE2 before 10.43 read
     * "{,3}" as.
     */
    #[Test]
    public function test_the_rewrite_matches_the_text_the_old_release_matched(): void
    {
        $this->assertSame(1, preg_match('/a\{,3}/', 'a{,3}'));
        $this->assertSame(0, preg_match('/a\{,3}/', 'aaa'));
        $this->assertSame(1, preg_match('/x\{ 2 }/', 'x{ 2 }'));
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
        return __DIR__.'/config/php82.php';
    }
}
