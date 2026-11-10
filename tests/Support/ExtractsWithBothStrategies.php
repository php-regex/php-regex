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
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Resolution\ArgumentState;

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
 * The pattern arguments are read the same way: every argument both
 * strategies anchor, a literal one included, with the state it ends in.
 * An expected argument is placed by its expression, the argument as the
 * source writes it, which starts where the argument does; when the
 * expression appears more than once in the code, "at" names a longer text
 * starting there that appears once.
 *
 * @phpstan-type Expected array{pattern: string, at: string, source?: string, location?: string|null}
 * @phpstan-type Read array{pattern: string, line: int, offset: int|null, source: string, location: string|null}
 * @phpstan-type ExpectedArgument array{state: string, expression: string, at?: string, source?: string, file?: string}
 * @phpstan-type Argument array{state: string, expression: string, line: int, column: int|null, offset: int|null, source: string, file: string}
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
     * Asserts the pattern arguments both strategies anchor in the code, all
     * of them: each with its state, and where its first token stands once
     * its outer parentheses are stripped, as a line, a column counted in
     * bytes from 1 and a byte offset. Without a "source" key, the source is
     * not compared with an expectation, only between the strategies.
     *
     * @param list<array{state: string, expression: string, at?: string, source?: string, file?: string}> $expected
     * @param string|array<string, string>                                                                $code     one file, or relative path => contents
     * @param string|null                                                                                 $file     with several files, the one an expected argument is read in when it names none
     */
    private function assertArgumentsReadByBoth(array $expected, string|array $code, ?PatternFunctionRegistry $registry = null, ?string $file = null): void
    {
        $files = \is_string($code) ? ['subject.php' => $code] : $code;
        $file ??= array_key_first($files);
        $this->assertIsString($file);

        $read = $this->argumentsReadByBoth($code, $registry);
        $sources = [];
        foreach ($read as $row) {
            $sources[$row['file'].'|'.($row['offset'] ?? '').'|'.$row['expression']] = $row['source'];
        }

        $wanted = [];
        foreach ($expected as $row) {
            $in = $row['file'] ?? $file;
            $this->assertArrayHasKey($in, $files);
            $at = $row['at'] ?? $row['expression'];
            $this->assertNotSame('', $row['expression'], 'An argument is written with something.');
            $this->assertStringStartsWith($row['expression'], $at, 'The position of an argument starts with its expression.');

            $offset = $this->offsetOf($files[$in], $at);
            $lineStart = strrpos(substr($files[$in], 0, $offset), "\n");
            $wanted[] = [
                'state' => $row['state'],
                'expression' => $row['expression'],
                'line' => substr_count($files[$in], "\n", 0, $offset) + 1,
                'column' => false === $lineStart ? $offset + 1 : $offset - $lineStart,
                'offset' => $offset,
                'source' => $row['source'] ?? $sources[$in.'|'.$offset.'|'.$row['expression']] ?? '?',
                'file' => $in,
            ];
        }

        usort($wanted, static fn (array $a, array $b): int => [$a['file'], $a['offset'], $a['expression'], $a['state']] <=> [$b['file'], $b['offset'], $b['expression'], $b['state']]);

        $this->assertSame($wanted, $read);
    }

    /**
     * The pattern arguments both strategies anchor, one row per argument,
     * in the order of their positions: the two strategies give the same
     * state, line, column, byte offset, source and expression for each.
     *
     * For each strategy, extractWithArguments() gives what extract() gives,
     * in the same order, plus the arguments; extract() gives no argument.
     *
     * @param string|array<string, string> $code one file, or relative path => contents
     *
     * @return list<array{state: string, expression: string, line: int, column: int|null, offset: int|null, source: string, file: string}>
     */
    private function argumentsReadByBoth(string|array $code, ?PatternFunctionRegistry $registry = null): array
    {
        $files = \is_string($code) ? ['subject.php' => $code] : $code;
        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $registry ??= PatternFunctionRegistry::native();

        $fromTokens = $this->argumentsWith(new TokenBasedExtractionStrategy([], $registry), $paths, $directory);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->argumentsWith(new PhpParserExtractionStrategy([], $registry), $paths, $directory);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy states its arguments otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * The state of each pattern argument both strategies anchor, in the
     * order of their positions.
     *
     * @param string|array<string, string> $code
     *
     * @return list<string>
     */
    private function statesReadByBoth(string|array $code, ?PatternFunctionRegistry $registry = null): array
    {
        return array_map(static fn (array $argument): string => $argument['state'], $this->argumentsReadByBoth($code, $registry));
    }

    /**
     * @param list<string> $paths
     *
     * @return list<array{state: string, expression: string, line: int, column: int|null, offset: int|null, source: string, file: string}>
     */
    private function argumentsWith(ExtractorInterface $strategy, array $paths, string $directory): array
    {
        $this->assertInstanceOf(ArgumentAwareInterface::class, $strategy);

        $arguments = [];
        $patterns = [];
        foreach ($strategy->extractWithArguments($paths) as $occurrence) {
            $this->assertInstanceOf(PatternOccurrence::class, $occurrence);
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            if (null === $occurrence->state) {
                $this->assertNull($occurrence->expression, 'Only an argument carries its expression.');
                $patterns[] = self::patternRow($occurrence);

                continue;
            }

            $this->assertNotNull(ArgumentState::tryFrom($occurrence->state), 'An argument ends in one of the five states, got "'.$occurrence->state.'".');
            $this->assertSame('', $occurrence->pattern, 'An argument holds no pattern: its patterns are occurrences of their own.');
            $this->assertNotNull($occurrence->expression, 'An argument carries its expression.');
            $arguments[] = [
                'state' => $occurrence->state,
                'expression' => $occurrence->expression,
                'line' => $occurrence->line,
                'column' => $occurrence->column,
                'offset' => $occurrence->fileOffset,
                'source' => $occurrence->source,
                'file' => substr($occurrence->file, \strlen($directory) + 1),
            ];
        }

        // The same strategy, asked for the patterns alone after it gave
        // the arguments: none is left in what it returns.
        $extracted = [];
        foreach ($strategy->extract($paths) as $occurrence) {
            $this->assertInstanceOf(PatternOccurrence::class, $occurrence);
            $this->assertNull($occurrence->state, 'extract() returns no argument.');
            $this->assertNull($occurrence->expression, 'extract() returns no argument.');
            $extracted[] = self::patternRow($occurrence);
        }

        $this->assertSame($extracted, $patterns, 'extractWithArguments() returns what extract() returns, in the same order, plus the arguments.');

        usort($arguments, static fn (array $a, array $b): int => [$a['file'], $a['offset'], $a['expression'], $a['state']] <=> [$b['file'], $b['offset'], $b['expression'], $b['state']]);

        return $arguments;
    }

    /**
     * @return array{pattern: string, file: string, line: int, column: int|null, offset: int|null, source: string, location: string|null}
     */
    private static function patternRow(PatternOccurrence $occurrence): array
    {
        return [
            'pattern' => $occurrence->pattern,
            'file' => basename($occurrence->file),
            'line' => $occurrence->line,
            'column' => $occurrence->column,
            'offset' => $occurrence->fileOffset,
            'source' => $occurrence->source,
            'location' => $occurrence->location,
        ];
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
