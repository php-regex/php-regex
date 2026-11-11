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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\ExtractsAcrossFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call naming a global constant another file declares reads it through
 * the project's constants: a "const" at the level of a namespace, or a
 * define() whose name is a string literal, standing at the top level of
 * its file. The pattern is reported once, at its literal, in the file
 * that writes it, with the call as its source.
 *
 * The constant is declared exactly once across the project files; a
 * declaration of the project hides one of a library. Declared in a
 * function, a method, a branch or a loop, it may not be declared at all
 * when the call runs: it is not read. An unqualified name is the
 * constant of the namespace when one is declared, the global one
 * otherwise, as PHP resolves it. A name the reading file declares itself
 * is read by the rules of the file; a constant PHP defines is never one
 * of the project.
 *
 * PHP 8.4 (php -r): in namespace App\Service, with "use const App\WORD",
 * WORD is the constant of App; an unqualified GLOBAL_ONLY falls back to
 * the global constant; SHADOWED is "/namespaced/" in namespace App, which
 * declares one, and "/global/" in App\Service, which does not. define()
 * called in a namespaced file declares a global constant, and
 * define('App\NAMESPACED_DEFINE', ...) one of the namespace App. The
 * namespace of a constant is compared without case, \APP\WORD is
 * \App\WORD; its name with it, defined('App\word') is false. if (true) {
 * define('BRANCHED', '/from-the-branch/'); } followed by
 * define('BRANCHED', '/from-the-top-level/') leaves
 * "/from-the-branch/"; a second define('TWICE', ...) warns and keeps the
 * first; define('PHP_EOL', '/(/') and const E_ALL = '/(/' warn "Constant
 * ... already defined" and change nothing. A define() in a function
 * declares nothing until the function runs.
 *
 * Both strategies read the same.
 */
final class CrossFileGlobalConstantsTest extends TestCase
{
    use ExtractsAcrossFiles;

    private const CONSTANTS = <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace App;

        const WORD = '/^\w+$/';
        const PART = 'a';
        const FULL = '/' . PART . '/';
        const PATTERN_LIST = ['/list-a/', '/list-b/'];
        const BRANCHES = \PHP_INT_SIZE === 8 ? '/branch-wide/' : '/branch-narrow/';
        PHP_WRAP;

    /**
     * No "const" stands in this file: its constants are all declared by
     * define().
     */
    private const DEFINES = <<<'PHP'
        <?php

        define('APP_DEFINED', '/defined/');
        \define('APP_QUALIFIED_DEFINE', '/qualified-define/');
        define('App\NAMESPACED_DEFINE', '/namespaced-define/');
        define("APP_DOUBLE_QUOTED", '/double-quoted-name/');
        PHP;

