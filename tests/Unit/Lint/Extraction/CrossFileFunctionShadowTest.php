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

use PHPRegex\Tests\Support\ExtractsAcrossFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An unqualified call reaches the function its namespace declares before
 * the one of PHP, whichever file of the project declares it. A reading
 * that stands on the function being PHP's own, array_map() calling a
 * closure and letting it go, preg_quote() quoting a text, sprintf() and
 * implode() building a string, define() declaring a constant, does not
 * hold for a call of a namespace where another file declares a function
 * of that name, once the declarations of the project are read. A fully
 * qualified call reaches PHP's, and a call of another namespace is not
 * concerned.
 *
 * Read alone, a file is judged on what it declares and imports itself.
 *
 * PHP 8.4 (php -r), with function preg_quote(), sprintf() and implode()
 * declared in namespace App: there, '/^' . preg_quote('a.b', '/') . '$/'
 * is "/^a.b$/" when the function returns its text, where '/^' .
 * \preg_quote('a.b', '/') . '$/' is "/^a\.b$/"; sprintf('/^%s$/', 'abc')
 * and implode('|', ['/a', 'b/']) are what the functions of App return,
 * \sprintf() and \implode() "/^abc$/" and "/a|b/". A function array_map()
 * of the namespace that binds the closure it is given to another class
 * makes self::RE the constant of that class.
 *
 * Both strategies read the same.
 */
final class CrossFileFunctionShadowTest extends TestCase
{
    use ExtractsAcrossFiles;

    /**
     * No "const" stands in this file: it declares functions and nothing
     * else.
     */
    private const FUNCTIONS = <<<'CODE'
        <?php

        namespace App\Text;

        function array_map(callable $callback, array $items): array
        {
            return [\Closure::bind($callback, null, Other::class)];
        }

        function preg_quote(string $text, ?string $delimiter = null): string
        {
            return $text;
        }

        function sprintf(string $format, string ...$values): string
        {
            return $format;
        }

        function implode(string $separator, array $items): string
        {
            return $separator;
        }
        CODE;

    private const CLOSURES = <<<'CODE'
        <?php

        namespace App\Text;

        final class Closures
        {
            const RE = '/closures/';

            public function run(array $xs): array
            {
                return [
                    array_map(fn ($a) => preg_match(self::RE, $a), $xs),
                    \array_map(fn ($b) => preg_match(self::RE, $b), $xs),
                    array_filter($xs, fn ($c) => preg_match(self::RE, $c)),
                ];
            }
        }
        CODE;

    private const BUILDERS = <<<'CODE'
        <?php

        namespace App\Text;

        function build(string $s, string $given): int
        {
            return preg_match('/^' . preg_quote($given, '/') . '$/', $s)
                + preg_match('#^' . \preg_quote($given, '#') . '$#', $s)
                + preg_match(sprintf('/^%s$/', 'shadowed'), $s)
                + preg_match(\sprintf('/^%s$/', 'qualified'), $s)
                + preg_match(implode('|', ['/shadowed', 'joined/']), $s)
                + preg_match(\implode('|', ['/qualified', 'joined/']), $s);
        }
        CODE;

    private const ELSEWHERE = <<<'CODE'
        <?php

        namespace App\Other;

        final class Elsewhere
        {
            const RE = '/elsewhere/';

            public function run(array $xs, string $s, string $given): int
            {
                return \count(array_map(fn ($d) => preg_match(self::RE, $d), $xs))
                    + preg_match('~^' . preg_quote($given, '~') . '$~', $s)
                    + preg_match(sprintf('/^%s$/', 'elsewhere'), $s);
            }
        }
        CODE;

