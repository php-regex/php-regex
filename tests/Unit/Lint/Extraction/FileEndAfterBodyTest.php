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
 * A return written in the body of a function or of a class ends that
 * body; one written after the body closes is of the file again, and ends
 * it: what the file declares after it is declared only when the return
 * did not run. A class named by "::class" opens no body, whatever braces
 * follow it. An exit that opens the file always runs.
 *
 * PHP 8.4 (php -r, the file required after define('GUARDED',
 * '/set-elsewhere/')): "function helper(): int { return 1; } if
 * (defined('GUARDED')) { return; } define('GUARDED', '/guarded/');"
 * leaves GUARDED "/set-elsewhere/", and so does the same file with a
 * class holding a method in the place of the function, with "switch
 * ($mode) { case Legacy::class: { return; } }" when $mode is
 * Legacy::class, and with "echo Legacy::class ?>" followed by the
 * guarded return in a new "<?php" block. "<?php exit; define('NEVER',
 * 1);" defines nothing.
 *
 * Both strategies read the same.
 */
final class FileEndAfterBodyTest extends TestCase
{
    use ExtractsAcrossFiles;

    /**
     * @return iterable<string, array{statements: string}>
     */
    public static function provideReturnsOfTheFileAfterABody(): iterable
    {
        $guard = "if (defined('GUARDED')) {\n    return;\n}";

        yield 'after a function that returns' => ['statements' => "function helper(): int\n{\n    return 1;\n}\n\n".$guard];
        yield 'after a class whose method returns' => ['statements' => "final class Boot\n{\n    public static function run(): int\n    {\n        return 2;\n    }\n}\n\n".$guard];
        yield 'after a closure that returns' => ['statements' => "\$boot = static function (): int {\n    return 3;\n};\n\n".$guard];
        yield 'after a function with no return' => ['statements' => "function boot(): void\n{\n}\n\nreturn;"];
        yield 'in the braces of a case naming a class' => ['statements' => "switch (\$mode) {\n    case Legacy::class: {\n        return;\n    }\n}"];
        yield 'in a branch, after a class name is printed before a closing tag' => ['statements' => "echo Legacy::class ?>\n<?php ".$guard];
    }

    /**
     * The constant is declared, with a value no file proves; what the
     * file declares before the return is still read.
     */
    #[Test]
    #[DataProvider('provideReturnsOfTheFileAfterABody')]
    public function test_a_return_of_the_file_written_after_a_body_ends_the_file(string $statements): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$statements."\n\ndefine('GUARDED', '/guarded/');\n",
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(BEFORE, 'x');\n",
        ];

        $this->assertSame(['/before/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{opening: string}>
     */
    public static function provideExitsThatOpenTheFile(): iterable
    {
        yield 'an exit' => ['opening' => "<?php\nexit;\n"];
        yield 'an exit on the line of the opening tag' => ['opening' => "<?php exit('Not meant to be loaded.');\n"];
        yield 'a die after a comment' => ['opening' => "<?php\n\n// Kept for the record.\ndie;\n"];
    }

    /**
     * Nothing stands before the exit but the opening tag: it starts a
     * statement, and what follows it never runs.
     */
    #[Test]
    #[DataProvider('provideExitsThatOpenTheFile')]
    public function test_an_exit_that_opens_the_file_ends_it(string $opening): void
    {
        $files = [
            'Constants.php' => $opening."\ndefine('GUARDED', '/guarded/');\n",
            'Beside.php' => "<?php\n\ndefine('BESIDE', '/beside/');\n",
            'Reader.php' => "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(BESIDE, 'x');\n",
        ];

        $this->assertSame(['/beside/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'BESIDE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }
}