    #[Test]
    public function test_a_namespace_const_of_another_file_is_read_at_its_literal(): void
    {
        $reader = <<<'PHP'
            <?php

            namespace App\Service;

            use const App\WORD;

            final class Reader
            {
                public function run(string $s): int
                {
                    return preg_match(WORD, $s)
                        + preg_match(\App\FULL, $s)
                        + preg_match('/^' . \App\PART . '+$/', $s)
                        + preg_match(\App\MISSING, $s)
                        + preg_match(UNDECLARED, $s);
                }
            }
            PHP;
        $files = ['Constants.php' => self::CONSTANTS, 'Reader.php' => $reader];

        // FULL is composed where it is declared, '/' . 'a' . '/' being
        // "/a/"; '/^' . 'a' . '+$/' is composed at the call.
        $this->assertSame([
            ['/^\w+$/', 'Constants.php', strpos(self::CONSTANTS, "'/^\\w+$/'"), 'preg_match()', 'via WORD'],
            ['/a/', 'Constants.php', strpos(self::CONSTANTS, "'/' . PART"), 'preg_match()', 'via \App\FULL'],
            ['/^a+$/', 'Reader.php', strpos($reader, "'/^' . \\App\\PART"), 'preg_match()', null],
        ], self::placed($this->readAcross($files)));

        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'WORD', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => '\App\FULL', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => "'/^' . \\App\\PART . '+$/'", 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => '\App\MISSING', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'UNDECLARED', 'state' => 'opaque'],
        ], $this->argumentsAcross($files));
    }

    #[Test]
    public function test_a_define_of_another_file_is_read_at_its_literal(): void
    {
        $reader = <<<'PHP'
            <?php

            namespace App;

            function run(string $s): int
            {
                return preg_match(APP_DEFINED, $s)
                    + preg_match(\APP_QUALIFIED_DEFINE, $s)
                    + preg_match(NAMESPACED_DEFINE, $s)
                    + preg_match(\APP_DOUBLE_QUOTED, $s);
            }
            PHP;
        $files = ['Defines.php' => self::DEFINES, 'Reader.php' => $reader];

        $this->assertSame([
            ['/defined/', 'Defines.php', strpos(self::DEFINES, "'/defined/'"), 'preg_match()', 'via APP_DEFINED'],
            ['/qualified-define/', 'Defines.php', strpos(self::DEFINES, "'/qualified-define/'"), 'preg_match()', 'via \APP_QUALIFIED_DEFINE'],
            ['/namespaced-define/', 'Defines.php', strpos(self::DEFINES, "'/namespaced-define/'"), 'preg_match()', 'via NAMESPACED_DEFINE'],
            ['/double-quoted-name/', 'Defines.php', strpos(self::DEFINES, "'/double-quoted-name/'"), 'preg_match()', 'via \APP_DOUBLE_QUOTED'],
        ], self::placed($this->readAcross($files)));

        $this->assertSame(['constant', 'constant', 'constant', 'constant'], array_column($this->argumentsAcross($files), 'state'));
    }

    /**
     * An array and a value holding branches are read through a global
     * constant of another file as through a class constant of one.
     */
    #[Test]
    public function test_an_array_and_branches_of_another_file_are_read_through_a_global_constant(): void
    {
        $reader = <<<'PHP'
            <?php

            use const App\{BRANCHES, PATTERN_LIST};

            preg_match(BRANCHES, 'x');
            preg_replace(PATTERN_LIST, '', 'x');
            PHP;
        $files = ['Constants.php' => self::CONSTANTS, 'Reader.php' => $reader];

        $this->assertSame([
            ['/list-a/', 'Constants.php', strpos(self::CONSTANTS, "'/list-a/'"), 'preg_replace()', 'via PATTERN_LIST'],
            ['/list-b/', 'Constants.php', strpos(self::CONSTANTS, "'/list-b/'"), 'preg_replace()', 'via PATTERN_LIST'],
            ['/branch-wide/', 'Constants.php', strpos(self::CONSTANTS, "'/branch-wide/'"), 'preg_match()', 'via BRANCHES'],
            ['/branch-narrow/', 'Constants.php', strpos(self::CONSTANTS, "'/branch-narrow/'"), 'preg_match()', 'via BRANCHES'],
        ], self::placed($this->readAcross($files)));

        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'BRANCHES', 'state' => 'finite_set'],
            ['file' => 'Reader.php', 'expression' => 'PATTERN_LIST', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'PATTERN_LIST', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * An unqualified name is the constant of the namespace of the call
     * when the project declares one, the global one otherwise.
     */
    #[Test]
    public function test_an_unqualified_name_tries_the_namespace_then_the_global_constant(): void
    {
        $namespaced = "<?php\n\nnamespace App;\n\nconst SHADOWED = '/namespaced/';\n";
        $global = "<?php\n\nconst SHADOWED = '/global/';\nconst GLOBAL_ONLY = '/global-only/';\n";
        $inApp = "<?php\n\nnamespace App;\n\npreg_match(SHADOWED, 'x');\npreg_match(GLOBAL_ONLY, 'x');\npreg_match(\\SHADOWED, 'y');\n";
        $inService = "<?php\n\nnamespace App\\Service;\n\npreg_match(SHADOWED, 'x');\npreg_match(namespace\\SHADOWED, 'y');\n";
        $files = ['Namespaced.php' => $namespaced, 'Global.php' => $global, 'InApp.php' => $inApp, 'InService.php' => $inService];

        // "/global/" is read from App through its qualified name, and from
        // App\Service, which declares no SHADOWED, through the bare one:
        // the literals read are compared, not how many times each is.
        $this->assertSame([
            ['/global-only/', 'Global.php'],
            ['/global/', 'Global.php'],
            ['/namespaced/', 'Namespaced.php'],
        ], self::sorted(array_map(static fn (array $row): array => [$row['pattern'], $row['file']], $this->readAcross($files))));

        $this->assertSame([
            ['file' => 'InApp.php', 'expression' => 'SHADOWED', 'state' => 'constant'],
            ['file' => 'InApp.php', 'expression' => 'GLOBAL_ONLY', 'state' => 'constant'],
            ['file' => 'InApp.php', 'expression' => '\SHADOWED', 'state' => 'constant'],
            ['file' => 'InService.php', 'expression' => 'SHADOWED', 'state' => 'constant'],
            // namespace\SHADOWED names the constant of App\Service alone,
            // which no file declares.
            ['file' => 'InService.php', 'expression' => 'namespace\SHADOWED', 'state' => 'opaque'],
        ], $this->argumentsAcross($files));
    }

    /**
     * The namespace of a constant is compared without case; its name is
     * not.
     */
    #[Test]
    public function test_the_name_of_a_constant_is_compared_with_its_case(): void
    {
        $reader = "<?php\n\npreg_match(\\APP\\WORD, 'x');\npreg_match(\\App\\word, 'x');\npreg_match(\\app_defined, 'x');\npreg_match(\\app\\NAMESPACED_DEFINE, 'x');\n";
        $files = ['Constants.php' => self::CONSTANTS, 'Defines.php' => self::DEFINES, 'Reader.php' => $reader];

        $this->assertSame(['/^\w+$/', '/namespaced-define/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => '\APP\WORD', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => '\App\word', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\app_defined', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\app\NAMESPACED_DEFINE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{files: array<string, string>}>
     */
    public static function provideConstantsDeclaredTwice(): iterable
    {
        yield 'a const in two files' => ['files' => [
            'First.php' => "<?php\n\nconst TWICE = '/first/';\n",
            'Second.php' => "<?php\n\nconst TWICE = '/second/';\n",
        ]];

        yield 'a namespaced const in two files' => ['files' => [
            'First.php' => "<?php\n\nnamespace App;\n\nconst TWICE = '/first/';\n",
            'Second.php' => "<?php\n\nnamespace App;\n\nconst TWICE = '/second/';\n",
        ]];

        yield 'a define() in two files' => ['files' => [
            'First.php' => "<?php\n\ndefine('TWICE', '/first/');\n",
            'Second.php' => "<?php\n\ndefine('TWICE', '/second/');\n",
        ]];

        yield 'a const in one file and a define() in another' => ['files' => [
            'First.php' => "<?php\n\nconst TWICE = '/first/';\n",
            'Second.php' => "<?php\n\ndefine('TWICE', '/second/');\n",
        ]];

        yield 'twice in one file' => ['files' => [
            'First.php' => "<?php\n\ndefine('TWICE', '/first/');\ndefine('TWICE', '/second/');\n",
        ]];

        yield 'the same value in two files' => ['files' => [
            'First.php' => "<?php\n\nconst TWICE = '/same/';\n",
            'Second.php' => "<?php\n\nconst TWICE = '/same/';\n",
        ]];

        // if (true) { define('BRANCHED', '/from-the-branch/'); } then the
        // top-level define(): PHP keeps "/from-the-branch/".
        yield 'at the top level of one file and in a branch of another' => ['files' => [
            'First.php' => "<?php\n\ndefine('TWICE', '/top-level/');\n",
            'Second.php' => "<?php\n\nif (\\PHP_VERSION_ID >= 80300) {\n    define('TWICE', '/in-a-branch/');\n}\n",
        ]];

        yield 'at the top level of one file and in a function of another' => ['files' => [
            'First.php' => "<?php\n\ndefine('TWICE', '/top-level/');\n",
            'Second.php' => "<?php\n\nfunction boot(): void\n{\n    define('TWICE', '/in-a-function/');\n}\n",
        ]];
    }

    /**
     * Which declaration runs first is not known: the constant is not
     * read, in the global namespace or through it.
     *
     * @param array<string, string> $files
     */
    #[Test]
    #[DataProvider('provideConstantsDeclaredTwice')]
    public function test_a_constant_declared_twice_is_not_read(array $files): void
    {
        $reader = "<?php\n\nnamespace App;\n\nconst ONCE = '/once/';\n\npreg_match(TWICE, 'x');\npreg_match(ONCE, 'x');\n";
        $files['Reader.php'] = $reader;

        $this->assertSame(['/once/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'TWICE', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'ONCE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A declaration that is not at the top level of its file may not
     * have run when the call does: a define() in a branch, a loop, a
     * function, a method or a closure. The usual guard is one of them.
     *
     * @return iterable<string, array{declaration: string}>
     */
    public static function provideDeclarationsOutOfTheTopLevel(): iterable
    {
        yield 'guarded by defined()' => ['declaration' => "if (!defined('GUARDED')) {\n    define('GUARDED', '/guarded/');\n}"];
        yield 'guarded by defined(), without braces' => ['declaration' => "if (!defined('GUARDED')) define('GUARDED', '/guarded/');"];
        yield 'guarded by defined(), with a short circuit' => ['declaration' => "defined('GUARDED') || define('GUARDED', '/guarded/');"];
        yield 'guarded by defined(), with "or"' => ['declaration' => "defined('GUARDED') or define('GUARDED', '/guarded/');"];
        yield 'in an else branch' => ['declaration' => "if (\\PHP_VERSION_ID < 80000) {\n    exit(1);\n} else {\n    define('GUARDED', '/guarded/');\n}"];
        yield 'in the arm of a ternary' => ['declaration' => "\\PHP_VERSION_ID >= 80000 ? define('GUARDED', '/guarded/') : null;"];
        yield 'in a loop' => ['declaration' => "foreach (['GUARDED'] as \$name) {\n    define('GUARDED', '/guarded/');\n}"];
        yield 'in a while loop' => ['declaration' => "while (!defined('GUARDED')) {\n    define('GUARDED', '/guarded/');\n}"];
        yield 'in a function' => ['declaration' => "function boot(): void\n{\n    define('GUARDED', '/guarded/');\n}"];
        yield 'in a method' => ['declaration' => "final class Boot\n{\n    public static function run(): void\n    {\n        define('GUARDED', '/guarded/');\n    }\n}"];
        yield 'in a closure' => ['declaration' => "\$boot = static function (): void {\n    define('GUARDED', '/guarded/');\n};"];
        yield 'in an arrow function' => ['declaration' => "\$boot = static fn (): bool => define('GUARDED', '/guarded/');"];
        yield 'in a try block' => ['declaration' => "try {\n    define('GUARDED', '/guarded/');\n} catch (\\Throwable \$e) {\n}"];
        yield 'in a switch' => ['declaration' => "switch (\\PHP_OS_FAMILY) {\n    default:\n        define('GUARDED', '/guarded/');\n}"];
        yield 'in a match arm' => ['declaration' => "match (\\PHP_OS_FAMILY) {\n    default => define('GUARDED', '/guarded/'),\n};"];
    }

    #[Test]
    #[DataProvider('provideDeclarationsOutOfTheTopLevel')]
    public function test_a_define_out_of_the_top_level_of_its_file_is_not_read(string $declaration): void
    {
        $files = [
            'Constants.php' => "<?php\n\n".$declaration."\n\ndefine('TOP_LEVEL', '/top-level/');\n",
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(TOP_LEVEL, 'x');\n",
        ];

        $this->assertSame(['/top-level/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'TOP_LEVEL', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A return at the level of the file ends it: what the file declares
     * after one is declared only when the return did not run. Guarded by
     * defined(), the return is the guard "if (!defined('X')) define(...)"
     * written the other way round; under any condition, in a loop or in a
     * switch, it is a branch around the declaration; alone, it leaves the
     * declaration never run, as an exit that always runs does. A "const"
     * of the file level is declared when its statement runs, as define()
     * is.
     *
     * PHP 8.4 (php -r, each file required): "return; const AFTER = 1;
     * define('AFTER_DEFINE', 1);" leaves defined('AFTER') and
     * defined('AFTER_DEFINE') false. With define('GUARDED',
     * '/set-elsewhere/') run first, "if (defined('GUARDED')) { return; }
     * define('GUARDED', '/guarded/'); const BESIDE = '/beside/';" leaves
     * GUARDED "/set-elsewhere/" and BESIDE undefined; run alone, it
     * defines "/guarded/" and "/beside/". "exit; define('GUARDED',
     * '/guarded/');" defines nothing.
     *
     * @return iterable<string, array{constants: string}>
     */
    public static function provideDeclarationsAfterAReturnOfTheFile(): iterable
    {
        $after = static fn (string $statements): string => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$statements."\n";
        $define = "define('GUARDED', '/guarded/');";
        $const = "const GUARDED = '/guarded/';";
        $guard = "if (defined('GUARDED')) {\n    return;\n}\n\n";

        yield 'a define() after a return guarded by defined()' => ['constants' => $after($guard.$define)];
        yield 'a const after a return guarded by defined()' => ['constants' => $after($guard.$const)];
        yield 'a define() after a guarded return without braces' => ['constants' => $after("if (defined('GUARDED')) return;\n\n".$define)];
        yield 'a define() after a guarded return in the alternative syntax' => ['constants' => $after("if (defined('GUARDED')):\n    return;\nendif;\n\n".$define)];
        yield 'a define() after a return under another condition' => ['constants' => $after("if (false !== getenv('APP_SKIP')) {\n    return;\n}\n\n".$define)];
        yield 'a const after a return under another condition' => ['constants' => $after("if (false !== getenv('APP_SKIP')) {\n    return;\n}\n\n".$const)];
        yield 'a define() after a return in an else branch' => ['constants' => $after("if (false === getenv('APP_SKIP')) {\n    \$loaded = true;\n} else {\n    return;\n}\n\n".$define)];
        yield 'a define() after a return in a loop' => ['constants' => $after("foreach (\$argv as \$argument) {\n    if ('--skip' === \$argument) {\n        return;\n    }\n}\n\n".$define)];
        yield 'a define() after a return in a switch' => ['constants' => $after("switch (getenv('APP_SKIP')) {\n    case '1':\n        return;\n}\n\n".$define)];
        yield 'a define() after a return that always runs' => ['constants' => $after("return;\n\n".$define)];
        yield 'a const after a return that always runs' => ['constants' => $after("return;\n\n".$const)];
        yield 'a define() after a return of a value' => ['constants' => $after("return ['loaded' => true];\n\n".$define)];
        yield 'a define() after an exit that always runs' => ['constants' => $after("exit;\n\n".$define)];
        yield 'a define() after a die that always runs' => ['constants' => $after("die('stopped');\n\n".$define)];
        yield 'a define() after a return in the braces of a namespace' => ['constants' => "<?php\n\nnamespace App {\n    \\define('BEFORE', '/before/');\n\n    if (\\defined('GUARDED')) {\n        return;\n    }\n}\n\nnamespace {\n    define('GUARDED', '/guarded/');\n}\n"];
    }

    /**
     * The constant is declared, with a value no file proves: nothing is
     * known of it where it is read. What the file declares before the
     * return is still read.
     */
    #[Test]
    #[DataProvider('provideDeclarationsAfterAReturnOfTheFile')]
    public function test_a_declaration_after_a_return_of_the_file_is_not_read(string $constants): void
    {
        $files = [
            'Constants.php' => $constants,
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(BEFORE, 'x');\n",
        ];

        $this->assertSame(['/before/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A return that does not end the file hides nothing: one in a
     * function, a method or a closure the file declares ends that body
     * alone, and one written after the declaration runs after it.
     *
     * PHP 8.4 (php -r, the file required): "function helper() { return
     * 1; } define('GUARDED', '/guarded/');" and "define('GUARDED',
     * '/guarded/'); return;" both leave GUARDED "/guarded/". So does
     * "defined('BEFORE') || exit; define('GUARDED', '/guarded/');" when
     * BEFORE is defined; when it is not, the script ends there and nothing
     * reads GUARDED.
     *
     * @return iterable<string, array{constants: string}>
     */
    public static function provideReturnsThatDoNotEndTheFileBeforeTheDeclaration(): iterable
    {
        $after = static fn (string $statements): string => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$statements."\n";
        $define = "define('GUARDED', '/guarded/');";
        $const = "const GUARDED = '/guarded/';";

        yield 'a return in a function, before a define()' => ['constants' => $after("function helper(): int\n{\n    return 1;\n}\n\n".$define)];
        yield 'a return in a function, before a const' => ['constants' => $after("function helper(): int\n{\n    return 1;\n}\n\n".$const)];
        yield 'a return in a function declared in a branch' => ['constants' => $after("if (!function_exists('helper')) {\n    function helper(): int\n    {\n        return 1;\n    }\n}\n\n".$define)];
        yield 'a return in a method' => ['constants' => $after("final class Boot\n{\n    public static function run(): int\n    {\n        return 2;\n    }\n}\n\n".$define)];
        yield 'a return in a closure' => ['constants' => $after("\$boot = static function (): int {\n    return 3;\n};\n\n".$define)];
        yield 'an exit in a function' => ['constants' => $after("function stop(): never\n{\n    exit(1);\n}\n\n".$define)];
        yield 'a return after a define()' => ['constants' => $after($define."\n\nreturn;")];
        yield 'a return after a const' => ['constants' => $after($const."\n\nreturn;")];
        yield 'a guarded return after a define()' => ['constants' => $after($define."\n\nif (defined('OTHER')) {\n    return;\n}")];
        yield 'the word return in a comment and in a string' => ['constants' => $after("// return;\n\$message = 'return;';\n\n".$define)];
        // An exit under a condition stops the script or lets the declaration run: no code reads the constant without it.
        yield 'an exit after an or, before a define()' => ['constants' => $after("defined('BEFORE') || exit;\n\n".$define)];
        yield 'an exit after an or, before a const' => ['constants' => $after("defined('BEFORE') || exit;\n\n".$const)];
        yield 'an exit in a branch' => ['constants' => $after("if (!defined('BEFORE')) {\n    exit;\n}\n\n".$define)];
        yield 'a die in a branch without braces' => ['constants' => $after("if (!defined('BEFORE')) die('No direct access.');\n\n".$define)];
    }

    #[Test]
    #[DataProvider('provideReturnsThatDoNotEndTheFileBeforeTheDeclaration')]
    public function test_a_return_that_does_not_end_the_file_before_a_declaration_hides_nothing(string $constants): void
    {
        $files = [
            'Constants.php' => $constants,
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(BEFORE, 'x');\n",
        ];

        $this->assertSame(['/before/', '/guarded/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * The braces of a namespace are the top level of the file: a const
     * and a define() standing in them are read.
     */
    #[Test]
    public function test_the_braces_of_a_namespace_are_the_top_level(): void
    {
        $constants = "<?php\n\nnamespace App {\n    const BRACED = '/braced-const/';\n    define('BRACED_DEFINE', '/braced-define/');\n}\n\nnamespace {\n    const BRACED_GLOBAL = '/braced-global/';\n}\n";
        $reader = "<?php\n\npreg_match(\\App\\BRACED, 'x');\npreg_match(BRACED_DEFINE, 'x');\npreg_match(BRACED_GLOBAL, 'x');\n";
        $files = ['Constants.php' => $constants, 'Reader.php' => $reader];

        $this->assertSame(['/braced-const/', '/braced-define/', '/braced-global/'], $this->patternsAcross($files));
        $this->assertSame(['constant', 'constant', 'constant'], array_column($this->argumentsAcross($files), 'state'));
    }

    /**
     * A define() whose name or value the file does not spell declares
     * nothing another file can read; its name is the string it is given,
     * whatever the text of its value.
     *
     * @return iterable<string, array{declaration: string}>
     */
    public static function provideDefinesTheFileDoesNotSpellOut(): iterable
    {
        yield 'a name in a variable' => ['declaration' => "define(\$name, '/x/');"];
        yield 'a name built of a variable' => ['declaration' => "define('NAMED' . \$suffix, '/x/');"];
        yield 'a name returned by a call' => ['declaration' => "define(strtoupper('named'), '/x/');"];
        yield 'a value returned by a call' => ['declaration' => "define('NAMED', load_pattern());"];
        yield 'a value in a variable' => ['declaration' => "define('NAMED', \$pattern);"];
        yield 'a spread' => ['declaration' => "define('NAMED', ...\$values);"];
        yield 'no value' => ['declaration' => "define('NAMED');"];
        yield 'a method named define' => ['declaration' => "\$container->define('NAMED', '/x/');"];
        yield 'a static method named define' => ['declaration' => "Container::define('NAMED', '/x/');"];
        yield 'a function of another namespace named define' => ['declaration' => "\\Vendor\\define('NAMED', '/x/');"];
        yield 'a name that is the value of another define()' => ['declaration' => "define('OTHER', 'NAMED');"];
    }

    #[Test]
    #[DataProvider('provideDefinesTheFileDoesNotSpellOut')]
    public function test_a_define_the_file_does_not_spell_out_declares_nothing(string $declaration): void
    {
        $files = [
            'Constants.php' => "<?php\n\n".$declaration."\n\ndefine('SPELLED', '/spelled/');\n",
            'Reader.php' => "<?php\n\npreg_match(NAMED, 'x');\npreg_match(SPELLED, 'x');\n",
        ];

        $this->assertSame(['/spelled/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'NAMED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'SPELLED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A constant of a library is read when the project declares none of
     * that name; a declaration of the project hides it; two libraries
     * declaring it leave it unread.
     */
    #[Test]
    public function test_a_declaration_of_the_project_hides_one_of_a_library(): void
    {
        $reader = "<?php\n\npreg_match(LIBRARY_ONLY, 'x');\npreg_match(HIDDEN, 'x');\npreg_match(TWO_LIBRARIES, 'x');\npreg_match(\\Vendor\\NAMESPACED, 'x');\n";
        $project = "<?php\n\nconst HIDDEN = '/of-the-project/';\n";
        $library = "<?php\n\nconst LIBRARY_ONLY = '/of-the-library/';\nconst HIDDEN = '/hidden-by-the-project/';\ndefine('TWO_LIBRARIES', '/first-library/');\n";
        $other = "<?php\n\nnamespace Vendor;\n\nconst NAMESPACED = '/namespaced-in-a-library/';\n\\define('TWO_LIBRARIES', '/second-library/');\n";

        $files = ['Project.php' => $project, 'Reader.php' => $reader];
        $libraries = ['vendor/one/constants.php' => $library, 'vendor/two/constants.php' => $other];

        $this->assertSame([
            ['/namespaced-in-a-library/', 'constants.php'],
            ['/of-the-library/', 'constants.php'],
            ['/of-the-project/', 'Project.php'],
        ], self::sorted(array_map(static fn (array $row): array => [$row['pattern'], $row['file']], $this->readAcross($files, $libraries))));

        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'LIBRARY_ONLY', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'HIDDEN', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'TWO_LIBRARIES', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\Vendor\NAMESPACED', 'state' => 'constant'],
        ], $this->argumentsAcross($files, $libraries));
    }

    /**
     * A name the reading file declares itself is read by the rules of
     * the file, whatever another file declares under it.
     */
    #[Test]
    public function test_the_constant_of_the_file_wins_over_one_of_the_project(): void
    {
        $reader = "<?php\n\nnamespace App;\n\nconst WORD = '/own/';\n\npreg_match(WORD, 'x');\npreg_match(FULL, 'x');\n";
        $files = ['Constants.php' => self::CONSTANTS, 'Reader.php' => $reader];

        $this->assertSame([
            ['/a/', 'Constants.php', strpos(self::CONSTANTS, "'/' . PART"), 'preg_match()', 'via FULL'],
            ['/own/', 'Reader.php', strpos($reader, "'/own/'"), 'preg_match()', 'via WORD'],
        ], self::placed($this->readAcross($files)));
    }

    /**
     * A constant PHP defines keeps its own value whatever a file of the
     * project declares under its name: none is read as that declaration.
     */
    #[Test]
    public function test_a_constant_php_defines_is_never_one_of_the_project(): void
    {
        $constants = "<?php\n\n@define('E_ALL', '/(/');\nconst PHP_VERSION = '/(/';\nconst M_PI = '/(/';\nconst DECLARED = '/declared/';\n";
        $reader = "<?php\n\nnamespace App;\n\npreg_match(E_ALL, 'x');\npreg_match(\\PHP_VERSION, 'x');\npreg_match(M_PI, 'x');\npreg_match(DECLARED, 'x');\n";
        $files = ['Constants.php' => $constants, 'Reader.php' => $reader];

        $this->assertSame(['/declared/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'E_ALL', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => '\PHP_VERSION', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'M_PI', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'DECLARED', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * A chain of constants is followed across files, a global constant
     * naming a class constant of another file and the other way round; a
     * cycle across two files reads nothing.
     */
    #[Test]
    public function test_global_constants_fold_across_files(): void
    {
        $parts = "<?php\n\nnamespace App;\n\nconst PART = 'b';\nconst LOOP = Patterns::LOOP;\nconst THROUGH_A_CLASS = '/' . Patterns::PART . '/';\n";
        $patterns = "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const PART = 'c';\n    public const LOOP = LOOP;\n    public const THROUGH_A_CONSTANT = '#' . PART . '#';\n}\n";
        $reader = "<?php\n\nuse App\\Patterns;\n\npreg_match(\\App\\THROUGH_A_CLASS, 'x');\npreg_match(Patterns::THROUGH_A_CONSTANT, 'x');\npreg_match(\\App\\LOOP, 'x');\n";
        $files = ['Parts.php' => $parts, 'Patterns.php' => $patterns, 'Reader.php' => $reader];

        $this->assertSame([
            ['/c/', 'Parts.php', strpos($parts, "'/' . Patterns::PART"), 'preg_match()', 'via \App\THROUGH_A_CLASS'],
            ['#b#', 'Patterns.php', strpos($patterns, "'#' . PART"), 'preg_match()', 'via Patterns::THROUGH_A_CONSTANT'],
        ], self::placed($this->readAcross($files)));

        $this->assertSame(['constant', 'constant', 'opaque'], array_column($this->argumentsAcross($files), 'state'));
    }

    /**
     * Without the project's constants, a constant of another file is not
     * read, as before.
     */
    #[Test]
    public function test_without_the_project_constants_another_file_is_not_read(): void
    {
        $reader = "<?php\n\npreg_match(\\App\\WORD, 'x');\npreg_match(APP_DEFINED, 'x');\n";
        $directory = $this->makeProject(['Constants.php' => self::CONSTANTS, 'Defines.php' => self::DEFINES, 'Reader.php' => $reader]);

        $this->assertSame([], $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), [$directory.'/Reader.php']));
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([], $this->readWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), [$directory.'/Reader.php']));
        }
    }

    /**
     * Each pattern with its file, the offset of its literal there, the
     * call that reads it and the name it is read through.
     *
     * @param list<array{pattern: string, line: int, offset: int|null, source: string, location: string|null, file: string}> $read
     *
     * @return list<array{string, string, int|null, string, string|null}>
     */
    private static function placed(array $read): array
    {
        return array_map(static fn (array $row): array => [$row['pattern'], $row['file'], $row['offset'], $row['source'], $row['location']], $read);
    }

    /**
     * The rows, each once, in the order of their pattern.
     *
     * @param list<array{string, string}> $rows
     *
     * @return list<array{string, string}>
     */
    private static function sorted(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row[0].'|'.$row[1]] = $row;
        }
        ksort($unique, \SORT_STRING);

        return array_values($unique);
    }
}
