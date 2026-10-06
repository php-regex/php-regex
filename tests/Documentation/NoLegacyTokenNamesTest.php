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

use PHPRegex\Tests\Support\DocumentationPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The 1.x token and enum case names (T_GROUP_CAPTURING, T_GREEDY, ...) are
 * gone in 2.0: the cases are GroupType::Capturing, QuantifierType::Greedy,
 * TokenType::GroupOpen and so on. No page of the documentation names one.
 *
 * A `T_*` name the code still spells (the lexer's token-map keys such as
 * T_CALLOUT, PHP's tokenizer constants) is not a 1.x name: a name is flagged
 * only when it occurs nowhere under src/. The 1.x-to-2.0 upgrade map
 * (src/Toolkit/Upgrade/) names the 1.x cases on purpose and is not read.
 *
 * The pages read are those of DocumentationPages. CHANGELOG.md and
 * UPGRADE-2.0.md name the 1.x cases on purpose and are not among them.
 */
final class NoLegacyTokenNamesTest extends TestCase
{
    private const LEGACY_NAME = '/\bT_[A-Z][A-Z_]+\b/';

    /**
     * @var array<string, true>|null
     */
    private static ?array $namesInCode = null;

    #[Test]
    #[DataProvider('provideDocumentationPages')]
    public function test_documentation_page_names_no_legacy_token(string $page): void
    {
        $lines = preg_split('/\R/', (string) file_get_contents(DocumentationPages::ROOT.'/'.$page)) ?: [];

        $found = [];
        foreach ($lines as $number => $line) {
            $names = self::legacyNames($line);
            if ([] !== $names) {
                $found[] = $page.':'.($number + 1).': '.implode(', ', $names);
            }
        }

        $this->assertSame([], $found, $page.' names 1.x token or enum cases; name the 2.0 cases instead.');
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideLines')]
    public function test_legacy_names_are_those_the_code_does_not_spell(string $line, array $expected): void
    {
        $this->assertSame($expected, self::legacyNames($line));
    }

    #[Test]
    public function test_documentation_pages_are_found(): void
    {
        $pages = array_keys(iterator_to_array(self::provideDocumentationPages()));

        $this->assertContains('README.md', $pages);
        $this->assertContains('CONTRIBUTING.md', $pages);
        $this->assertContains('docs/nodes/README.md', $pages);
        $this->assertContains('src/Parser/README.md', $pages);
        $this->assertNotContains('CHANGELOG.md', $pages);
        $this->assertNotContains('UPGRADE-2.0.md', $pages);
    }

    /**
     * @return iterable<string, array{page: string}>
     */
    public static function provideDocumentationPages(): iterable
    {
        foreach (DocumentationPages::all() as $page) {
            yield $page => ['page' => $page];
        }
    }

    /**
     * @return iterable<string, array{line: string, expected: list<string>}>
     */
    public static function provideLines(): iterable
    {
        yield 'a name the code does not have' => [
            'line' => 'Add `T_NOT_A_TOKEN` to the map.',
            'expected' => ['T_NOT_A_TOKEN'],
        ];

        yield 'a 1.x enum case only the upgrade map names' => [
            'line' => 'GroupType::T_GROUP_CAPTURING',
            'expected' => ['T_GROUP_CAPTURING'],
        ];

        yield 'a lexer token-map key' => [
            'line' => "'T_CALLOUT' => '...'",
            'expected' => [],
        ];

        yield 'a name flagged once per line, the real one kept' => [
            'line' => 'T_NOT_A_TOKEN, T_LITERAL, T_NOT_A_TOKEN',
            'expected' => ['T_NOT_A_TOKEN'],
        ];
    }

    /**
     * The `T_*` names of a line that occur nowhere in the code.
     *
     * @return list<string>
     */
    private static function legacyNames(string $line): array
    {
        if (0 === preg_match_all(self::LEGACY_NAME, $line, $matches)) {
            return [];
        }

        $namesInCode = self::namesInCode();

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $name): bool => !isset($namesInCode[$name]),
        )));
    }

    /**
     * @return array<string, true>
     */
    private static function namesInCode(): array
    {
        if (null !== self::$namesInCode) {
            return self::$namesInCode;
        }

        $names = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(DocumentationPages::ROOT.'/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()
                || str_contains(str_replace('\\', '/', $file->getPathname()), '/src/Toolkit/Upgrade/')) {
                continue;
            }
            preg_match_all(self::LEGACY_NAME, (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[0] as $name) {
                $names[$name] = true;
            }
        }

        return self::$namesInCode = $names;
    }
}
