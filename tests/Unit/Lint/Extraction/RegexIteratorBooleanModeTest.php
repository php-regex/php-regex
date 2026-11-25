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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A RegexIterator mode spelled true or false is the integer PHP coerces
 * it to, 1 or 0: GET_MATCH and MATCH, which stop at the first match. The
 * call names no loop, through both extraction strategies.
 *
 * The oracle builds the iterator as code without strict types does, the
 * boolean coerced, and runs it with "(?:a+\d)?", which matches the empty
 * string at each position of a run of "a": it makes one match.
 */
final class RegexIteratorBooleanModeTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    #[DataProvider('provideBooleanModes')]
    public function test_extraction_reads_a_boolean_mode_as_the_integer_php_coerces_it_to(string $spelled, bool $mode, int $coerced): void
    {
        $iterator = (new \ReflectionClass(\RegexIterator::class))->newInstanceArgs([new \ArrayIterator([str_repeat('a', 2000)]), '/(?:a+\d)?/', $mode]);
        $this->assertSame($coerced, $iterator->getMode(), 'Oracle: PHP coerces the mode.');
        $current = null;
        foreach ($iterator as $value) {
            $current = $value;
        }
        $this->assertTrue(\RegexIterator::GET_MATCH === $coerced ? [''] === $current : str_repeat('a', 2000) === $current, 'Oracle: the mode stops at the first match.');

        $code = "<?php\n\$it = new \\ArrayIterator(['x']);\nnew RegexIterator(\$it, '/p/', ".$spelled.");\n";
        $this->assertSame([['/p/', null]], $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The token strategy.');
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', null]], $this->readWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The parser strategy.');
        }
    }

    /**
     * @return iterable<string, array{spelled: string, mode: bool, coerced: int}>
     */
    public static function provideBooleanModes(): iterable
    {
        yield 'true' => ['spelled' => 'true', 'mode' => true, 'coerced' => \RegexIterator::GET_MATCH];
        yield 'false' => ['spelled' => 'false', 'mode' => false, 'coerced' => \RegexIterator::MATCH];
        yield 'TRUE by name' => ['spelled' => 'mode: TRUE', 'mode' => true, 'coerced' => \RegexIterator::GET_MATCH];
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
