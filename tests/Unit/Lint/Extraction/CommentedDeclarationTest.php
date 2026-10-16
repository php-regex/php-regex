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
use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Linter\Extraction\PatternFunctionAwareInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP reads a function declaration whatever white space or comment stands
 * between "function", "&" and the name: so does the search for the
 * namespaced functions an unqualified call reaches first.
 */
final class CommentedDeclarationTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{declaration: string}>
     */
    public static function provideDeclarations(): iterable
    {
        yield 'a block comment' => ['declaration' => 'function /* the helper */ grep(array $lines) {}'];
        yield 'a line comment' => ['declaration' => "function // the helper\ngrep(array \$lines) {}"];
        yield 'a hash comment' => ['declaration' => "function # the helper\ngrep(array \$lines) {}"];
        yield 'a doc comment before the parenthesis' => ['declaration' => 'function grep /** lines */ (array $lines) {}'];
        yield 'by reference, no space' => ['declaration' => 'function&grep(array $lines) {}'];
        yield 'by reference, comments around' => ['declaration' => 'function /* a */ & /* b */ grep(array $lines) {}'];
    }

    #[Test]
    #[DataProvider('provideDeclarations')]
    public function test_a_declaration_is_found_whatever_stands_before_the_name(string $declaration): void
    {
        $this->assertSame(['App\grep'], PatternAttributeScanner::namespacedFunctionsIn("<?php\nnamespace App;\n".$declaration."\n", ['grep']));
    }

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface&PatternFunctionAwareInterface>}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokenizer' => ['strategy' => TokenBasedExtractionStrategy::class];
        yield 'nikic/php-parser' => ['strategy' => PhpParserExtractionStrategy::class];
    }

    /**
     * grep is a configured pattern function; App declares its own grep,
     * a comment before the name, and the unqualified call in App reaches
     * that one: its argument is no pattern.
     *
     * @param class-string<ExtractorInterface&PatternFunctionAwareInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_commented_namespaced_declaration_takes_the_call_from_a_global_helper(string $strategy): void
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $directory = $this->makeProject(['src/Helpers.php' => "<?php\n\nnamespace App;\n\nfunction /* not the global one */ grep(string \$text) {}\n\ngrep('not a pattern');\n\\grep('/global/');\n"]);

        $patterns = [];
        foreach ((new PatternExtractor(new $strategy(['grep'])))->extract([$directory.'/src'], []) as $occurrence) {
            $patterns[] = $occurrence->pattern;
        }

        $this->assertSame(['/global/'], $patterns);
    }
}
