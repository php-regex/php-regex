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
use PHPRegex\Linter\Extraction\ProjectConstants;
use PHPRegex\Linter\Extraction\ProjectConstantsAwareInterface;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;

/**
 * Runs the files of a project through both extraction strategies, each
 * handed the constants every file of the project declares, and those of
 * the library files beside them: what a call reads through a name another
 * file declares. The library files give their constants and are not read
 * for patterns, as the files of a vendor directory are not.
 *
 * Both strategies must read the same, the patterns and the arguments
 * alike.
 */
trait ExtractsAcrossFiles
{
    use ExtractsWithBothStrategies;

    /**
     * The patterns read in the project files, in the order of their
     * files and positions; each says the file its literal stands in.
     *
     * @param array<string, string> $files     relative path => contents, the project
     * @param array<string, string> $libraries relative path => contents, the libraries
     *
     * @return list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}>
     */
    private function readAcross(array $files, array $libraries = []): array
    {
        [$paths, $constants] = $this->projectOf($files, $libraries);

        $fromTokens = $this->readWith($this->withConstants(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->readWith($this->withConstants(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * The patterns read in the project files, as strings, sorted.
     *
     * @param array<string, string> $files     relative path => contents, the project
     * @param array<string, string> $libraries relative path => contents, the libraries
     *
     * @return list<string>
     */
    private function patternsAcross(array $files, array $libraries = []): array
    {
        $patterns = array_map(static fn (array $read): string => $read['pattern'], $this->readAcross($files, $libraries));
        sort($patterns, \SORT_STRING);

        return $patterns;
    }

    /**
     * The pattern arguments of the project files, each as its file, its
     * expression and its state, in the order of their files and positions.
     *
     * @param array<string, string> $files     relative path => contents, the project
     * @param array<string, string> $libraries relative path => contents, the libraries
     *
     * @return list<array{file: string, expression: string, state: string}>
     */
    private function argumentsAcross(array $files, array $libraries = []): array
    {
        [$paths, $constants, $directory] = $this->projectOf($files, $libraries);

        $fromTokens = $this->argumentsWith($this->withConstants(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths, $directory);
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->argumentsWith($this->withConstants(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $constants), $paths, $directory);
            $this->assertSame($fromAst, $fromTokens, 'The token strategy states its arguments otherwise than the parser strategy.');
        }

        return array_map(static fn (array $argument): array => ['file' => $argument['file'], 'expression' => $argument['expression'], 'state' => $argument['state']], $fromTokens);
    }

    /**
     * Writes the files and reads their constants: the paths of the project
     * files, the constants of the project and of its libraries, and the
     * directory holding them all.
     *
     * @param array<string, string> $files     relative path => contents, the project
     * @param array<string, string> $libraries relative path => contents, the libraries
     *
     * @return array{list<string>, ProjectConstants, string}
     */
    private function projectOf(array $files, array $libraries): array
    {
        $this->assertSame([], array_intersect_key($files, $libraries), 'A file is of the project or of a library, not both.');

        $directory = $this->makeProject($files + $libraries);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        $libraryPaths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($libraries));

        return [
            $paths,
            ProjectConstants::of(array_map(ProjectConstants::scanFile(...), $paths), array_map(ProjectConstants::scanFile(...), $libraryPaths)),
            $directory,
        ];
    }

    private function withConstants(ExtractorInterface $strategy, ProjectConstants $constants): ExtractorInterface
    {
        $this->assertInstanceOf(ProjectConstantsAwareInterface::class, $strategy);

        return $strategy->withProjectConstants($constants);
    }
}
