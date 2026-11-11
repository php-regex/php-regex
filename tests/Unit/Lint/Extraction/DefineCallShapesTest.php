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
 * What a call written "define(" declares for the other files of the
 * project, by its shape. A method, a static method or a class named
 * define is no call of the function: it declares nothing, and leaves the
 * one define() of that name the only declaration. A define() whose
 * result is used, or whose warning is silenced, is written for a
 * constant that may be declared already: it declares the constant with a
 * value no file proves. One that stands alone as a statement is read,
 * whatever ends the statement and its list of arguments.
 *
 * PHP 8.4 (php -r): after "$container->define('NAMED', '/m/');
 * $container?->define('NAMED', '/m/'); Container::define('NAMED', '/s/');
 * $d = new Define('NAMED', '/c/');", defined('NAMED') is false.
 * define('\LEADING', '/x/') leaves defined('LEADING') false. With
 * define('S', '/first/') run first, "@define('S', '/second/');" leaves S
 * "/first/" and says nothing; "define('O', '/second/') or
 * print('refused');" warns, prints "refused" and leaves O "/first/".
 * "<?php define('CT', '/x/') ?>" defines CT; "define('LIST_OF', ['/a/',
 * '/b/'],);" defines the array; define('NAMED', value: '/x/') defines
 * NAMED "/x/". In namespace App, "define('TWICE', '/one/');
 * define('TWICE', '/two/');" warns and leaves \TWICE "/one/".
 *
 * Both strategies read the same.
 */
final class DefineCallShapesTest extends TestCase
{
    use ExtractsAcrossFiles;

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideMembersAndClassesNamedDefine(): iterable
    {
        yield 'a method' => ['statement' => "\$container->define('NAMED', '/of-the-method/');"];
        yield 'a method called through "?->"' => ['statement' => "\$container?->define('NAMED', '/of-the-method/');"];
        yield 'a static method' => ['statement' => "Container::define('NAMED', '/of-the-static-method/');"];
        yield 'a class' => ['statement' => "\$definition = new Define('NAMED', '/of-the-class/');"];
        yield 'a class, where a statement starts' => ['statement' => "new Define('NAMED', '/of-the-class/');"];
    }

