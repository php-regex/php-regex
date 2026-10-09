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

namespace PHPRegex\Tests\Unit\Lsp\Document;

use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call to a function or static method that declares a parameter with
 * #[RegexPattern] (or PhpStorm's #[Language('RegExp')]) is read at that
 * argument, its name resolved as PHP resolves it, as `regex lint` does.
 */
final class RegexFinderDeclaredCallsTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace App\Support;

        use PHPRegex\Parser\Attribute\RegexPattern;

        final class Str
        {
            public static function matches(string $subject, #[RegexPattern] string $regex): bool
            {
                return true;
            }

            public static function match(#[RegexPattern] string $regex): bool
            {
                return true;
            }

            public function onInstance(#[RegexPattern] string $regex): void
            {
            }
        }

        function grep(#[RegexPattern] string $regex, array $lines): array
        {
            return [];
        }
        CODE;

    #[Test]
    public function test_a_call_is_read_at_the_marked_argument(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App;

            use App\Support\Str;

            Str::matches('/subject/', '/(a/');
            CODE;

        $occurrences = $this->find($content);

        $this->assertSame(['/(a/'], self::patterns($occurrences));
        $this->assertSame(['line' => 6, 'character' => 26], $occurrences[0]->start);
        $this->assertSame(['line' => 6, 'character' => 32], $occurrences[0]->end);
        $this->assertSame(\strlen("<?php\n\nnamespace App;\n\nuse App\\Support\\Str;\n\nStr::matches('/subject/', "), $occurrences[0]->byteOffset);
    }

    #[Test]
    public function test_names_are_resolved_through_the_namespace_and_the_imports(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Support;

            Str::matches($s, '/same-namespace-class/');
            grep('/same-namespace-function/', []);
            namespace\grep('/relative/', []);
            \App\Support\grep('/fully-qualified/', []);
            Other\Str::matches($s, '/other-namespace/');
            CODE;

        $this->assertSame(['/same-namespace-class/', '/same-namespace-function/', '/relative/', '/fully-qualified/'], self::patterns($this->find($content)));
    }

    #[Test]
    public function test_the_use_forms_php_writes_are_read(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Http;

            use App\Support\Str as Text;
            use App\{Support\Str};
            use function App\Support\grep;
            use function App\Support\{grep as search};
            use const PHP_EOL;

            Text::matches($s, '/aliased/');
            Str::matches($s, '/grouped/');
            grep('/function/', []);
            search('/function-aliased/', []);
            CODE;

        $this->assertSame(['/aliased/', '/grouped/', '/function/', '/function-aliased/'], self::patterns($this->find($content)));
    }

    #[Test]
    public function test_each_braced_namespace_has_its_own_imports(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App {
                use App\Support\Str;

                Str::matches($s, '/imported/');
            }

            namespace Other {
                Str::matches($s, '/not-imported/');
            }

            namespace {
                App\Support\grep('/global-code/', []);
            }
            CODE;

        $this->assertSame(['/imported/', '/global-code/'], self::patterns($this->find($content)));
    }

    /**
     * A trait imported in a class body, or a closure's "use", is not a
     * namespace import.
     */
    #[Test]
    public function test_a_use_inside_a_class_or_a_closure_is_no_import(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Support;

            final class Mailer
            {
                use Str;

                public function send(): void
                {
                    $check = function () use ($s) {
                        return Str::matches($s, '/in-closure/');
                    };
                }
            }
            CODE;

        $this->assertSame(['/in-closure/'], self::patterns($this->find($content)));
    }

    #[Test]
    public function test_an_instance_call_is_not_read(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Support;

            (new Str())->onInstance('/instance/');
            $str?->matches($s, '/nullsafe/');
            $str->match('/method/');
            CODE;

        $this->assertSame([], self::patterns($this->find($content)));
    }

    /**
     * Only a lone string literal is read: its position is the diagnostic's.
     */
    #[Test]
    public function test_an_argument_that_is_not_one_literal_is_not_read(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Support;

            Str::matches($s, '/con/' . 'cat/');
            Str::matches($s, $pattern);
            Str::matches(regex: '/named/', subject: $s);
            Str::matches($s, "/{$part}/");
            Str::matches($s, 'not a pattern');
            Str::matches(['/a/', '/b/'], '/after-array/');
            Str::matches(f('/x/', ['/y/']), '/after-nested-call/');
            Str::matches($s);
            Str::matches;
            CODE;

        $this->assertSame(['/after-array/', '/after-nested-call/'], self::patterns($this->find($content)));
    }

    #[Test]
    public function test_a_declaration_is_not_read_as_a_call(): void
    {
        $content = <<<'CODE'
            <?php

            namespace App\Support;

            function grep(string $regex = '/default/') {}
            function &grep(string $regex = '/default/') {}
            $x = new Str('/constructor/');
            CODE;

        $this->assertSame([], self::patterns($this->find($content)));
    }

    /**
     * Str::match() is a wrapper method name too: the literal is read once.
     */
    #[Test]
    public function test_a_literal_found_by_both_readings_is_read_once(): void
    {
        $content = "<?php\nnamespace App\\Support;\nStr::match('/once/');\npreg_match('/native/', \$s);\n";

        $this->assertSame(['/once/', '/native/'], self::patterns($this->find($content)));
    }

    /**
     * A file being edited may stop anywhere.
     */
    #[Test]
    public function test_a_truncated_document_is_read_as_far_as_it_goes(): void
    {
        $this->assertSame([], self::patterns($this->find("<?php\nnamespace App;\nuse App\\Support\\Str")));
        // A call still being typed: its pattern is checked already.
        $this->assertSame(['/x/'], self::patterns($this->find("<?php\nnamespace App\\Support;\nStr::matches(\$s, '/x/'")));
        $this->assertSame([], self::patterns($this->find("<?php\nnamespace")));
    }

    #[Test]
    public function test_with_no_declaration_only_the_known_calls_are_read(): void
    {
        $content = "<?php\nnamespace App\\Support;\nStr::matches(\$s, '/declared-nowhere/');\npreg_match('/native/', \$s);\n";

        $this->assertSame(['/native/'], self::patterns((new RegexFinder())->find($content, new PatternDeclarations())));
    }

    /**
     * @return list<RegexOccurrence>
     */
    private function find(string $content): array
    {
        $declarations = new PatternDeclarations();
        $declarations->readDocument('file:///workspace/src/Support/Str.php', self::DECLARATIONS);

        return array_values((new RegexFinder())->find($content, $declarations));
    }

    /**
     * @param array<RegexOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        return array_values(array_map(static fn (RegexOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
    }
}