    /**
     * array_map() of the namespace may bind the closure: self:: in it
     * names no class the file knows. \array_map() and array_filter(),
     * which the namespace does not declare, still read the class.
     */
    #[Test]
    public function test_a_function_another_file_declares_in_the_namespace_may_bind_the_closure(): void
    {
        $files = ['Closures.php' => self::CLOSURES, 'Functions.php' => self::FUNCTIONS];

        $this->assertSame(['/closures/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Closures.php', 'expression' => 'self::RE', 'state' => 'opaque'],
            ['file' => 'Closures.php', 'expression' => 'self::RE', 'state' => 'constant'],
            ['file' => 'Closures.php', 'expression' => 'self::RE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * preg_quote() of the namespace quotes nothing the file knows: what
     * it returns is a part built at run time, not a quoted text.
     * sprintf() and implode() of the namespace are not read either.
     */
    #[Test]
    public function test_a_function_another_file_declares_in_the_namespace_is_not_the_one_of_php(): void
    {
        $files = ['Builders.php' => self::BUILDERS, 'Functions.php' => self::FUNCTIONS];

        // The fully qualified calls alone are read: \sprintf('/^%s$/',
        // 'qualified') is "/^qualified$/", \implode('|', ['/qualified',
        // 'joined/']) "/qualified|joined/".
        $this->assertSame(['/^qualified$/', '/qualified|joined/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Builders.php', 'expression' => "'/^' . preg_quote(\$given, '/') . '$/'", 'state' => 'raw_shell'],
            ['file' => 'Builders.php', 'expression' => "'#^' . \\preg_quote(\$given, '#') . '$#'", 'state' => 'quoted_shell'],
            ['file' => 'Builders.php', 'expression' => "sprintf('/^%s$/', 'shadowed')", 'state' => 'opaque'],
            ['file' => 'Builders.php', 'expression' => "\\sprintf('/^%s$/', 'qualified')", 'state' => 'constant'],
            ['file' => 'Builders.php', 'expression' => "implode('|', ['/shadowed', 'joined/'])", 'state' => 'opaque'],
            ['file' => 'Builders.php', 'expression' => "\\implode('|', ['/qualified', 'joined/'])", 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A comment may stand anywhere in the declaration: between "function"
     * and the name, between the name and its parenthesis, after the "&"
     * of a function that returns a reference. The function is declared
     * all the same.
     *
     * PHP 8.4 (php, a file of namespace App\Text holding each line below
     * and its body "{ return 'mine'; }"): sprintf('/^%s$/', 'x') is
     * "mine" after every one of them.
     *
     * @return iterable<string, array{declaration: string}>
     */
    public static function provideDeclarationsHoldingAComment(): iterable
    {
        yield 'a block comment before the name' => ['declaration' => 'function /* why */ sprintf(string $format, string ...$values): string'];
        yield 'a block comment after the name' => ['declaration' => 'function sprintf /* why */ (string $format, string ...$values): string'];
        yield 'a line comment before the name' => ['declaration' => "function // why\nsprintf(string \$format, string ...\$values): string"];
        yield 'a hash comment before the name' => ['declaration' => "function # why\nsprintf(string \$format, string ...\$values): string"];
        yield 'a line comment after the name' => ['declaration' => "function sprintf // why (\n(string \$format, string ...\$values): string"];
        yield 'a doc comment before the name' => ['declaration' => "function\n/** why */\nsprintf(string \$format, string ...\$values): string"];
        yield 'a comment after the ampersand' => ['declaration' => 'function &/* why */sprintf(string $format, string ...$values): string'];
        yield 'a comment before the ampersand' => ['declaration' => 'function/* why */&sprintf(string $format, string ...$values): string'];
        yield 'a comment holding a parenthesis' => ['declaration' => 'function /* sprintf( */ sprintf /* ) */ (string $format, string ...$values): string'];
        yield 'two comments in a row' => ['declaration' => "function /* why */ // and why\n sprintf(string \$format, string ...\$values): string"];
    }

    #[Test]
    #[DataProvider('provideDeclarationsHoldingAComment')]
    public function test_a_declaration_holding_a_comment_declares_the_function(string $declaration): void
    {
        $files = [
            'Builders.php' => "<?php\n\nnamespace App\\Text;\n\nfunction build(string \$s): int\n{\n    return preg_match(sprintf('/^%s$/', 'shadowed'), \$s)\n        + preg_match(\\sprintf('/^%s$/', 'qualified'), \$s);\n}\n",
            'Functions.php' => "<?php\n\nnamespace App\\Text;\n\n".$declaration."\n{\n    return 'mine';\n}\n",
        ];

        $this->assertSame(['/^qualified$/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Builders.php', 'expression' => "sprintf('/^%s$/', 'shadowed')", 'state' => 'opaque'],
            ['file' => 'Builders.php', 'expression' => "\\sprintf('/^%s$/', 'qualified')", 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A call of another namespace does not reach these functions: it
     * falls back to PHP's.
     */
    #[Test]
    public function test_a_call_of_another_namespace_reaches_the_function_of_php(): void
    {
        $files = ['Elsewhere.php' => self::ELSEWHERE, 'Functions.php' => self::FUNCTIONS];

        // sprintf('/^%s$/', 'elsewhere') is "/^elsewhere$/".
        $this->assertSame(['/^elsewhere$/', '/elsewhere/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Elsewhere.php', 'expression' => 'self::RE', 'state' => 'constant'],
            ['file' => 'Elsewhere.php', 'expression' => "'~^' . preg_quote(\$given, '~') . '$~'", 'state' => 'quoted_shell'],
            ['file' => 'Elsewhere.php', 'expression' => "sprintf('/^%s$/', 'elsewhere')", 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * Read alone, without the declarations of the project, each file is
     * judged on its own: it declares and imports none of these functions,
     * and every unqualified call is taken for PHP's.
     */
    #[Test]
    public function test_a_file_read_alone_is_judged_on_its_own_declarations(): void
    {
        $this->assertSame(['/closures/'], $this->patternsReadByBoth(self::CLOSURES));
        $this->assertSame(['constant', 'constant', 'constant'], $this->statesReadByBoth(self::CLOSURES));

        // sprintf('/^%s$/', 'shadowed') is "/^shadowed$/" and
        // implode('|', ['/shadowed', 'joined/']) "/shadowed|joined/" with
        // the functions of PHP.
        $patterns = $this->patternsReadByBoth(self::BUILDERS);
        sort($patterns, \SORT_STRING);
        $this->assertSame(['/^qualified$/', '/^shadowed$/', '/qualified|joined/', '/shadowed|joined/'], $patterns);
        $this->assertSame(['quoted_shell', 'quoted_shell', 'constant', 'constant', 'constant', 'constant'], $this->statesReadByBoth(self::BUILDERS));
    }

    /**
     * define() declares a constant when it is the function of PHP. A
     * function of that name the namespace of the call declares, in the
     * file or in another one, or one the file imports with "use
     * function", is reached first, and declares nothing the files can
     * know.
     *
     * PHP 8.4 (php -r): namespace Other; function define($n, $v) {}
     * define('SHADOWED', '('); leaves defined('SHADOWED') false, and so
     * does the call written before the declaration, which PHP hoists;
     * function DEFINE() shadows the same. In a namespace Third, "use
     * function Other\define;", "use function Other\{define, helper};"
     * and "use function Other\helper as define;" each leave the constant
     * undefined after define('SHADOWED', '('). With the declaration in
     * one file and the call in another file of the namespace, the
     * constant is not defined once the first is loaded.
     *
     * @return iterable<string, array{files: array<string, string>}>
     */
    public static function provideFunctionsNamedDefine(): iterable
    {
        $declared = "<?php\n\nnamespace Other;\n\nfunction define(string \$name, string \$value): void\n{\n}\n\nfunction helper(string \$name, string \$value): void\n{\n}\n";

        yield 'declared in the namespace of the call, in its file' => ['files' => [
            'Functions.php' => $declared."\ndefine('SHADOWED', '(');\n",
        ]];

        yield 'declared after the call' => ['files' => [
            'Functions.php' => "<?php\n\nnamespace Other;\n\ndefine('SHADOWED', '(');\n\nfunction define(string \$name, string \$value): void\n{\n}\n",
        ]];

        yield 'declared in upper case' => ['files' => [
            'Functions.php' => "<?php\n\nnamespace Other;\n\nfunction DEFINE(string \$name, string \$value): void\n{\n}\n\ndefine('SHADOWED', '(');\n",
        ]];

        yield 'declared in a namespace written with braces' => ['files' => [
            'Functions.php' => "<?php\n\nnamespace Other {\n    function define(string \$name, string \$value): void\n    {\n    }\n}\n\nnamespace Other {\n    define('SHADOWED', '(');\n}\n",
        ]];

        yield 'declared in another file of the namespace' => ['files' => [
            'Functions.php' => $declared,
            'Calls.php' => "<?php\n\nnamespace Other;\n\ndefine('SHADOWED', '(');\n",
        ]];

        yield 'imported with use function' => ['files' => [
            'Functions.php' => $declared,
            'Calls.php' => "<?php\n\nnamespace Third;\n\nuse function Other\\define;\n\ndefine('SHADOWED', '(');\n",
        ]];

        yield 'imported in a group' => ['files' => [
            'Functions.php' => $declared,
            'Calls.php' => "<?php\n\nnamespace Third;\n\nuse function Other\\{define, helper};\n\ndefine('SHADOWED', '(');\n",
        ]];

        yield 'imported under the name define' => ['files' => [
            'Functions.php' => $declared,
            'Calls.php' => "<?php\n\nnamespace Third;\n\nuse function Other\\helper as define;\n\ndefine('SHADOWED', '(');\n",
        ]];
    }

    /**
     * The name is one no file declares as a constant: the pattern around
     * it is a shell around a part built at run time. A define() of a
     * namespace that declares and imports no such function is still
     * PHP's.
     *
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideFunctionsNamedDefine')]
    public function test_a_function_named_define_declares_no_constant(array $files): void
    {
        $files['Real.php'] = "<?php\n\nnamespace App\\Real;\n\ndefine('REAL', '/real/');\n";
        $files['Reader.php'] = "<?php\n\npreg_match('/' . SHADOWED . '/', 'x');\npreg_match(REAL, 'x');\n";

        $this->assertSame(['/real/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => "'/' . SHADOWED . '/'", 'state' => 'raw_shell'],
            ['file' => 'Reader.php', 'expression' => 'REAL', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A fully qualified \define() reaches PHP's, whatever the namespace
     * declares or the file imports; a define() of another namespace of
     * the file is not concerned, and a method named define hides nothing.
     *
     * PHP 8.4: in namespace Third with "use function Other\helper as
     * define;", \define('QUALIFIED', '/q/') defines it; with function
     * DEFINE() declared in namespace Other, define('ELSEWHERE', '/e/') in
     * namespace Seventh defines it; with a class of namespace Other
     * holding a method define(), define('METHOD_ONLY', '/m/') defines it.
     */
    #[Test]
    public function test_a_define_that_reaches_the_one_of_php_declares_its_constant(): void
    {
        $files = [
            'Functions.php' => "<?php\n\nnamespace Other {\n    function define(string \$name, string \$value): void\n    {\n    }\n\n    \\define('QUALIFIED', '/qualified/');\n}\n\n"
                ."namespace Seventh {\n    define('ELSEWHERE', '/elsewhere/');\n}\n",
            'Imports.php' => "<?php\n\nnamespace Third;\n\nuse function Other\\define;\n\n\\define('QUALIFIED_BESIDE_AN_IMPORT', '/qualified-beside-an-import/');\n",
            'Methods.php' => "<?php\n\nnamespace Fourth;\n\nfinal class Registry\n{\n    public function define(string \$name, string \$value): void\n    {\n    }\n}\n\ndefine('METHOD_ONLY', '/method-only/');\n",
            'Reader.php' => "<?php\n\npreg_match(QUALIFIED, 'x');\npreg_match(ELSEWHERE, 'x');\npreg_match(QUALIFIED_BESIDE_AN_IMPORT, 'x');\npreg_match(METHOD_ONLY, 'x');\n",
        ];

        $this->assertSame(['/elsewhere/', '/method-only/', '/qualified-beside-an-import/', '/qualified/'], $this->patternsAcross($files));
        $this->assertSame(['constant', 'constant', 'constant', 'constant'], array_column($this->argumentsAcross($files), 'state'));
    }

    /**
     * Read alone, a file is judged the same on what it declares and
     * imports: its own function named define declares no constant, and
     * the define() of a namespace with none still does.
     *
     * PHP 8.4: namespace Other; function define($n, $v) {}
     * define('IN_FILE', '('); leaves both \IN_FILE and Other\IN_FILE
     * undefined.
     *
     * @return iterable<string, array{code: string, patterns: list<string>, states: list<string>}>
     */
    public static function provideFilesReadAloneThatCallDefine(): iterable
    {
        $reads = "\npreg_match('/' . IN_FILE . '/', 'x');\npreg_match(DECLARED, 'x');\n";

        yield 'a function named define declared in the namespace' => [
            'code' => "<?php\n\nnamespace Other;\n\nconst DECLARED = '/declared/';\n\nfunction define(string \$name, string \$value): void\n{\n}\n\ndefine('IN_FILE', '(');\n".$reads,
            'patterns' => ['/declared/'],
            'states' => ['raw_shell', 'constant'],
        ];

        yield 'a function named define imported with use function' => [
            'code' => "<?php\n\nnamespace Third;\n\nuse function Other\\define;\n\nconst DECLARED = '/declared/';\n\ndefine('IN_FILE', '(');\n".$reads,
            'patterns' => ['/declared/'],
            'states' => ['raw_shell', 'constant'],
        ];

        // '/' . 'x' . '/' is "/x/".
        yield 'the define() of PHP, in a namespace' => [
            'code' => "<?php\n\nnamespace App;\n\nconst DECLARED = '/declared/';\n\ndefine('IN_FILE', 'x');\n".$reads,
            'patterns' => ['/declared/', '/x/'],
            'states' => ['constant', 'constant'],
        ];

        yield 'a fully qualified define(), beside a function named define' => [
            'code' => "<?php\n\nnamespace Other;\n\nconst DECLARED = '/declared/';\n\nfunction define(string \$name, string \$value): void\n{\n}\n\n\\define('IN_FILE', 'x');\n".$reads,
            'patterns' => ['/declared/', '/x/'],
            'states' => ['constant', 'constant'],
        ];
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $states
     */
    #[Test]
    #[DataProvider('provideFilesReadAloneThatCallDefine')]
    public function test_a_file_read_alone_judges_its_own_function_named_define(string $code, array $patterns, array $states): void
    {
        $read = $this->patternsReadByBoth($code);
        sort($read, \SORT_STRING);

        $this->assertSame($patterns, $read);
        $this->assertSame($states, $this->statesReadByBoth($code));
    }
}
