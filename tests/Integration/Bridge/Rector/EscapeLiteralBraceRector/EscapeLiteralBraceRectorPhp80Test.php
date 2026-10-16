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
 * Configured for PHP 8.0 (PCRE2 10.35), which refuses a count past 65535
 * after a "{" that opens no quantifier, as "a{65536" ("number too big in
 * {} quantifier", php:8.0-cli and pcre2test 10.35), where 10.37 reads it as
 * text. The brace is escaped, which every release reads as text, and the
 * tree PHP 8.1 and later read stays the same.
 */
final class EscapeLiteralBraceRectorPhp80Test extends AbstractRectorTestCase
{
    #[Test]
    #[DataProvider('provideFixtures')]
    public function test_fixture_is_rewritten_or_left_alone(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * The running engine reads "a\{65536" as it reads "a{65536" from
     * PCRE2 10.37: the text.
     */
    #[Test]
    public function test_the_rewrite_matches_the_text_the_newer_releases_match(): void
    {
        $this->assertSame(1, preg_match('/a\{65536/', 'a{65536'));
        $this->assertSame(1, preg_match('/a{65536/', 'a{65536'));
        $this->assertSame(0, preg_match('/a\{65536/', 'aa'));
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
