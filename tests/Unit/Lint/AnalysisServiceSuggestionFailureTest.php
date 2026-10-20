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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Exception\CacheException;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A suggestion is optional: a failure of the library while one pattern is
 * rewritten or checked leaves that pattern without one, and the run goes
 * on. Anything else is a bug, and surfaces.
 */
final class AnalysisServiceSuggestionFailureTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * A pattern the optimizer rewrites to "/\d+/", a pattern it then reads.
     */
    private const PATTERN = '/[0-9]+/';

    #[Test]
    public function test_the_rewrite_is_offered_when_nothing_fails(): void
    {
        $this->assertCount(1, self::suggestions(null));
    }

    #[Test]
    public function test_a_failure_of_the_library_leaves_the_pattern_without_a_suggestion(): void
    {
        $this->assertSame([], self::suggestions(new CacheException('The cache cannot be read.')));
    }

    #[Test]
    public function test_a_bug_surfaces(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A bug.');

        self::suggestions(new \LogicException('A bug.'));
    }

    /**
     * The suggestions for PATTERN, with a parser that reads the rewritten
     * pattern as a tree failing as given.
     *
     * @return array<mixed>
     */
    private static function suggestions(?\Throwable $failure): array
    {
        // The tree of the rewritten pattern fails when it is read.
        $failing = null === $failure ? null : new RegexNode(new class($failure) implements NodeInterface {
            public function __construct(private readonly \Throwable $failure) {}

            public function accept(NodeVisitorInterface $visitor): never
            {
                throw $this->failure;
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 3;
            }

            /**
             * @return list<NodeInterface>
             */
            public function getChildren(): array
            {
                throw $this->failure;
            }
        }, '', '/', 0, 3);
        $cache = new class($failing) implements CacheInterface {
            public function __construct(private readonly ?RegexNode $failing) {}

            public function generateKey(string $seed): string
            {
                return str_starts_with($seed, "/\\d+/\n") ? 'rewrite' : hash('xxh128', $seed);
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                return 'rewrite' === $key ? $this->failing : null;
            }
        };

        return (new AnalysisService(RegexParser::create(['cache' => $cache])))
            ->suggestOptimizations([new PatternOccurrence(self::PATTERN, 'a.php', 3, 'preg_match()')], 1);
    }
}
