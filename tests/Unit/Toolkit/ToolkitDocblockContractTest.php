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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileException;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The docblock contracts the Toolkit and its delegates owe their callers:
 * honest @throws lists on optimize()/transpile() — a consumer running
 * pepakriz/phpstan-exception-rules gets an incomplete-catch report on a list
 * that misses what the method really raises — and the LiteralSet type on the
 * literal-extraction helpers, whose @param still says mixed.
 */
final class ToolkitDocblockContractTest extends TestCase
{
    #[Test]
    public function test_optimizer_optimize_declares_the_invalid_option_throws_tag(): void
    {
        $tags = self::throwsTags(Optimizer::class, 'optimize');

        $this->assertContains(
            InvalidRegexOptionException::class,
            $tags,
            'Optimizer::optimize() must keep @throws InvalidRegexOptionException: an option key it does not know,'
            .' or a value of the wrong type, raises it through OptimizerOptions::fromArray().',
        );
    }

    #[Test]
    public function test_optimizer_optimize_declares_the_parse_exception_family(): void
    {
        $tags = self::throwsTags(Optimizer::class, 'optimize');
        $family = self::parseFamilyTags($tags);

        $this->assertNotSame(
            [],
            $family,
            sprintf(
                'Optimizer::optimize() runs RegexParser::parse() on its input, so a pattern that does not parse'
                .' escapes as the parse-phase family (ParserException or a subclass: SyntaxErrorException,'
                .' RecursionLimitException, ResourceLimitException). The docblock must @throws it — the exact class'
                .' list is the builder\'s to settle. Tagged today: %s.',
                [] === $tags ? '(nothing)' : implode(', ', $tags),
            ),
        );
    }

    #[Test]
    public function test_transpiler_transpile_declares_the_transpile_exception_throws_tag(): void
    {
        $tags = self::throwsTags(Transpiler::class, 'transpile');

        $this->assertContains(
            TranspileException::class,
            $tags,
            'Transpiler::transpile() must @throws TranspileException: a target dialect refuses a construct it'
            .' cannot express safely through it.',
        );
    }

    #[Test]
    public function test_transpiler_transpile_declares_the_parse_exception_family(): void
    {
        $tags = self::throwsTags(Transpiler::class, 'transpile');
        $family = self::parseFamilyTags($tags);

        $this->assertNotSame(
            [],
            $family,
            sprintf(
                'Transpiler::transpile() runs RegexParser::parse() before any target sees the pattern, so the'
                .' parse-phase family (ParserException or a subclass) escapes it too and belongs in its @throws'
                .' list. Tagged today: %s.',
                [] === $tags ? '(nothing)' : implode(', ', $tags),
            ),
        );
    }

    #[Test]
    #[DataProvider('provideLiteralSetHelpers')]
    public function test_literal_extraction_helper_declares_the_literal_set_type(string $helper): void
    {
        $docComment = (string) (new \ReflectionMethod(Regex::class, $helper))->getDocComment();

        if (!preg_match('/@param\s+((?:[^{}\s]|\{[^}]*\})+)\s+\$literalSet\b/', $docComment, $match)) {
            $this->fail(sprintf(
                'Regex::%s() does not document its $literalSet parameter at all; it receives what LiteralExtractor'
                .' produced, which is always a LiteralSet.',
                $helper,
            ));
        }

        [$useMap, $namespace] = self::fileImports(Regex::class);

        $this->assertSame(
            LiteralSet::class,
            self::normalizeType($match[1], $useMap, $namespace),
            sprintf(
                'The @param for $literalSet in Regex::%s() must say LiteralSet, not %s: the only producer is'
                .' literals(), which passes the LiteralExtractionResult of $ast->accept(new LiteralExtractor()) —'
                .' visitRegex() is declared to return a LiteralSet, never null, so no null arm is real.',
                $helper,
                $match[1],
            ),
        );
    }

    public static function provideLiteralSetHelpers(): iterable
    {
        yield 'extractUniqueLiterals' => ['extractUniqueLiterals'];
        yield 'processLiteralPatterns' => ['processLiteralPatterns'];
        yield 'buildSearchPatterns' => ['buildSearchPatterns'];
        yield 'determineConfidenceLevel' => ['determineConfidenceLevel'];
    }

    /**
     * The fully spelled classes the @throws tags of a method name.
     *
     * @param class-string $className
     *
     * @return list<string>
     */
    private static function throwsTags(string $className, string $method): array
    {
        $docComment = (string) (new \ReflectionMethod($className, $method))->getDocComment();
        [$useMap, $namespace] = self::fileImports($className);

        $tags = [];
        if (preg_match_all('/@throws\s+(\S+)/', $docComment, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tags[] = self::resolveTypeName($match[1], $useMap, $namespace);
            }
        }

        return $tags;
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private static function parseFamilyTags(array $tags): array
    {
        return array_values(array_filter(
            $tags,
            static fn (string $tag): bool => class_exists($tag) && is_a($tag, ParserException::class, true),
        ));
    }

    /**
     * @param array<string, string> $useMap
     */
    private static function normalizeType(string $type, array $useMap, string $namespace): string
    {
        $members = [];
        if (str_starts_with($type, '?')) {
            $members[] = 'null';
            $type = substr($type, 1);
        }

        foreach (explode('|', $type) as $member) {
            $member = trim($member);
            if ('' !== $member) {
                $members[] = self::resolveTypeName($member, $useMap, $namespace);
            }
        }

        $members = array_values(array_unique($members));
        sort($members);

        return implode('|', $members);
    }

    /**
     * @param array<string, string> $useMap
     */
    private static function resolveTypeName(string $name, array $useMap, string $namespace): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        if (str_contains($name, '\\')) {
            return $name;
        }

        return $useMap[$name] ?? ('' === $namespace ? $name : $namespace.'\\'.$name);
    }

    /**
     * @param class-string $className
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function fileImports(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $match)) {
            $namespace = $match[1];
        }

        $useMap = [];
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $statements, \PREG_SET_ORDER)) {
            foreach ($statements as $statement) {
                foreach (explode(',', $statement[1]) as $import) {
                    $import = trim($import);
                    if ('' === $import || preg_match('/^(function|const)\s+/', $import)) {
                        continue;
                    }
                    if (preg_match('/^([\w\\\\]+)(?:\s+as\s+(\w+))?$/', $import, $parts)) {
                        $pos = strrpos($parts[1], '\\');
                        $short = false === $pos ? $parts[1] : substr($parts[1], $pos + 1);
                        $useMap[$parts[2] ?? $short] = ltrim($parts[1], '\\');
                    }
                }
            }
        }

        return [$useMap, $namespace];
    }
}
