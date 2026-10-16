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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the token strategy's scope tracker proves about a file: the bodies a
 * variable belongs to, the declarations it reads, and every way a variable
 * or a property may be written. Each shape is read as the parser strategy
 * reads it.
 */
final class TokenValueCollectorShapesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const READ = [['pattern' => '/re/', 'at' => "'/re/'", 'location' => 'via $re']];

    private const PROPERTY = [['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re']];

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideWrites(): iterable
    {
        yield 'include_once' => ['statement' => "include_once __DIR__.'/patterns.php';"];
        yield 'require' => ['statement' => "require __DIR__.'/patterns.php';"];
        yield 'a closure taking it by reference' => ['statement' => "\$append = function (string \$suffix) use (&\$re): void {\n        \$re .= \$suffix;\n    };"];
        yield 'a closure taking it by reference after another variable' => ['statement' => "\$append = function () use (\$values, &\$re): void {\n    };"];
        yield 'an arrow function in the true branch of a ternary' => ['statement' => "\$make = \$flag ? fn () => \$flag ? 'a' : 'b' : \$re .= 'i';"];
        yield 'an arrow function with a nullable return type in a ternary' => ['statement' => "\$make = \$flag ? fn (): ?string => null : \$re .= 'i';"];
        yield 'an arrow function called at once' => ['statement' => '$length = (fn (string $p): int => \strlen($p))($re);'];
        yield 'a static variable declared after another' => ['statement' => "static \$count = 0, \$re = '/static/';"];
        yield 'a static variable declared after an array' => ['statement' => "static \$seen = [], \$re = '/static/';"];
        yield 'a static variable declared after another with no space' => ['statement' => "static \$count = 0,\$re = '/static/';"];
        yield 'a list() target' => ['statement' => 'list($re, $rest) = $values;'];
        yield 'a reference taken in array()' => ['statement' => '$refs = array(&$re);'];
        yield 'extract() after a bitwise and' => ['statement' => '$count = 1 & extract($values);'];
        yield 'compact() spelled in mixed case' => ['statement' => "\$vars = Compact('re');"];
        yield 'a call taking it under a name' => ['statement' => 'normalize(subject: $re);'];
        yield 'a call taking it after a nested call' => ['statement' => 'normalize(trim($s), $re);'];
    }

    /**
     * Each statement may change $re: what it held before proves nothing.
     */
    #[Test]
    #[DataProvider('provideWrites')]
    public function test_a_write_of_this_shape_stops_the_variable(string $statement): void
    {
        $this->assertExtractedByBoth([], self::inFunction($statement));
    }

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideOtherScopes(): iterable
    {
        yield 'an arrow function incrementing it' => ['statement' => '$bump = fn () => $re++;'];
        yield 'an arrow function returning by reference' => ['statement' => "\$bump = fn &() => \$re .= 'x';"];
        yield 'an arrow function with the same parameter and a default' => ['statement' => "\$match = fn (\$re = '/default/') => preg_match(\$re, \$s);"];
        yield 'a closure returning by reference' => ['statement' => "\$reset = function &() {\n        \$re = '/inner/';\n\n        return \$re;\n    };"];
        yield 'a closure whose body opens on a destructuring' => ['statement' => "\$reset = function (array \$pair) {\n        [\$first] = \$pair;\n        \$re = '/inner/';\n    };"];
    }

    /**
     * A closure and an arrow function are bodies of their own: what they
     * do to a variable of the same name leaves the one around them alone.
     */
    #[Test]
    #[DataProvider('provideOtherScopes')]
    public function test_a_write_in_another_body_leaves_the_variable_read(string $statement): void
    {
        $this->assertExtractedByBoth(self::READ, self::inFunction($statement));
    }

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideReads(): iterable
    {
        yield 'a bitwise and with a literal' => ['statement' => "\$masked = \$re & '___';"];
        yield 'a foreach over a cast of it' => ['statement' => "foreach ((array) \$re as \$alternative) {\n    }"];
        yield 'a static property of the same name' => ['statement' => "Holder::\$re = '/other/';"];
        yield 'a static property of the same name destructured' => ['statement' => '[Holder::$re] = $values;'];
        yield 'a class named like extract()' => ['statement' => '$extractor = new Extract($values);'];
        yield 'a static declaration before a statement that reads it' => ['statement' => "static \$calls = 0;\n    \$re[0] === '/' || throw new \\LogicException();"];
    }

    /**
     * None of these writes $re, though a token of each would at another
     * place.
     */
    #[Test]
    #[DataProvider('provideReads')]
    public function test_a_statement_that_writes_nothing_leaves_the_variable_read(string $statement): void
    {
        $this->assertExtractedByBoth(self::READ, self::inFunction($statement));
    }

    /**
     * A body leaves the stack when the next token read lies past it; the
     * last token of an arrow function's body is still in it.
     */
    #[Test]
    public function test_an_arrow_function_ends_at_a_close_tag(): void
    {
        $this->assertExtractedByBoth([['pattern' => '/after/', 'at' => "'/after/'", 'location' => 'via $re']], <<<'PHP'
            <?php

            function page(string $s): int
            {
                $re = '/page/';
                $render = fn () => 1 ?>
            <p>html</p>
            <?php
                $re .= 'x';

                return preg_match($re, $s);
            }

            function after(string $s): int
            {
                $re = '/after/';

                return preg_match($re, $s);
            }

            PHP);
    }

    /**
     * Each body gets a number of its own, an arrow function's too: the
     * function after one shares no variable with the function before.
     */
    #[Test]
    public function test_each_body_has_its_own_variables(): void
    {
        $this->assertExtractedByBoth([['pattern' => '/first/', 'at' => "'/first/'", 'location' => 'via $re']], <<<'PHP'
            <?php

            function first(string $s): int
            {
                $re = '/first/';
                $one = fn () => 1;

                return preg_match($re, $s);
            }

            function second(string $s, string $input): int
            {
                $re = $input;

                return preg_match($re, $s);
            }

            PHP);
    }

    /**
     * global and a by-reference "use" at file level change no body: the
     * functions of the file are read as without them.
     */
    #[Test]
    public function test_a_file_level_global_or_reference_changes_no_body(): void
    {
        $this->assertExtractedByBoth(self::READ, <<<'PHP'
            <?php

            global $config;
            $counter = 0;
            $increment = function () use (&$counter): void {
                $counter++;
            };

            function subject(string $s): int
            {
                $re = '/re/';

                return preg_match($re, $s);
            }

            PHP);
    }

    /**
     * @return iterable<string, array{expected: list<array{pattern: string, at: string, location?: string|null}>, code: string}>
     */
    public static function provideAssignedValues(): iterable
    {
        yield 'a parenthesised literal' => ['expected' => [['pattern' => '/paren/', 'at' => "'/paren/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            function subject(string $s): int
            {
                $re = ('/paren/');

                return preg_match($re, $s);
            }

            PHP];
        yield 'a literal before "or"' => ['expected' => [['pattern' => '/or/', 'at' => "'/or/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            function subject(string $s): int
            {
                $re = '/or/' or throw new \LogicException();

                return preg_match($re, $s);
            }

            PHP];
        yield 'a literal before a close tag' => ['expected' => [['pattern' => '/tag/', 'at' => "'/tag/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            function subject(string $s): int
            {
                $re = '/tag/' ?>
            <p>html</p>
            <?php
                return preg_match($re, $s);
            }

            PHP];
        yield 'a heredoc that interpolates' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            function subject(string $s, string $suffix): int
            {
                $re = <<<EOT
                /a$suffix/
                EOT;

                return preg_match($re, $s);
            }

            PHP];
    }

    /**
     * @param list<array{pattern: string, at: string, location?: string|null}> $expected
     */
    #[Test]
    #[DataProvider('provideAssignedValues')]
    public function test_an_assigned_value_is_read_up_to_its_end(array $expected, string $code): void
    {
        $this->assertExtractedByBoth($expected, $code);
    }

    /**
     * @return iterable<string, array{expected: list<array{pattern: string, at: string, location?: string|null}>, code: string}>
     */
    public static function provideConstantExpressions(): iterable
    {
        yield 'a parenthesised operand of a concatenation' => ['expected' => [['pattern' => '/paren/tail/', 'at' => "('/paren/')", 'location' => 'via PAREN']], 'code' => <<<'PHP'
            <?php

            const PAREN = ('/paren/') . 'tail/';

            function subject(string $s): int
            {
                return preg_match(PAREN, $s);
            }

            PHP];
        yield 'an item picked out of an array literal' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            const PICKED = ['/first/', '/second/'][1];

            function subject(string $s): ?string
            {
                return preg_replace(PICKED, '', $s);
            }

            PHP];
        yield 'an item picked out of array()' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            const PICKED = array('/first/', '/second/')[1];

            function subject(string $s): ?string
            {
                return preg_replace(PICKED, '', $s);
            }

            PHP];
        yield 'define() of a concatenated name' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            define('PREFIX' . 'RE', '/defined/');

            function subject(string $s): int
            {
                return preg_match(PREFIX, $s);
            }

            PHP];
        yield 'the second constant of a declaration' => ['expected' => [['pattern' => '/second/', 'at' => "'/second/'", 'location' => 'via self::SECOND']], 'code' => <<<'PHP'
            <?php

            final class Holder
            {
                private const FIRST = '/first/', SECOND = '/second/';

                public function run(string $s): int
                {
                    return preg_match(self::SECOND, $s);
                }
            }

            PHP];
        yield 'SELF:: in upper case' => ['expected' => [['pattern' => '/upper/', 'at' => "'/upper/'", 'location' => 'via SELF::RE']], 'code' => <<<'PHP'
            <?php

            final class Holder
            {
                private const RE = '/upper/';

                public function run(string $s): int
                {
                    return preg_match(SELF::RE, $s);
                }
            }

            PHP];
        yield 'a constant of an interface' => ['expected' => [['pattern' => '/interface/', 'at' => "'/interface/'", 'location' => 'via Patterns::RE']], 'code' => <<<'PHP'
            <?php

            interface Patterns
            {
                public const RE = '/interface/';
            }

            function subject(string $s): int
            {
                return preg_match(Patterns::RE, $s) + preg_match(RE, $s);
            }

            PHP];
        yield 'a class constant passed to an anonymous class' => ['expected' => [['pattern' => '/config/', 'at' => "'/config/'", 'location' => 'via Config::RE']], 'code' => <<<'PHP'
            <?php

            final class Config
            {
                public const RE = '/config/';
            }

            function subject(string $s): int
            {
                $handler = new class(Config::RE) {
                    public function __construct(private string $re)
                    {
                    }
                };

                return preg_match(Config::RE, $s);
            }

            PHP];
        yield 'a constant of a trait' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            trait Shared
            {
                public const RE = '/trait/';
            }

            function subject(string $s): int
            {
                return preg_match(RE, $s);
            }

            PHP];
    }

    /**
     * @param list<array{pattern: string, at: string, location?: string|null}> $expected
     */
    #[Test]
    #[DataProvider('provideConstantExpressions')]
    public function test_a_constant_is_read_as_php_declares_it(array $expected, string $code): void
    {
        $this->assertExtractedByBoth($expected, $code);
    }

    /**
     * @return iterable<string, array{read: bool, members: string, statement: string}>
     */
    public static function provideClassBodies(): iterable
    {
        yield 'a property declared after a method' => ['read' => true, 'members' => '', 'statement' => ''];
        yield 'a property declared after an attributed constant' => ['read' => true, 'members' => "#[Deprecated]\n    private const UNUSED = 1;", 'statement' => ''];
        yield 'a property declared after a property of a DNF type' => ['read' => true, 'members' => 'private (\Countable&\Traversable)|null $items = null;', 'statement' => ''];
        yield 'an untyped static property' => ['read' => true, 'members' => 'private static $cache;', 'statement' => ''];
        yield 'a foreach over local variables' => ['read' => true, 'members' => '', 'statement' => "foreach (\$items as \$item) {\n        }"];
        yield 'a foreach into another property' => ['read' => true, 'members' => '', 'statement' => "foreach (\$items as \$this->current) {\n        }"];
        yield 'a variable variable' => ['read' => true, 'members' => '', 'statement' => "\${'name'} = 'x';"];
        yield 'a foreach into the property' => ['read' => false, 'members' => '', 'statement' => "foreach (\$items as \$this->re) {\n        }"];
        yield 'a reference to an offset of the property' => ['read' => false, 'members' => '', 'statement' => '$alias = &$this->re[0];'];
        yield 'a reference to a property named by a variable' => ['read' => false, 'members' => '', 'statement' => '$alias = &$this->$name;'];
        yield 'a reference to a property named by an expression' => ['read' => false, 'members' => '', 'statement' => '$alias = &$this->{$name};'];
        yield 'a call taking a property named by an expression' => ['read' => false, 'members' => '', 'statement' => 'normalize($this->{$name});'];
    }

    /**
     * $this->re of a final class holds its default but where the file may
     * write it.
     */
    #[Test]
    #[DataProvider('provideClassBodies')]
    public function test_a_class_body_is_read_member_by_member(bool $read, string $members, string $statement): void
    {
        $this->assertExtractedByBoth($read ? self::PROPERTY : [], <<<PHP
            <?php

            final class Holder
            {
                private array \$cache = [];
                private mixed \$current = null;

                {$members}

                public function run(string \$s, array \$items, string \$name): int
                {
                    {$statement}

                    return preg_match(\$this->re, \$s);
                }

                private string \$re = '/property/';
            }

            PHP);
    }

    /**
     * The body of a method is no member list: a local variable named like
     * a property declares nothing.
     */
    #[Test]
    public function test_a_local_variable_named_like_a_property_leaves_it_read(): void
    {
        $this->assertExtractedByBoth(self::PROPERTY, <<<'PHP'
            <?php

            final class Holder
            {
                private string $re = '/property/';

                public function run(string $s): int
                {
                    $re = $s;

                    return preg_match($this->re, $re);
                }
            }

            PHP);
    }

    /**
     * @return iterable<string, array{expected: list<array{pattern: string, at: string, location?: string|null}>, code: string}>
     */
    public static function provideDeclarationsNamedLikeCalls(): iterable
    {
        yield 'a method named extract' => ['expected' => [['pattern' => '/extract/', 'at' => "'/extract/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            final class Extractor
            {
                public function extract(string $s): int
                {
                    $re = '/extract/';

                    return preg_match($re, $s);
                }
            }

            PHP];
        yield 'a by-reference method named compact' => ['expected' => [['pattern' => '/compact/', 'at' => "'/compact/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            final class Builder
            {
                private array $cache = [];

                public function &compact(string $s): array
                {
                    $re = '/compact/';
                    preg_match($re, $s);

                    return $this->cache;
                }
            }

            PHP];
        yield 'a method named class' => ['expected' => [['pattern' => '/class/', 'at' => "'/class/'", 'location' => 'via $re']], 'code' => <<<'PHP'
            <?php

            final class Builder
            {
                public function class(string $s): int
                {
                    $re = '/class/';

                    return preg_match($re, $s);
                }
            }

            PHP];
    }

    /**
     * A method named extract or compact is no call to them, a method named
     * class no class: its body keeps its variables.
     *
     * @param list<array{pattern: string, at: string, location?: string|null}> $expected
     */
    #[Test]
    #[DataProvider('provideDeclarationsNamedLikeCalls')]
    public function test_a_declaration_named_like_a_keyword_or_a_call_is_no_call(array $expected, string $code): void
    {
        $this->assertExtractedByBoth($expected, $code);
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideUnprovenProperties(): iterable
    {
        yield 'a private property of an abstract class' => ['code' => <<<'PHP'
            <?php

            abstract class Holder
            {
                private string $re = '/abstract/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }

            PHP];
        yield 'a property array spread into a call' => ['code' => <<<'PHP'
            <?php

            final class Holder
            {
                private array $patterns = ['/spread/'];

                public function run(string $s): ?string
                {
                    normalize(...$this->patterns);

                    return preg_replace($this->patterns, '', $s);
                }
            }

            PHP];
        yield 'the property of an anonymous class with no space before its body' => ['code' => <<<'PHP'
            <?php

            final class Holder
            {
                private string $re = '/outer/';

                public function run(): object
                {
                    return new class{
                        private string $subject = '';

                        public function test(): int
                        {
                            return preg_match($this->re, $this->subject);
                        }
                    };
                }
            }

            PHP];
        yield 'the property of an attributed anonymous class with no space after the attribute' => ['code' => <<<'PHP'
            <?php

            final class Holder
            {
                private string $re = '/outer/';

                public function run(): object
                {
                    return new #[Sealed]class {
                        private string $subject = '';

                        public function test(): int
                        {
                            return preg_match($this->re, $this->subject);
                        }
                    };
                }
            }

            PHP];
        yield 'the property of an anonymous class built with arguments' => ['code' => <<<'PHP'
            <?php

            final class Holder
            {
                private string $re = '/outer/';

                public function run(string $s): object
                {
                    return new class($s) {
                        public function __construct(private string $s)
                        {
                        }

                        public function test(): int
                        {
                            return preg_match($this->re, $this->s);
                        }
                    };
                }
            }

            PHP];
    }

    #[Test]
    #[DataProvider('provideUnprovenProperties')]
    public function test_a_property_the_file_does_not_pin_down_is_not_read(string $code): void
    {
        $this->assertExtractedByBoth([], $code);
    }

    /**
     * An untyped property is read in a method; in an arrow function, which
     * Closure::bind() may give another $this, it is not.
     */
    #[Test]
    public function test_an_untyped_property_is_read_in_a_method_only(): void
    {
        $this->assertExtractedByBoth([['pattern' => '/untyped/', 'at' => "'/untyped/'", 'location' => 'via $this->re']], <<<'PHP'
            <?php

            final class Holder
            {
                private $re = '/untyped/';

                public function run(array $items): array
                {
                    return array_filter($items, fn (string $s): bool => 1 === preg_match($this->re, $s));
                }

                public function one(string $s): bool
                {
                    return 1 === preg_match($this->re, $s);
                }
            }

            PHP);
    }

    /**
     * @return iterable<string, array{expected: list<array{pattern: string, at: string, location?: string|null}>, code: string}>
     */
    public static function provideCompactSpellings(): iterable
    {
        yield 'class members with no space between them' => ['expected' => [
            ['pattern' => '/const/', 'at' => "'/const/'", 'location' => 'via self::RE'],
            ['pattern' => '/property/', 'at' => "'/property/'", 'location' => 'via $this->re'],
            ['pattern' => '/after-property/', 'at' => "'/after-property/'", 'location' => 'via self::AFTER_PROPERTY'],
            ['pattern' => '/after-method/', 'at' => "'/after-method/'", 'location' => 'via self::AFTER_METHOD'],
        ], 'code' => <<<'PHP'
            <?php

            final class Holder{const RE='/const/';private string $re='/property/';const AFTER_PROPERTY='/after-property/';public function run(string $s):int{return preg_match(self::RE,$s)+preg_match($this->re,$s)+preg_match(self::AFTER_PROPERTY,$s)+preg_match(self::AFTER_METHOD,$s);}const AFTER_METHOD='/after-method/';}

            PHP];
        yield 'an attribute with no space before the property' => ['expected' => [
            ['pattern' => '/attributed/', 'at' => "'/attributed/'", 'location' => 'via $this->re'],
        ], 'code' => <<<'PHP'
            <?php

            final class Holder
            {
                #[Sensitive]private string $re = '/attributed/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }

            PHP];
        yield 'an array item with no space around "=>"' => ['expected' => [
            ['pattern' => '/value/', 'at' => "'/value/'", 'location' => 'via self::PATTERNS'],
        ], 'code' => <<<'PHP'
            <?php

            final class Holder
            {
                private const PATTERNS = [0=>'/value/'];

                public function run(string $s): ?string
                {
                    return preg_replace(self::PATTERNS, '', $s);
                }
            }

            PHP];
        yield 'a function body opening right after the parameters' => ['expected' => [
            ['pattern' => '/body/', 'at' => "'/body/'", 'location' => 'via $re'],
        ], 'code' => <<<'PHP'
            <?php

            function subject(string $s){
                $re = '/body/';

                return preg_match($re, $s);
            }

            PHP];
        yield 'a function body opening right after a nullable return type' => ['expected' => [
            ['pattern' => '/typed/', 'at' => "'/typed/'", 'location' => 'via $re'],
        ], 'code' => <<<'PHP'
            <?php

            function subject(string $s):?int{
                $re = '/typed/';

                return preg_match($re, $s);
            }

            PHP];
        yield 'a closure with no space before its parameters or its body' => ['expected' => [
            ['pattern' => '/outer/', 'at' => "'/outer/'", 'location' => 'via $re'],
        ], 'code' => <<<'PHP'
            <?php

            function subject(string $s, string $suffix): int
            {
                $re = '/outer/';
                $first = function() {
                    $re = '/first/';
                };
                $second = function () use ($suffix){
                    $re = '/second/';
                };

                return preg_match($re, $s);
            }

            PHP];
        yield 'a by-reference use with no space' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            function subject(string $s): int
            {
                $re = '/outer/';
                $append = function () use(&$re): void {
                };

                return preg_match($re, $s);
            }

            PHP];
        yield 'an arrow function with no space around "=>"' => ['expected' => [
            ['pattern' => '/outer/', 'at' => "'/outer/'", 'location' => 'via $re'],
        ], 'code' => <<<'PHP'
            <?php

            function subject(string $s): int
            {
                $re = '/outer/';
                $bump = fn()=>$re++;

                return preg_match($re, $s);
            }

            PHP];
        yield 'a first parameter with a default and no space' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            function subject($re = '/default/', string $s = ''): int
            {
                return preg_match($re, $s);
            }

            PHP];
        yield 'catch, foreach and arguments with no space' => ['expected' => [], 'code' => <<<'PHP'
            <?php

            function caught(string $s): int
            {
                $re = '/caught/';
                try {
                } catch(\Throwable $re) {
                }

                return preg_match($re, $s);
            }

            function iterated(string $s, array $values): int
            {
                $re = '/iterated/';
                foreach($values as $re) {
                }

                return preg_match($re, $s);
            }

            function passed(string $s): int
            {
                $re = '/passed/';
                normalize($s,$re);

                return preg_match($re, $s);
            }

            function named(string $s): int
            {
                $re = '/named/';
                normalize(subject:$re);

                return preg_match($re, $s);
            }

            PHP];
    }

    /**
     * Where PHP needs no whitespace, the tokens are read as when it is
     * there.
     *
     * @param list<array{pattern: string, at: string, location?: string|null}> $expected
     */
    #[Test]
    #[DataProvider('provideCompactSpellings')]
    public function test_a_shape_with_no_optional_whitespace_is_read_as_with_it(array $expected, string $code): void
    {
        $this->assertExtractedByBoth($expected, $code);
    }

    /**
     * A property hook ends at its brace: the members after it are read.
     */
    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function test_a_property_after_a_hooked_one_is_read(): void
    {
        $this->assertExtractedByBoth([['pattern' => '/after-hook/', 'at' => "'/after-hook/'", 'location' => 'via $this->re']], <<<'PHP'
            <?php

            final class Holder
            {
                public string $hooked {
                    get => 'x';
                }
                private string $re = '/after-hook/';

                public function run(string $s): int
                {
                    return preg_match($this->re, $s);
                }
            }

            PHP);
    }

    /**
     * new Foo()->re (PHP 8.4) writes the property re of an object: it is
     * written wherever it is read.
     */
    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function test_a_property_passed_through_new_without_parentheses_is_not_read(): void
    {
        $this->assertExtractedByBoth([], <<<'PHP'
            <?php

            final class Holder
            {
                private string $re = '/property/';

                public function run(string $s): int
                {
                    normalize(new Holder()->re);

                    return preg_match($this->re, $s);
                }
            }

            PHP);
    }

    private static function inFunction(string $statement): string
    {
        return <<<PHP
            <?php

            function subject(string \$s, array \$values, bool \$flag): int
            {
                \$re = '/re/';
                {$statement}

                return preg_match(\$re, \$s);
            }

            PHP;
    }
}
