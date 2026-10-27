<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

use PHPRegex\Build\Verify\Witness;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\TestCase;

/**
 * A tree the twin wrote to a cache directory comes back from it to another
 * parser, and reads as the tree just parsed: the capture shape a consumer
 * types from it is the same.
 */
final class CacheRoundTripTest extends TestCase
{
    /**
     * @var string
     */
    private $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/twin-cache-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->directory.'/*') ?: [] as $directory) {
            is_dir($directory) ? rmdir($directory) : unlink($directory);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function providePatterns(): iterable
    {
        yield 'groups of every kind' => ['/(a)(?<n>b)(?:c)(?|(d)|(e))(?=f)/'];
        yield 'quantified alternation' => ['/^(?:(\d+)|([a-z]+))*$/i'];
        yield 'conditional' => ['/(a)?(?(1)b|c)/'];
    }

    /**
     * @dataProvider providePatterns
     */
    public function test_a_cached_tree_reads_as_the_tree_parsed(string $pattern): void
    {
        $options = ['cache' => $this->directory, 'php_version' => 70400];
        $parsed = RegexParser::create($options)->parse($pattern);

        $reader = RegexParser::create($options);
        $restored = $reader->parse($pattern);

        $this->assertSame(1, $reader->getCacheStats()['hits'], 'The second parser read the tree from the cache.');
        $this->assertSame(Witness::normalize($parsed), Witness::normalize($restored));
        $this->assertSame(
            Witness::normalize((new CaptureShapeAnalyzer())->analyze($parsed)),
            Witness::normalize((new CaptureShapeAnalyzer())->analyze($restored)),
        );
    }
}
