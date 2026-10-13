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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shipped rules run inside the consumer's own PHPStan, against whatever
 * php-parser that PHPStan brings: an API php-parser has deprecated puts a
 * deprecation notice in every analyse run of every consumer. NodeAbstract
 * ::getLine() is deprecated since php-parser 5; getStartLine() is the
 * replacement, present since 4.x, so the sources call the one every host
 * accepts without a notice.
 */
final class ShippedRuleSourcesTest extends TestCase
{
    private const BRIDGE_SOURCES = __DIR__.'/../../../../src/PHPStan';

    #[Test]
    #[DataProvider('provideBridgeSources')]
    public function test_a_shipped_source_avoids_deprecated_php_parser_apis(string $file): void
    {
        $source = (string) file_get_contents($file);

        $this->assertDoesNotMatchRegularExpression(
            '/->getLine\(\)/',
            $source,
            \sprintf('%s calls NodeAbstract::getLine(), deprecated since php-parser 5: use getStartLine().', substr($file, \strlen(self::BRIDGE_SOURCES) + 1)),
        );
    }

    /**
     * @return iterable<string, array{file: string}>
     */
    public static function provideBridgeSources(): iterable
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::BRIDGE_SOURCES, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        if ([] === $files) {
            self::fail(\sprintf('No sources found under %s.', self::BRIDGE_SOURCES));
        }

        foreach ($files as $file) {
            yield substr($file, \strlen(self::BRIDGE_SOURCES) + 1) => ['file' => $file];
        }
    }
}
