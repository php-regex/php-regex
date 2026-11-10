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
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every pattern argument the extraction anchors gives one occurrence of
 * its own, holding its state, beside the occurrences of its patterns: one
 * per call argument, one per item of an array literal written at the call.
 * They come out of extractWithArguments() only; extract() gives the
 * patterns and nothing else, as it always did.
 *
 * Every case runs through both strategies.
 */
final class ArgumentCarrierTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_both_built_in_strategies_give_the_arguments(): void
    {
        $this->assertContains(ArgumentAwareInterface::class, class_implements(TokenBasedExtractionStrategy::class) ?: []);
        $this->assertContains(ArgumentAwareInterface::class, class_implements(PhpParserExtractionStrategy::class) ?: []);

        $interface = new \ReflectionClass(ArgumentAwareInterface::class);
        $this->assertTrue($interface->isInterface());
        $this->assertStringContainsString('@internal', (string) $interface->getDocComment());
        $this->assertStringContainsString('@return list<PatternOccurrence>', (string) $interface->getMethod('extractWithArguments')->getDocComment());

        // An extractor of one's own is asked for patterns only.
        $this->assertFalse((new \ReflectionClass(ExtractorInterface::class))->hasMethod('extractWithArguments'));
    }

    /**
     * No argument of the file is a literal: extract() returns nothing at
     * all, as before the arguments were held, and each argument is there
     * when asked for.
     */
    #[Test]
    public function test_extract_returns_no_argument_for_a_file_of_dynamic_arguments(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $re, string $word): void
            {
                preg_match($re, $s);
                preg_match('/^' . $word . '$/', $s);
                preg_match('/^' . preg_quote($word, '/') . '$/i', $s);
                preg_match(build_pattern(), $s);
            }
            PHP;

        $file = $this->makeProject(['dynamic.php' => $code]).'/dynamic.php';
        $this->assertSame([], (new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extract([$file]));
        $this->assertSame([], (new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()))->extract([$file]));

        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$word . '$/'", 'source' => 'preg_match()'],
            ['state' => 'quoted_shell', 'expression' => "'/^' . preg_quote(\$word, '/') . '$/i'", 'source' => 'preg_match()'],
            ['state' => 'opaque', 'expression' => 'build_pattern()', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A literal argument is an argument as any other: its pattern is an
     * occurrence, and the argument a second one, at the same place, whose
     * state is "constant".
     */
    #[Test]
    public function test_a_literal_argument_is_constant_beside_its_pattern(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): void
            {
                preg_match('/literal/', $s);
                preg_split("/double\tquoted/", $s);
                preg_match('/con' . 'cat/i', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/literal/', 'at' => "'/literal/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => "/double\tquoted/", 'at' => '"/double\tquoted/"', 'source' => 'preg_split()', 'location' => null],
            ['pattern' => '/concat/i', 'at' => "'/con' . 'cat/i'", 'source' => 'preg_match()', 'location' => null],
        ], $code);

        // The expression is the source text, not the decoded string.
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/literal/'", 'source' => 'preg_match()'],
            ['state' => 'constant', 'expression' => '"/double\tquoted/"', 'source' => 'preg_split()'],
            ['state' => 'constant', 'expression' => "'/con' . 'cat/i'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Each item of an array literal written at the call is one argument,
     * whatever it is; the array itself is none.
     */
    #[Test]
    public function test_each_item_of_an_array_literal_is_one_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $re, string $word): string
            {
                return (string) preg_replace(['/item-literal/', $re, '/item-' . $word . '/', self_made()], '', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/item-literal/', 'at' => "'/item-literal/'", 'source' => 'preg_replace()'],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/item-literal/'", 'source' => 'preg_replace()'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => "\$re, '/item-'", 'source' => 'preg_replace()'],
            ['state' => 'raw_shell', 'expression' => "'/item-' . \$word . '/'", 'source' => 'preg_replace()'],
            ['state' => 'opaque', 'expression' => 'self_made()', 'source' => 'preg_replace()'],
        ], $code);
    }

    /**
     * preg_replace_callback_array() reads its patterns from the keys: each
     * key is an argument, no value is.
     */
    #[Test]
    public function test_each_key_of_a_callback_array_is_one_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $re, string $word): ?string
            {
                return preg_replace_callback_array([
                    '/key-literal/' => 'strtoupper',
                    $re => static fn (array $m): string => '/not-a-pattern/',
                    '/key-' . $word . '/' => 'strtolower',
                ], $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/key-literal/', 'at' => "'/key-literal/'", 'source' => 'preg_replace_callback_array()'],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/key-literal/'", 'source' => 'preg_replace_callback_array()'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re => static fn', 'source' => 'preg_replace_callback_array()'],
            ['state' => 'raw_shell', 'expression' => "'/key-' . \$word . '/'", 'source' => 'preg_replace_callback_array()'],
        ], $code);
    }

    /**
     * An array with no item holds no argument.
     */
    #[Test]
    public function test_an_empty_array_literal_holds_no_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $word): string
            {
                preg_match('/^' . $word . '/', $s);

                return (string) preg_replace([], '', $s);
            }
            PHP;

        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$word . '/'"],
        ], $code);
    }

    /**
     * A name several calls read gives its pattern once, where its literal
     * is written, and one argument per call: the second read of a constant
     * or of a variable is as known as the first.
     */
    #[Test]
    public function test_a_name_read_by_two_calls_is_one_pattern_and_two_arguments(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/read-twice/';

                public function run(string $s): int
                {
                    $re = '/variable-read-twice/';

                    return preg_match(self::RE, $s)
                        + preg_match_all(self::RE, $s)
                        + preg_match($re, $s)
                        + preg_match_all($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/read-twice/', 'at' => "'/read-twice/'", 'source' => 'preg_match()', 'location' => 'via self::RE'],
            ['pattern' => '/variable-read-twice/', 'at' => "'/variable-read-twice/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => "self::RE, \$s)\n            + preg_match_all(self::RE", 'source' => 'preg_match()'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => "self::RE, \$s)\n            + preg_match(\$re", 'source' => 'preg_match_all()'],
            ['state' => 'constant', 'expression' => '$re', 'at' => "\$re, \$s)\n            + preg_match_all(\$re", 'source' => 'preg_match()'],
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s);', 'source' => 'preg_match_all()'],
        ], $code);
    }

    /**
     * A constant array the call reads through a name gives one argument
     * per item when the file proves it. Where each stands is the
     * strategies' to agree on: only their number and their states are
     * expected here.
     */
    #[Test]
    public function test_an_array_read_through_a_constant_is_one_argument_per_item(): void
    {
        $code = <<<'PHP'
            <?php

            final class Cleaner
            {
                private const LIST = ['/list-a/', '/list-b/', '/list-c/'];

                public function run(string $s): string
                {
                    return (string) preg_replace(self::LIST, '', $s);
                }
            }
            PHP;

        $this->assertSame(['/list-a/', '/list-b/', '/list-c/'], $this->patternsReadByBoth($code));
        $this->assertSame(['constant', 'constant', 'constant'], $this->statesReadByBoth($code));
    }

    /**
     * An array reached through a name the file does not prove is one
     * argument, of which nothing is known.
     */
    #[Test]
    public function test_an_array_read_through_an_unproven_name_is_one_opaque_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, array $patterns): string
            {
                return (string) preg_replace($patterns, '', $s);
            }
            PHP;

        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$patterns', 'at' => "\$patterns, '', \$s)", 'source' => 'preg_replace()'],
        ], $code);
    }

    /**
     * The arguments of a constructor and of an attribute the registry
     * knows are anchored as those of a call are.
     */
    #[Test]
    public function test_the_arguments_of_a_constructor_and_of_an_attribute_are_arguments(): void
    {
        $code = <<<'PHP'
            <?php

            use Symfony\Component\Validator\Constraints as Assert;

            final class Dto
            {
                private const PART = 'abc';

                #[Assert\Regex('/^' . self::PART . '$/')]
                public string $name = '';

                #[Assert\Regex(pattern: self::UNDECLARED)]
                public string $other = '';

                public function run(\Iterator $rows, string $word): \Iterator
                {
                    return new \RegexIterator($rows, '/^' . $word . '/');
                }
            }
            PHP;
        $registry = PatternFunctionRegistry::create(['symfony-validator']);

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/^' . self::PART . '$/'", 'source' => '#[Regex]', 'location' => null],
        ], $code, $registry);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . self::PART . '$/'", 'source' => '#[Regex]'],
            ['state' => 'opaque', 'expression' => 'self::UNDECLARED', 'source' => '#[Regex]'],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$word . '/'", 'source' => 'new RegexIterator()'],
        ], $code, $registry);
    }

    /**
     * A file that calls no pattern function holds no argument; a path that
     * is no file gives nothing.
     */
    #[Test]
    public function test_a_file_without_a_pattern_call_holds_no_argument(): void
    {
        $this->assertSame([], $this->argumentsReadByBoth("<?php\n\nfunction run(string \$s, string \$re): string\n{\n    return str_replace(\$re, '', \$s);\n}\n"));

        $missing = $this->makeProject().'/missing.php';
        $tokens = new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native());
        $this->assertContains(ArgumentAwareInterface::class, class_implements($tokens) ?: []);
        $this->assertSame([], $tokens->extractWithArguments([$missing]));
        $this->assertSame([], $tokens->extractWithArguments([]));

        $parser = new PhpParserExtractionStrategy([], PatternFunctionRegistry::native());
        $this->assertContains(ArgumentAwareInterface::class, class_implements($parser) ?: []);
        $this->assertSame([], $parser->extractWithArguments([$missing]));
        $this->assertSame([], $parser->extractWithArguments([]));
    }

    /**
     * A file the parser strategy cannot read is read with the tokenizer,
     * its arguments included: they get the states the tokenizer gives
     * them, after the occurrence that says the parser refused the file.
     */
    #[Test]
    public function test_a_file_the_parser_refuses_still_gets_its_arguments_from_the_tokenizer(): void
    {
        $file = $this->makeProject([
            'broken.php' => "<?php\npreg_match('/whole/', \$s);\npreg_match('/^' . \$word . '$/', \$s);\npreg_match(\$re, \$s);\nfunction (\n",
        ]).'/broken.php';

        $expected = [
            ['constant', "'/whole/'", 2, 12],
            ['raw_shell', "'/^' . \$word . '$/'", 3, 12],
            ['opaque', '$re', 4, 12],
        ];

        $tokens = new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native());
        $this->assertContains(ArgumentAwareInterface::class, class_implements($tokens) ?: []);
        $fromTokens = $tokens->extractWithArguments([$file]);
        $this->assertSame($expected, self::arguments($fromTokens));
        $this->assertSame(['/whole/'], self::patterns($fromTokens));

        // Without nikic/php-parser the parser strategy reads nothing.
        if (!class_exists(ParserFactory::class)) {
            return;
        }

        $parser = new PhpParserExtractionStrategy([], PatternFunctionRegistry::native());
        $this->assertContains(ArgumentAwareInterface::class, class_implements($parser) ?: []);
        $fromParser = $parser->extractWithArguments([$file]);

        $this->assertNotSame([], $fromParser);
        $this->assertNotNull($fromParser[0]->parserFallback);
        $this->assertSame($expected, self::arguments($fromParser));
        $this->assertSame(['/whole/'], self::patterns($fromParser));

        // extract() says the fallback and gives the patterns, no argument.
        $extracted = $parser->extract([$file]);
        $this->assertSame([], self::arguments($extracted));
        $this->assertSame(['/whole/'], self::patterns($extracted));
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<array{string, string|null, int, int|null}> the state, the expression, the line and the column of each argument
     */
    private static function arguments(array $occurrences): array
    {
        $arguments = [];
        foreach ($occurrences as $occurrence) {
            if (null !== $occurrence->state) {
                $arguments[] = [$occurrence->state, $occurrence->expression, $occurrence->line, $occurrence->column];
            }
        }

        // The order the strategies emit in is no contract: the position is.
        usort($arguments, static fn (array $a, array $b): int => [$a[2], $a[3]] <=> [$b[2], $b[3]]);

        return $arguments;
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        $patterns = [];
        foreach ($occurrences as $occurrence) {
            if (null === $occurrence->state && null === $occurrence->parserFallback && null === $occurrence->unread) {
                $patterns[] = $occurrence->pattern;
            }
        }

        return $patterns;
    }
}
