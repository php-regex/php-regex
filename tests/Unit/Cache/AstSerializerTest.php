<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Cache;

use PhpRegex\Parser\Cache\ArrayCache;
use PhpRegex\Parser\Cache\AstSerializer;
use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Tests\Support\AstFingerprint;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstSerializerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/regex-parser-ast-cache-'.uniqid('', true);
        SerializerProbe::$woken = false;
    }

    protected function tearDown(): void
    {
        (new FilesystemCache($this->cacheDir))->clear();
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_a_tree_survives_a_round_trip(string $pattern): void
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);

        $data = AstSerializer::serialize($tree);
        $restored = AstSerializer::unserialize($data);

        $this->assertStringStartsNotWith('<?php', $data);
        $this->assertInstanceOf(RegexNode::class, $restored);
        $this->assertEquals($tree, $restored);
        $this->assertSame($data, AstSerializer::serialize($restored));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a quantifier holding a comma' => ['/a{2,3}/'];
        yield 'a named group with flags' => ['/(?<year>\d{4})-\d{2}/u'];
        yield 'a class with an escaped range endpoint' => ['/[a\-z]]/'];
        yield 'a POSIX class and a trailing caret' => ['/[[:alpha:]^]/'];
        yield 'a branch reset and a backreference' => ['/(?|(a)|(b))\1/'];
        yield 'a define block with a comma and a subroutine' => ['/(?(DEFINE)(?<x>a,b))(?&x)/'];
        yield 'a unicode property and a multibyte literal' => ['/^\p{Lu}+é$/iu'];
        yield 'a keep' => ['/a\Kb/'];
        yield 'an inline flag and its cancellation' => ['/(?i)a(?-i)b/'];
        yield 'a quote and a NUL byte' => ["/'\0\"/"];
    }

    #[Test]
    public function test_the_cache_file_holds_serialized_data_that_restores_the_tree(): void
    {
        $pattern = '/(?<year>\d{4})-\d{2}/u';
        Regex::create(['cache' => new FilesystemCache($this->cacheDir)])->parse($pattern);

        $payload = (string) file_get_contents($this->onlyCacheFile());

        $this->assertSame(AstSerializer::serialize(Regex::create(['cache' => null])->parse($pattern)), $payload);

        $restored = AstSerializer::unserialize($payload);

        $this->assertInstanceOf(RegexNode::class, $restored);
        $this->assertSame('u', $restored->flags);
        $this->assertSame('(?<year>\d{4})-\d{2}', $restored->source);
    }

    #[Test]
    public function test_second_parse_reads_the_ast_back_from_disk(): void
    {
        $pattern = '/^a(b|c)+$/';

        $cache = new FilesystemCache($this->cacheDir);
        Regex::create(['cache' => $cache])->parse($pattern);

        $reader = new FilesystemCache($this->cacheDir);
        $ast = Regex::create(['cache' => $reader])->parse($pattern);

        $this->assertInstanceOf(RegexNode::class, $ast);
        $this->assertSame(['hits' => 1, 'misses' => 0], $reader->getStats());
    }

    #[Test]
    public function test_an_extended_class_is_read_back_from_a_decoded_payload(): void
    {
        $cache = new ArrayCache();
        $pattern = '/(?[ \\d - [3] ])/';
        Regex::create(['cache' => $cache, 'pcre_version' => '10.45'])->parse($pattern);

        $ast = Regex::create(['cache' => $cache, 'pcre_version' => '10.45'])->parse($pattern);

        $this->assertInstanceOf(ExtendedCharClassNode::class, $ast->pattern);
        $this->assertInstanceOf(ClassSetOperationNode::class, $ast->pattern->expression);
        $this->assertSame(['hits' => 1, 'misses' => 1], $cache->getStats());

        Regex::create(['cache' => new FilesystemCache($this->cacheDir), 'pcre_version' => '10.45'])->parse($pattern);
        $reader = new FilesystemCache($this->cacheDir);
        $fromDisk = Regex::create(['cache' => $reader, 'pcre_version' => '10.45'])->parse($pattern);

        $this->assertInstanceOf(ExtendedCharClassNode::class, $fromDisk->pattern);
        $this->assertSame(['hits' => 1, 'misses' => 0], $reader->getStats());
    }

    #[Test]
    public function test_a_payload_is_decoded_into_the_tree(): void
    {
        $cache = new FilesystemCache($this->cacheDir);
        Regex::create(['cache' => $cache, 'pcre_version' => '10.45'])->parse('/(?[ \\d - [3] ])/');

        $tree = AstSerializer::unserialize((string) file_get_contents($this->onlyCacheFile()));

        $this->assertInstanceOf(RegexNode::class, $tree);
        $this->assertInstanceOf(ExtendedCharClassNode::class, $tree->pattern);
    }

    #[Test]
    public function test_every_node_class_may_be_read_back(): void
    {
        foreach ((array) glob(\dirname(__DIR__, 3).'/src/Parser/Node/*.php') as $file) {
            $class = 'PhpRegex\\Parser\\Node\\'.basename((string) $file, '.php');
            if (class_exists($class) && is_subclass_of($class, NodeInterface::class)
                && !(new \ReflectionClass($class))->isAbstract()) {
                $this->assertContains($class, AstSerializer::NODE_CLASSES, $class);
            }
        }
    }

    /**
     * Only a tree comes back: whatever else the data holds, even a
     * well-formed value of an allowed class, reads as nothing.
     */
    #[Test]
    #[DataProvider('provideDataThatIsNotATree')]
    public function test_data_that_is_not_a_tree_is_rejected(string $data): void
    {
        $this->assertNull(AstSerializer::unserialize($data));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDataThatIsNotATree(): iterable
    {
        $tree = new RegexNode(new LiteralNode('a', 0, 1), '', '/', 0, 1);

        yield 'a serialized object that is not a node' => [serialize(new \ArrayObject([1]))];
        yield 'a serialized stdClass' => [serialize(new \stdClass())];
        yield 'a serialized array' => [serialize(['pattern' => 'a'])];
        yield 'a serialized array holding a tree' => [serialize([$tree])];
        yield 'a serialized node that is not a root' => [serialize(new LiteralNode('a', 0, 1))];
        yield 'a serialized string' => [serialize('a')];
        yield 'a serialized false' => [serialize(false)];
        yield 'a truncated tree' => [substr(serialize($tree), 0, 40)];
        yield 'garbage' => ['not serialized at all'];
        yield 'a PHP script' => ["<?php return unserialize('".serialize($tree)."');"];
        yield 'an empty string' => [''];
    }

    /**
     * A node swapped for a class outside the allowlist comes back as an
     * incomplete object no node property accepts: a miss, not an error.
     */
    #[Test]
    public function test_a_tree_holding_a_foreign_object_is_rejected(): void
    {
        $data = AstSerializer::serialize(Regex::create(['cache' => null])->parse('/a{2,3}/'));
        $planted = preg_replace('/O:\\d++:"PhpRegex\\\\Parser\\\\Node\\\\QuantifierNode"/', 'O:8:"stdClass"', $data, 1, $count);
        $this->assertSame(1, $count);

        $this->assertNull(AstSerializer::unserialize((string) $planted));
    }

    #[Test]
    public function test_a_serialized_object_of_another_class_is_never_built(): void
    {
        $this->assertNull(AstSerializer::unserialize(serialize(new SerializerProbe())));
        $this->assertFalse(SerializerProbe::$woken);
    }

    #[Test]
    public function test_a_tree_cached_by_another_ast_version_is_not_served(): void
    {
        $pattern = '/abc/';
        $regex = Regex::create(['cache' => new FilesystemCache($this->cacheDir)]);
        $seed = Regex::cacheSeed($pattern, $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH);

        // A tree for another pattern, planted where an older version of the
        // code would have stored the tree for this one.
        $planted = Regex::create(['cache' => null])->parse('/planted/');
        $writer = new FilesystemCache($this->cacheDir);
        $writer->write($writer->generateKey(str_replace(Regex::CACHE_VERSION, 'ast-0.0.0-other', $seed)), $planted);

        $reader = new FilesystemCache($this->cacheDir);
        $ast = Regex::create(['cache' => $reader])->parse($pattern);

        $this->assertSame('abc', $ast->source);
        $this->assertSame(['hits' => 0, 'misses' => 1], $reader->getStats());
    }

    #[Test]
    public function test_the_cache_version_is_the_fingerprint_of_the_code_that_builds_a_tree(): void
    {
        $this->assertSame(
            Regex::CACHE_VERSION,
            AstFingerprint::compute(),
            'The code that builds the AST changed, so trees cached before it are no longer the ones this '
            .'code would build. Run "task cache-version" and commit src/Parser/RegexParser.php.',
        );
    }

    private function onlyCacheFile(): string
    {
        $files = [];
        foreach ((array) glob($this->cacheDir.'/*/*.cache') as $file) {
            $files[] = (string) $file;
        }

        if (1 !== \count($files)) {
            self::fail(\sprintf('Expected exactly one cache file, found %d.', \count($files)));
        }

        return $files[0];
    }
}

/**
 * An object whose rebuilding would be seen: unserialize() calls
 * __unserialize() only when it builds the object for real.
 */
final class SerializerProbe
{
    public static bool $woken = false;

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        self::$woken = true;
    }
}
