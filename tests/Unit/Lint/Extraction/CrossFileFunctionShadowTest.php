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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An unqualified call reaches the function its namespace declares before
 * the one of PHP, whichever file of the project declares it. A reading
 * that stands on the function being PHP's own, array_map() calling a
 * closure and letting it go, preg_quote() quoting a text, sprintf() and
 * implode() building a string, does not hold for a call of a namespace
 * where another file declares a function of that name, once the
 * declarations of the project are read. A fully qualified call reaches
 * PHP's, and a call of another namespace is not concerned.
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
}
