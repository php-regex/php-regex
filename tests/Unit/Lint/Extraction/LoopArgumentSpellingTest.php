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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The arguments that decide which loop a call runs its pattern in, read as
 * PHP binds them however they are written: an argument right after a
 * comma, with no space, is the next position; a replacement is a callback
 * only when the whole argument is a closure, not when an array holds one
 * or when the closure is called at once.
 *
 * Every case runs through both strategies.
 */
final class LoopArgumentSpellingTest extends TestCase
{
    use TemporaryProject;

    /**
     * RegexIterator::ALL_MATCHES gives every match of the subject: three for
     * "/a/" on "aaa", where MATCH stops at the first.
     */
    #[Test]
    public function test_extraction_reads_a_mode_written_right_after_a_comma(): void
    {
        $this->assertSame([[['a', 'a', 'a']]], iterator_to_array(new \RegexIterator(new \ArrayIterator(['aaa']), '/a/', \RegexIterator::ALL_MATCHES)), 'Oracle: ALL_MATCHES gives every match.');
        $this->assertSame(['aaa'], iterator_to_array(new \RegexIterator(new \ArrayIterator(['aaa']), '/a/', \RegexIterator::MATCH)), 'Oracle: MATCH keeps the subject.');

        $this->assertReadByBoth("\$it = new \\ArrayIterator([]);\nnew RegexIterator(\$it,'/p/',RegexIterator::ALL_MATCHES);", 'preg_match_all', []);
    }

    /**
     * Laravel's Str::replaceMatches() runs preg_replace_callback() for a
     * replacement that is a Closure, preg_replace() for anything else
     * (laravel/framework 13.44, src/Illuminate/Support/Str.php). The value
     * PHP builds for the argument is the oracle.
     */
    #[Test]
    #[DataProvider('provideReplacements')]
    public function test_extraction_reads_a_callback_only_where_the_whole_replacement_is_a_closure(string $replacement, mixed $value, string $everyMatch): void
    {
        $this->assertSame('preg_replace_callback' === $everyMatch, $value instanceof \Closure, 'Oracle: what PHP builds for '.$replacement);

        $this->assertReadByBoth("\\Illuminate\\Support\\Str::replaceMatches('/p/', ".$replacement.', $s);', $everyMatch, [InteropPresets::ILLUMINATE_SUPPORT]);
    }

    /**
     * @return iterable<string, array{replacement: string, value: mixed, everyMatch: string}>
     */
    public static function provideReplacements(): iterable
    {
        yield 'an arrow function in parentheses' => ['replacement' => "(static fn (array \$m): string => '')", 'value' => (static fn (array $m): string => ''), 'everyMatch' => 'preg_replace_callback'];
        yield 'an array holding an arrow function' => ['replacement' => "[static fn (array \$m): string => '']", 'value' => [static fn (array $m): string => ''], 'everyMatch' => 'preg_replace'];
        yield 'an arrow function called at once' => ['replacement' => "(static fn (): string => '')()", 'value' => (static fn (): string => '')(), 'everyMatch' => 'preg_replace'];
    }

    /**
     * @param list<string> $presets
     */
    private function assertReadByBoth(string $code, string $everyMatch, array $presets): void
    {
        $registry = [] === $presets ? PatternFunctionRegistry::native() : PatternFunctionRegistry::create($presets);
        $code = "<?php\n\$s = 'x';\n".$code."\n";
        $this->assertSame([['/p/', $everyMatch]], $this->readWith(new TokenBasedExtractionStrategy([], $registry), $code), 'The token strategy.');
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', $everyMatch]], $this->readWith(new PhpParserExtractionStrategy([], $registry), $code), 'The parser strategy.');
        }
    }

    /**
     * The pattern and the loop of each occurrence the strategy reads.
     *
     * @return list<array{string, string|null}>
     */
    private function readWith(ExtractorInterface $strategy, string $code): array
    {
        $directory = $this->makeProject(['subject.php' => $code]);
        $read = [];
        foreach ($strategy->extract([$directory.'/subject.php']) as $occurrence) {
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $read[] = [$occurrence->pattern, $occurrence->everyMatch];
        }

        return $read;
    }
}
