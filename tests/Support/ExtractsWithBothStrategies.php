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

namespace PHPRegex\Tests\Support;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;

/**
 * Runs a piece of PHP through both extraction strategies and compares what
 * each reads with what PHP would pass: the tokenizer and nikic/php-parser
 * must agree, to the byte offset, on every occurrence.
 *
 * An expected occurrence names its position by the source text that starts
 * there ("at"), which must appear once in the code: the line and the byte
 * offset follow from it. Without a "location" key, the location is not
 * compared with an expectation, only between the strategies.
 *
 * @phpstan-type Expected array{pattern: string, at: string, source?: string, location?: string|null}
 * @phpstan-type Read array{pattern: string, line: int, offset: int|null, source: string, location: string|null}
 */
trait ExtractsWithBothStrategies
{
    use TemporaryProject;

    /**
     * @param list<array{pattern: string, at: string, source?: string, location?: string|null}> $expected
     * @param string|array<string, string>                                                      $code     one file, or relative path => contents
     * @param string|null                                                                       $file     with several files, the one the positions are read in
     */
    private function assertExtractedByBoth(array $expected, string|array $code, ?PatternFunctionRegistry $registry = null, ?string $file = null): void
    {
        $files = \is_string($code) ? ['subject.php' => $code] : $code;
        $file ??= array_key_first($files);
        $this->assertIsString($file);
        $this->assertArrayHasKey($file, $files);

        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $registry ??= PatternFunctionRegistry::native();

        $fromTokens = $this->readWith(new TokenBasedExtractionStrategy([], $registry), $paths);
        $fromAst = class_exists(ParserFactory::class) ? $this->readWith(new PhpParserExtractionStrategy([], $registry), $paths) : null;

        $wanted = [];
        foreach ($expected as $row) {
            $offset = $this->offsetOf($files[$file], $row['at']);
            $wanted[] = [
                'pattern' => $row['pattern'],
                'line' => substr_count($files[$file], "\n", 0, $offset) + 1,
                'offset' => $offset,
                'source' => $row['source'] ?? null,
                'location' => \array_key_exists('location', $row) ? $row['location'] : false,
            ];
        }

        $reference = $fromAst ?? $fromTokens;
        $this->assertSame(self::shape($wanted, $reference), $reference, null === $fromAst ? 'The token strategy' : 'The parser strategy');

        if (null !== $fromAst) {
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }
    }

    /**
     * The patterns both strategies read, in the order of their positions.
     *
     * @param string|array<string, string> $code
     *
     * @return list<string>
     */
    private function patternsReadByBoth(string|array $code, ?PatternFunctionRegistry $registry = null): array
    {
        $files = \is_string($code) ? ['subject.php' => $code] : $code;
        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $registry ??= PatternFunctionRegistry::native();

        $fromTokens = $this->readWith(new TokenBasedExtractionStrategy([], $registry), $paths);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->readWith(new PhpParserExtractionStrategy([], $registry), $paths);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }

        return array_map(static fn (array $read): string => $read['pattern'], $fromTokens);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}>
     */
    private function readWith(ExtractorInterface $strategy, array $paths): array
    {
        $read = [];
        foreach ($strategy->extract($paths) as $occurrence) {
            $this->assertInstanceOf(PatternOccurrence::class, $occurrence);
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $read[] = [
                'pattern' => $occurrence->pattern,
                'line' => $occurrence->line,
                'offset' => $occurrence->fileOffset,
                'source' => $occurrence->source,
                'location' => $occurrence->location,
                'file' => basename($occurrence->file),
            ];
        }

        // The order the strategies emit in is no contract: the position is.
        usort($read, static fn (array $a, array $b): int => [$a['file'], $a['offset'], $a['pattern']] <=> [$b['file'], $b['offset'], $b['pattern']]);

        return $read;
    }

    /**
     * The expectation in the shape of what was read: what it leaves open
     * (the source, the location) takes the value read at that position.
     *
     * @param list<array{pattern: string, line: int, offset: int, source: string|null, location: string|false|null}>         $wanted
     * @param list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}> $read
     *
     * @return list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}>
     */
    private static function shape(array $wanted, array $read): array
    {
        $byOffset = [];
        foreach ($read as $row) {
            $byOffset[($row['offset'] ?? '').'|'.$row['pattern']] = $row;
        }

        $shaped = [];
        foreach ($wanted as $row) {
            $actual = $byOffset[$row['offset'].'|'.$row['pattern']] ?? null;
            $shaped[] = [
                'pattern' => $row['pattern'],
                'line' => $row['line'],
                'offset' => $row['offset'],
                'source' => $row['source'] ?? $actual['source'] ?? '?',
                'location' => false === $row['location'] ? ($actual['location'] ?? null) : $row['location'],
                'file' => $actual['file'] ?? '?',
            ];
        }

        usort($shaped, static fn (array $a, array $b): int => [$a['file'], $a['offset'], $a['pattern']] <=> [$b['file'], $b['offset'], $b['pattern']]);

        return $shaped;
    }

    private function offsetOf(string $code, string $at): int
    {
        $offset = strpos($code, $at);
        $this->assertNotFalse($offset, 'The expected position "'.$at.'" is not in the code.');
        $this->assertSame(1, substr_count($code, $at), 'The expected position "'.$at.'" is not unique in the code.');

        return $offset;
    }
}
