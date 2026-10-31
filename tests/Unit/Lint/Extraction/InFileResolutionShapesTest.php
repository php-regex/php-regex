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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shapes a value the file proves may take: how it is written (a
 * heredoc, a parenthesis, a binary string, an array), where it is declared
 * (an anonymous class, a trait, among other members, behind an import),
 * and what proves nothing.
 *
 * Every case runs through both strategies: the tokenizer and
 * nikic/php-parser must read the same patterns at the same offsets.
 */
final class InFileResolutionShapesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_parenthesised_name_is_read_at_its_declaration(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/parenthesised/', 'at' => "'/parenthesised/'", 'location' => 'via self::RE'],
        ], <<<'CODE'
            <?php

            final class Matcher
            {
                private const RE = '/parenthesised/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(((self::RE)), $s);
                }
            }
            CODE);
    }

    /**
     * A heredoc without interpolation, and a nowdoc, are literals: the
     * pattern is reported where the heredoc opens. An empty one is no
     * pattern; one that interpolates proves nothing.
     */
    #[Test]
    public function test_a_heredoc_or_a_nowdoc_is_read_where_it_opens(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/nowdoc/', 'at' => "<<<'NOWDOC'", 'location' => 'via self::NOWDOC'],
            ['pattern' => '/heredoc/', 'at' => '<<<HEREDOC', 'location' => 'via self::HEREDOC'],
            ['pattern' => '/heredoc-then-flags/i', 'at' => "<<<'CONCATENATED'", 'location' => 'via self::CONCATENATED'],
            ['pattern' => '/local-nowdoc/', 'at' => "<<<'LOCAL'", 'location' => 'via $local'],
        ], <<<'CODE'
            <?php

            final class Matcher
            {
                private const NOWDOC = <<<'NOWDOC'
                    /nowdoc/
                    NOWDOC;
                private const HEREDOC = <<<HEREDOC
                    /heredoc/
                    HEREDOC;
                private const CONCATENATED = <<<'CONCATENATED'
                    /heredoc-then-flags/
                    CONCATENATED . 'i';
                private const EMPTY = <<<'EMPTY'
                    EMPTY;

                public function run(string $s, string $word): bool
                {
                    $local = <<<'LOCAL'
                        /local-nowdoc/
                        LOCAL;
                    $interpolated = <<<INTERPOLATED
                        /{$word}/
                        INTERPOLATED;

                    return 1 === preg_match(self::NOWDOC, $s)
                        && 1 === preg_match(self::HEREDOC, $s)
                        && 1 === preg_match(self::CONCATENATED, $s)
                        && 1 === preg_match(self::EMPTY, $s)
                        && 1 === preg_match($local, $s)
                        && 1 === preg_match($interpolated, $s);
                }
            }
            CODE);
    }

    /**
     * b'...' is the same string as '...': the pattern is reported at the
     * prefix, where the literal starts.
     */
    #[Test]
    public function test_a_binary_string_is_read_without_its_prefix(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/binary/', 'at' => "b'/binary/'", 'location' => 'via self::SINGLE'],
            ['pattern' => '/binary-double/', 'at' => 'B"/binary-double/"', 'location' => 'via self::DOUBLE'],
        ], <<<'CODE'
            <?php

            final class Matcher
            {
                private const SINGLE = b'/binary/';
                private const DOUBLE = B"/binary-double/";

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::SINGLE, $s) && 1 === preg_match(self::DOUBLE, $s);
                }
            }
            CODE);
    }

    /**
     * array() is [], a trailing comma adds no item, and a spread item is
     * no literal the file proves.
     */
    #[Test]
    public function test_an_array_constant_is_read_item_by_item(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/long-a/', 'at' => "'/long-a/'", 'location' => 'via self::LONG'],
            ['pattern' => '/long-b/', 'at' => "'/long-b/'", 'location' => 'via self::LONG'],
            ['pattern' => '/trailing-a/', 'at' => "'/trailing-a/'", 'location' => 'via self::TRAILING'],
            ['pattern' => '/trailing-b/', 'at' => "'/trailing-b/'", 'location' => 'via self::TRAILING'],
        ], <<<'CODE'
            <?php

            final class Matcher
            {
                private const LONG = array('/long-a/', '/long-b/');
                private const TRAILING = [
                    '/trailing-a/',
                    '/trailing-b/',
                ];
                private const BASE = ['/base/'];
                private const SPREAD = [...self::BASE, '/spread/'];

                public function run(string $s): ?string
                {
                    preg_replace(self::LONG, '', $s);
                    preg_replace(self::TRAILING, '', $s);

                    return preg_replace(self::SPREAD, '', $s);
                }
            }
            CODE);
    }

    /**
     * None of these is a value of the file: a static property may be
     * written anywhere, another object's property too (alone, in a
     * concatenation, or as $this?->), a magic constant or a boolean in a
     * concatenation is no literal, parent:: and $this:: name a class the
     * file does not pin down.
     */
    #[Test]
    public function test_a_name_the_file_does_not_pin_down_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via self::CONTROL'],
        ], <<<'CODE'
            <?php

            class Base
            {
                public const PARENT = '/parent/';
            }

            final class Matcher extends Base
            {
                private const CONTROL = '/control/';
                private const MAGIC = '/' . __CLASS__ . '/';
                private const BOOLEAN = '/a' . true . '/';
                private const NULL = '/a' . null . '/';
                private static string $static = '/static-property/';
                private string $other = '/other-object/';
                private string $part = 'part';

                public function run(string $s, self $other): bool
                {
                    return 1 === preg_match(self::$static, $s)
                        && 1 === preg_match($other->other, $s)
                        && 1 === preg_match('/' . $other->part . '/', $s)
                        && 1 === preg_match('/' . $this?->part . '/', $s)
                        && 1 === preg_match(self::MAGIC, $s)
                        && 1 === preg_match(self::BOOLEAN, $s)
                        && 1 === preg_match(self::NULL, $s)
                        && 1 === preg_match(parent::PARENT, $s)
                        && 1 === preg_match($this::CONTROL, $s)
                        && 1 === preg_match(self::CONTROL, $s);
                }
            }
            CODE);
    }

    /**
     * $this in a closure of the file scope belongs to no class of the file.
     */
    #[Test]
    public function test_this_out_of_a_class_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via $this->re'],
        ], <<<'CODE'
            <?php

            $unbound = function (string $s): int {
                return preg_match($this->re, $s);
            };

            final class Matcher
            {
                private string $re = '/control/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }
            CODE);
    }

    /**
     * An anonymous class has a body of its own: self:: and $this in it are
     * not the class around it, whose constant and property have the same
     * names and are read nowhere else. Its own members are not read
     * either.
     */
    #[Test]
    public function test_an_anonymous_class_is_not_the_class_around_it(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via self::CONTROL'],
        ], <<<'CODE'
            <?php

            final class Outer
            {
                private const CONTROL = '/control/';
                private const RE = '/outer-constant/';
                private string $re = '/outer-property/';

                public function make(): array
                {
                    return [
                        new class {
                            private const RE = '/inner-constant/';
                            private string $re = '/inner-property/';

                            public function run(string $s): bool
                            {
                                return 1 === preg_match(self::RE, $s) && 1 === preg_match($this->re, $s);
                            }
                        },
                        new #[\AllowDynamicProperties] class {
                            public function run(string $s): bool
                            {
                                return 1 === preg_match(self::RE, $s);
                            }
                        },
                    ];
                }

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::CONTROL, $s);
                }
            }
            CODE);
    }

    #[Test]
    public function test_a_trait_property_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'"],
        ], <<<'CODE'
            <?php

            trait Matches
            {
                private string $re = '/trait-property/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }

            preg_match('/control/', $subject);
            CODE);
    }

    /**
     * The members before a declaration do not hide it: a list of
     * properties, an array default, an abstract method, a trait use, an
     * enum case, a typed constant.
     */
    #[Test]
    public function test_a_member_is_read_whatever_precedes_it(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/second-of-a-list/', 'at' => "'/second-of-a-list/'", 'location' => 'via $this->second'],
            ['pattern' => '/after-an-array/', 'at' => "'/after-an-array/'", 'location' => 'via $this->afterArray'],
            ['pattern' => '/after-a-trait-use/', 'at' => "'/after-a-trait-use/'", 'location' => 'via self::AFTER_USE'],
            ['pattern' => '/after-an-abstract-method/', 'at' => "'/after-an-abstract-method/'", 'location' => 'via static::AFTER_ABSTRACT'],
            ['pattern' => '/after-a-case/', 'at' => "'/after-a-case/'", 'location' => 'via self::AFTER_CASE'],
            ['pattern' => '/typed-constant/', 'at' => "'/typed-constant/'", 'location' => 'via self::TYPED'],
        ], <<<'CODE'
            <?php

            trait Helpers
            {
            }

            final class Members
            {
                use Helpers;

                private const AFTER_USE = '/after-a-trait-use/';
                private const string TYPED = '/typed-constant/';
                private string $first = '/first-of-a-list/', $second = '/second-of-a-list/';
                private array $list = ['/not-a-pattern/'];
                private string $afterArray = '/after-an-array/';

                public function run(string $s): bool
                {
                    return 1 === preg_match($this->second, $s)
                        && 1 === preg_match($this->afterArray, $s)
                        && 1 === preg_match(self::AFTER_USE, $s)
                        && 1 === preg_match(self::TYPED, $s);
                }
            }

            abstract class Base
            {
                abstract protected function check(string $s): bool;

                final protected const AFTER_ABSTRACT = '/after-an-abstract-method/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(static::AFTER_ABSTRACT, $s);
                }
            }

            enum Kind: string
            {
                case Plain = 'plain';

                private const AFTER_CASE = '/after-a-case/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::AFTER_CASE, $s);
                }
            }
            CODE);
    }

    /**
     * private(set) narrows the writing only: the property reads as its
     * reading visibility says, protected in a final class proves, public
     * does not.
     */
    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function test_asymmetric_visibility_reads_as_its_reading_visibility(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/protected-read/', 'at' => "'/protected-read/'", 'location' => 'via $this->re'],
        ], <<<'CODE'
            <?php

            final class Matcher
            {
                protected private(set) string $re = '/protected-read/';
                public private(set) string $public = '/public-read/';

                public function run(string $s): bool
                {
                    return 1 === preg_match($this->re, $s) && 1 === preg_match($this->public, $s);
                }
            }
            CODE);
    }

    /**
     * A constant reached through "use const", an alias, a group use or a
     * name relative to the namespace is the one PHP reads.
     */
    #[Test]
    public function test_an_imported_or_qualified_constant_is_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/imported/', 'at' => "'/imported/'", 'location' => 'via IMPORTED'],
            ['pattern' => '/aliased/', 'at' => "'/aliased/'", 'location' => 'via ALIAS'],
            ['pattern' => '/group-imported/', 'at' => "'/group-imported/'", 'location' => 'via GROUPED'],
            ['pattern' => '/relative/', 'at' => "'/relative/'", 'location' => 'via Sub\RELATIVE'],
        ], <<<'CODE'
            <?php

            namespace Lib {
                const IMPORTED = '/imported/';
                const ORIGINAL = '/aliased/';
                const GROUPED = '/group-imported/';

                function helper(): void
                {
                }
            }

            namespace App\Sub {
                const RELATIVE = '/relative/';
            }

            namespace App {
                use const Lib\IMPORTED;
                use const Lib\ORIGINAL as ALIAS;
                use Lib\{function helper, const GROUPED};

                function run(string $s): int
                {
                    return preg_match(IMPORTED, $s)
                        + preg_match(ALIAS, $s)
                        + preg_match(GROUPED, $s)
                        + preg_match(Sub\RELATIVE, $s);
                }
            }
            CODE);
    }

    /**
     * A local variable holds what its assignments give it: a ternary gives
     * no literal, an assignment in each branch of one gives two, an array
     * in parentheses is still an array, which a local variable never
     * proves.
     */
    #[Test]
    public function test_a_local_variable_assigned_in_a_ternary(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/then/', 'at' => "'/then/'", 'location' => 'via $branches'],
            ['pattern' => '/else/', 'at' => "'/else/'", 'location' => 'via $branches'],
        ], <<<'CODE'
            <?php

            function run(string $s, bool $flag): int
            {
                $ternary = PHP_OS_FAMILY === 'Windows' ? '/windows/' : '/unix/';
                $flag ? $branches = '/then/' : $branches = '/else/';
                $list = (['/parenthesised-array/']);
                preg_replace($list, '', $s);

                return preg_match($ternary, $s) + preg_match($branches, $s);
            }
            CODE);
    }

    /**
     * Strings::replace() takes the keys of a pattern array whose first key
     * is a string, and its values otherwise or when the replacement is a
     * callable (nette/utils Strings::replace(): "is_array($pattern) &&
     * is_string(key($pattern))"). PHP casts the key first: '1', true and
     * -1 are int keys; null is the string ''.
     *
     * @return iterable<string, array{map: string, call: string, expected: list<string>}>
     */
    public static function provideNetteArrays(): iterable
    {
        yield 'a string key' => ['map' => "['/key/' => 'x']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/key/']];
        yield 'a string key with a string replacement' => ['map' => "['/key/' => 'x']", 'call' => "Strings::replace(\$s, self::MAP, 'y')", 'expected' => ['/key/']];
        yield 'a list' => ['map' => "['/first/', '/second/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/first/', '/second/']];
        yield 'an int key' => ['map' => "[1 => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a numeric string key' => ['map' => "['1' => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a negative key' => ['map' => "[-1 => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a true key' => ['map' => "[true => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a TRUE key' => ['map' => "[TRUE => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a fully qualified true key' => ['map' => "[\\true => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a float key' => ['map' => "[1.0 => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => ['/value/']];
        yield 'a null key' => ['map' => "[null => '/value/']", 'call' => 'Strings::replace($s, self::MAP)', 'expected' => []];
        yield 'a string key with a callable' => ['map' => "['/key/' => '/value/']", 'call' => "Strings::replace(\$s, self::MAP, fn (array \$m): string => '')", 'expected' => ['/value/']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideNetteArrays')]
    public function test_a_resolved_array_feeds_nette_replace_as_a_literal_one_would(string $map, string $call, array $expected): void
    {
        $code = "<?php\n\nuse Nette\\Utils\\Strings;\n\nfinal class Cleaner\n{\n    private const MAP = ".$map.";\n\n    public function run(string \$s): string\n    {\n        return ".$call.";\n    }\n}\n";

        $this->assertSame($expected, $this->patternsReadByBoth($code, PatternFunctionRegistry::create(['nette-utils'])));
    }

    /**
     * A backed enum case is read through "->value", the way a call gets its
     * string: from the enum's own methods as self::Case->value, from another
     * class as Kind::Case->value; static:: reads too, an enum being final.
     * Parentheses around the case, (Kind::Case)->value, change nothing: PHP
     * drops them. The case itself, which is an object, its "->name", a
     * nullsafe "?->value", which no case needs, "::class->value", a computed
     * member and a pure enum's case, which has no value, give no pattern,
     * at a call or held in a constant.
     */
    #[Test]
    public function test_a_backed_enum_case_is_read_through_its_value(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/enum-case/', 'at' => "'/enum-case/'", 'location' => 'via self::Own->value'],
            ['pattern' => '/enum-late/', 'at' => "'/enum-late/'", 'location' => 'via static::Late->value'],
            ['pattern' => '/enum-other/', 'at' => "'/enum-other/'", 'location' => 'via Kind::Other->value'],
            ['pattern' => '/enum-wrapped/', 'at' => "'/enum-wrapped/'", 'location' => 'via Kind::Wrapped->value'],
            ['pattern' => '/enum-twice/', 'at' => "'/enum-twice/'", 'location' => 'via Kind::Twice->value'],
        ], <<<'CODE'
            <?php

            namespace App;

            enum Kind: string
            {
                case Own = '/enum-case/';
                case Other = '/enum-other/';
                case Late = '/enum-late/';
                case Bare = '/enum-bare/';
                case Wrapped = '/enum-wrapped/';
                case Twice = '/enum-twice/';
                case Plain = 'plain';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::Own->value, $s)
                        && 1 === preg_match(static::Late->value, $s);
                }
            }

            final class Reader
            {
                private const NAMED = Kind::Bare->name;
                private const WRAPPED_NAME = (Kind::Bare)->name;
                private const NAME_FIRST = Kind::Bare->name->value;
                private const SAFE = Kind::Bare?->value;

                public function run(string $s): bool
                {
                    return 1 === preg_match(Kind::Other->value, $s)
                        && 1 === preg_match((Kind::Wrapped)->value, $s)
                        && 1 === preg_match(((Kind::Twice))->value, $s)
                        && 1 === preg_match((Kind::Bare)->name, $s)
                        && 1 === preg_match((Kind::Bare)(Kind::Bare)->value, $s)
                        && 1 === preg_match((Kind::Bare), $s)
                        && 1 === preg_match(Kind::Bare, $s)
                        && 1 === preg_match(Kind::Other->name, $s)
                        && 1 === preg_match(Kind::Other?->value, $s)
                        && 1 === preg_match(Kind::class->value, $s)
                        && 1 === preg_match(Pure::None->value, $s)
                        && 1 === preg_match(Kind::Other->{$s}, $s)
                        && 1 === preg_match(self::NAMED, $s)
                        && 1 === preg_match(self::WRAPPED_NAME, $s)
                        && 1 === preg_match(self::NAME_FIRST, $s)
                        && 1 === preg_match(self::SAFE, $s);
                }
            }

            enum Pure
            {
                case None;
            }
            CODE);
    }

    /**
     * The tokenizer reads a file the parser would refuse as far as its
     * tokens go: "()->value" names nothing, and the literal after it is
     * still read.
     */
    #[Test]
    public function test_the_tokenizer_reads_past_an_empty_parenthesis_before_a_value(): void
    {
        $directory = $this->makeProject(['subject.php' => <<<'CODE'
            <?php

            final class Reader
            {
                private const EMPTY = ()->value;

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::EMPTY, $s)
                        && 1 === preg_match('/kept/', $s);
                }
            }
            CODE]);

        $read = $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), [$directory.'/subject.php']);

        $this->assertSame(['/kept/'], array_column($read, 'pattern'));
    }

    /**
     * The enum is named as any class is: through an import, an alias or
     * its qualified name.
     */
    #[Test]
    public function test_an_enum_case_value_is_read_through_the_name_the_file_gives_the_enum(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/enum-aliased/', 'at' => "'/enum-aliased/'", 'location' => 'via K::Re->value'],
        ], <<<'CODE'
            <?php

            namespace App\Model;

            enum Kind: string
            {
                case Re = '/enum-aliased/';
            }

            namespace App\Reader;

            use App\Model\Kind as K;

            final class Reader
            {
                public function run(string $s): bool
                {
                    return 1 === preg_match(K::Re->value, $s)
                        && 1 === preg_match(\App\Model\Kind::Re->value, $s);
                }
            }
            CODE);
    }

    /**
     * A constant holding a case's value folds to the case: the pattern is
     * read once, at the case.
     */
    #[Test]
    public function test_a_constant_holding_an_enum_case_value_is_folded_to_the_case(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/enum-folded/', 'at' => "'/enum-folded/'", 'location' => 'via self::FOLDED'],
        ], <<<'CODE'
            <?php

            enum Kind: string
            {
                case Own = '/enum-folded/';
                private const FOLDED = self::Own->value;

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::FOLDED, $s);
                }
            }
            CODE);
    }
}
