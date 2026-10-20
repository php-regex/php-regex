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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\PHPStan\PatternChecker;
use PHPRegex\Tests\Support\DocumentationPages;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PublicSurface;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A PHP example of the documentation imports only the classes the backward
 * compatibility promise covers: a class that says @internal may change in a
 * minor release, and an example that teaches it breaks the code copied from
 * it. PublicSurface lists the public classes, and PublicSurfaceTest holds
 * every other class to its @internal tag, so a class is internal here when
 * that list leaves it out.
 *
 * The pages written for contributors to the library itself show its
 * internal classes on purpose, and are not read.
 */
final class ExampleImportsTest extends TestCase
{
    private const CONTRIBUTOR_PAGES = ['docs/adding-a-lint-rule.md'];

    #[Test]
    #[DataProvider('provideDocumentationPages')]
    public function test_an_example_imports_only_public_classes(string $page): void
    {
        $found = [];
        foreach (DocumentationPages::phpFences($page) as $fence) {
            foreach (self::imports($fence['code']) as $class) {
                $problem = self::problemOf($class);
                if (null !== $problem) {
                    $found[] = $page.':'.$fence['line'].': '.$class.' '.$problem;
                }
            }
        }

        $this->assertSame([], $found, $page.' imports classes outside the public API; show the public class that does the job.');
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideExamples')]
    public function test_the_imports_of_an_example_are_read(string $code, array $expected): void
    {
        $this->assertSame($expected, self::imports($code));
    }

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_class_is_told_public_or_internal(string $class, ?string $expected): void
    {
        $this->assertSame($expected, self::problemOf($class));
    }

    #[Test]
    public function test_every_page_left_out_is_a_documentation_page(): void
    {
        foreach (self::CONTRIBUTOR_PAGES as $page) {
            $this->assertContains($page, DocumentationPages::all());
        }
    }

    /**
     * @return iterable<string, array{page: string}>
     */
    public static function provideDocumentationPages(): iterable
    {
        foreach (DocumentationPages::all() as $page) {
            if (!\in_array($page, self::CONTRIBUTOR_PAGES, true)) {
                yield $page => ['page' => $page];
            }
        }
    }

    /**
     * @return iterable<string, array{code: string, expected: list<string>}>
     */
    public static function provideExamples(): iterable
    {
        yield 'one import' => [
            'code' => "<?php\n\nuse PHPRegex\\Toolkit\\Regex;\n",
            'expected' => [Regex::class],
        ];

        yield 'an import under another name' => [
            'code' => 'use PHPRegex\Optimizer\Rewriter as Optimizer;',
            'expected' => [Rewriter::class],
        ];

        yield 'an import with a leading backslash' => [
            'code' => 'use \PHPRegex\Parser\RegexParser;',
            'expected' => [RegexParser::class],
        ];

        yield 'a group of imports' => [
            'code' => 'use PHPRegex\\Parser\\Node\\{LiteralNode, SequenceNode as Sequence};',
            'expected' => [LiteralNode::class, SequenceNode::class],
        ];

        yield 'the classes of another library' => [
            'code' => "use App\\Regex\\ComplexityVisitor;\nuse Symfony\\Component\\Validator\\Constraint;",
            'expected' => [],
        ];

        yield 'a use statement in a closure' => [
            'code' => '$visit = function () use ($regex) { return $regex; };',
            'expected' => [],
        ];
    }

    /**
     * @return iterable<string, array{class: string, expected: string|null}>
     */
    public static function provideClasses(): iterable
    {
        yield 'a public class' => ['class' => Regex::class, 'expected' => null];
        yield 'a node, public by its directory' => ['class' => CalloutNode::class, 'expected' => null];
        yield 'an internal class' => ['class' => Rewriter::class, 'expected' => 'is @internal'];
        yield 'an internal class of a bridge' => ['class' => PatternChecker::class, 'expected' => 'is @internal'];
        yield 'a namespace imported as a prefix' => ['class' => 'PHPRegex\Parser\Node', 'expected' => null];
        yield 'a class the library does not have' => ['class' => 'PHPRegex\Parser\NoSuchClass', 'expected' => 'names no class of the library'];
        yield 'a class of the test suite' => ['class' => PcreGates::class, 'expected' => null];
    }

    /**
     * The library classes a code example imports with "use", by their full name.
     *
     * @return list<string>
     */
    private static function imports(string $code): array
    {
        preg_match_all('/^\s*use\s+\\\\?(PHPRegex\\\\[\w\\\\]*?)(?:\\\\\{([^}]*)\}|\s+as\s+\w+)?\s*;/m', $code, $statements, \PREG_SET_ORDER);

        $classes = [];
        foreach ($statements as $statement) {
            if (!isset($statement[2]) || '' === $statement[2]) {
                $classes[] = $statement[1];

                continue;
            }

            foreach (explode(',', $statement[2]) as $member) {
                $name = (string) preg_replace('/\s+as\s+\w+$/', '', trim($member));
                if ('' !== $name) {
                    $classes[] = $statement[1].'\\'.$name;
                }
            }
        }

        return $classes;
    }

    /**
     * Why an example may not import the class, or null when it may: a
     * public class, a namespace imported as a prefix, or a name outside the
     * library's packages.
     */
    private static function problemOf(string $class): ?string
    {
        $parts = explode('\\', $class);
        $package = $parts[1] ?? '';
        if (!isset(PublicSurface::CLASSES[$package])) {
            return null;
        }

        $relative = implode('/', \array_slice($parts, 2));
        $source = DocumentationPages::ROOT.'/src/'.$package.'/'.$relative;
        if (!is_file($source.'.php')) {
            return is_dir($source) ? null : 'names no class of the library';
        }

        $public = PublicSurface::CLASSES[$package];

        return \in_array($relative, $public, true) || \in_array(\dirname($relative).'/*', $public, true) ? null : 'is @internal';
    }
}