    /**
     * The statement declares no constant: the define() of another file
     * is the one declaration of the name, and is read.
     */
    #[Test]
    #[DataProvider('provideMembersAndClassesNamedDefine')]
    public function test_a_member_or_a_class_named_define_declares_no_constant(string $statement): void
    {
        $files = [
            'Container.php' => "<?php\n\n".$statement."\n",
            'Constants.php' => "<?php\n\ndefine('NAMED', '/named/');\n",
            'Reader.php' => "<?php\n\npreg_match(NAMED, 'x');\n",
        ];

        $this->assertSame(['/named/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'NAMED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));

        // In the file of the define() itself, the statement hides nothing either.
        $files = [
            'Constants.php' => "<?php\n\n".$statement."\n\ndefine('NAMED', '/named/');\n",
            'Reader.php' => "<?php\n\npreg_match(NAMED, 'x');\n",
        ];

        $this->assertSame(['/named/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'NAMED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A name written with a leading backslash is not the constant a file
     * names LEADING or \LEADING: PHP keeps the backslash in the name.
     */
    #[Test]
    public function test_a_name_given_with_a_leading_backslash_is_no_constant_a_file_names(): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('\\LEADING', '/leading/');\ndefine('\\App\\LEADING', '/app-leading/');\ndefine('PLAIN', '/plain/');\n",
            'Reader.php' => "<?php\n\npreg_match(LEADING, 'x');\npreg_match(\\LEADING, 'x');\npreg_match(\\App\\LEADING, 'x');\npreg_match(PLAIN, 'x');\n",
        ];

        $this->assertSame(['/plain/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'LEADING', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\LEADING', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\App\LEADING', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'PLAIN', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{statement: string}>
     */
    public static function provideDefinesThatDoNotStandAlone(): iterable
    {
        yield 'a silenced define()' => ['statement' => "@define('GUARDED', '/guarded/');"];
        yield 'a define() followed by "or"' => ['statement' => "define('GUARDED', '/guarded/') or exit('Already defined.');"];
        yield 'a define() followed by "||"' => ['statement' => "define('GUARDED', '/guarded/') || exit('Already defined.');"];
        yield 'a define() followed by "?:"' => ['statement' => "define('GUARDED', '/guarded/') ?: exit('Already defined.');"];
        yield 'a define() followed by "and"' => ['statement' => "define('GUARDED', '/guarded/') and \$declared = true;"];
        yield 'a define() whose result is assigned' => ['statement' => "\$declared = define('GUARDED', '/guarded/');"];
    }

    /**
     * The file expects that the constant may be declared before the call
     * runs: nothing is known of its value where it is read.
     */
    #[Test]
    #[DataProvider('provideDefinesThatDoNotStandAlone')]
    public function test_a_define_that_does_not_stand_alone_as_a_statement_is_not_read(string $statement): void
    {
        $files = [
            'Constants.php' => "<?php\n\n".$statement."\n\ndefine('ALONE', '/alone/');\n",
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(ALONE, 'x');\n",
        ];

        $this->assertSame(['/alone/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'ALONE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A closing tag ends the statement as a semicolon does.
     */
    #[Test]
    public function test_a_define_a_closing_tag_ends_is_read(): void
    {
        $files = [
            'Constants.php' => "<?php define('CLOSED', '/closed/') ?>\n<p>Declared.</p>\n",
            'Reader.php' => "<?php\n\npreg_match(CLOSED, 'x');\n",
        ];

        $this->assertSame(['/closed/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'CLOSED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A comma that ends the list passes no third argument, after a value
     * that holds commas or parentheses of its own.
     */
    #[Test]
    public function test_a_trailing_comma_after_a_value_that_holds_commas_ends_the_list(): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('LIST_OF', ['/list-a/', '/list-b/'],);\ndefine('NESTED', ('/' . 'nested' . '/'),);\n",
            'Reader.php' => "<?php\n\npreg_match(LIST_OF[1], 'x');\npreg_match(NESTED, 'x');\n",
        ];

        // '/' . 'nested' . '/' is "/nested/".
        $this->assertSame(['/list-b/', '/nested/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'LIST_OF[1]', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'NESTED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * The value is read whatever its first token is: a name, a bracket,
     * a parenthesis.
     */
    #[Test]
    public function test_a_define_whose_value_starts_with_a_name_or_a_bracket_is_read(): void
    {
        $files = [
            'Constants.php' => "<?php\n\nconst ORIGINAL = '/original/';\ndefine('ALIAS', ORIGINAL);\ndefine('SUFFIXED', ORIGINAL . 'x');\ndefine('LIST_OF', ['/list-a/', '/list-b/']);\ndefine('GROUPED', ('/grouped/'));\n",
            'Reader.php' => "<?php\n\npreg_match(ALIAS, 'x');\npreg_match(SUFFIXED, 'x');\npreg_match(LIST_OF[0], 'x');\npreg_match(GROUPED, 'x');\n",
        ];

        // '/original/' . 'x' is "/original/x".
        $this->assertSame(['/grouped/', '/list-a/', '/original/', '/original/x'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'ALIAS', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'SUFFIXED', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'LIST_OF[0]', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'GROUPED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideValuesPassedByName(): iterable
    {
        yield 'a literal' => ['value' => "value: '/named/'"];
        yield 'a ternary' => ['value' => "value: \\PHP_INT_SIZE === 8 ? '/wide/' : '/narrow/'"];
        yield 'a ternary whose condition is a constant' => ['value' => "value: FLAG ? '/set/' : '/unset/'"];
    }

    /**
     * The arguments of a define() are followed by their position: a
     * value passed by name is not read, in the file that declares it or
     * in another one.
     */
    #[Test]
    #[DataProvider('provideValuesPassedByName')]
    public function test_a_define_whose_value_is_passed_by_name_is_not_read(string $value): void
    {
        $declaration = "<?php\n\ndefine('NAMED', ".$value.");\ndefine('PLACED', '/placed/');\n";
        $files = [
            'Constants.php' => $declaration,
            'Reader.php' => "<?php\n\npreg_match(NAMED, 'x');\npreg_match(PLACED, 'x');\n",
        ];

        $this->assertSame(['/placed/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'NAMED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'PLACED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));

        $alone = $declaration."\npreg_match(NAMED, 'x');\npreg_match(PLACED, 'x');\n";
        $this->assertSame(['/placed/'], $this->patternsReadByBoth($alone));
        $this->assertSame(['opaque', 'constant'], $this->statesReadByBoth($alone));
    }

    /**
     * @return iterable<string, array{declarations: string}>
     */
    public static function provideDefinesOfANamespaceThatMayNotRun(): iterable
    {
        yield 'declared twice in the file' => ['declarations' => "define('GUARDED', '/one/');\ndefine('GUARDED', '/two/');"];
        yield 'guarded by defined()' => ['declarations' => "if (!\\defined('GUARDED')) {\n    define('GUARDED', '/guarded/');\n}"];
        yield 'guarded by defined(), with a short circuit' => ['declarations' => "\\defined('GUARDED') || define('GUARDED', '/guarded/');"];
        yield 'in a function' => ['declarations' => "function boot(): void\n{\n    define('GUARDED', '/guarded/');\n}"];
        yield 'after a return of the file' => ['declarations' => "if (\\defined('GUARDED')) {\n    return;\n}\n\ndefine('GUARDED', '/guarded/');"];
        yield 'with a value returned by a call' => ['declarations' => "define('GUARDED', load_pattern());"];
    }

    /**
     * Written by its bare name in a namespace that declares no function
     * of that name, define() is the one of PHP, and is judged as one of
     * the global namespace is: declared twice, in a branch or after a
     * return of the file, its constant is not read.
     */
    #[Test]
    #[DataProvider('provideDefinesOfANamespaceThatMayNotRun')]
    public function test_a_define_of_a_namespace_that_may_not_run_is_not_read(string $declarations): void
    {
        $files = [
            'Constants.php' => "<?php\n\nnamespace App;\n\ndefine('ALONE', '/alone/');\n\n".$declarations."\n",
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(ALONE, 'x');\n",
        ];

        $this->assertSame(['/alone/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'ALONE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }
}
