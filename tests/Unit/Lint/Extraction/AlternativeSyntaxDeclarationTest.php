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
 * A block written in the alternative syntax, "if (...):" to "endif;", is
 * a block as one written in braces is: a define() standing in it may not
 * have run when another file reads the constant, and one standing after
 * it is at the top level of its file again. A block of either syntax
 * written in the body of a function is no block of the file.
 *
 * PHP 8.4 (php -r): "if (false): $d = 1; define('IN_IF', 1); endif;"
 * leaves defined('IN_IF') false, and so do the else of an if that runs,
 * "while (false):", "for ($i = 0; $i < 0; $i++):", "foreach ([] as
 * $x):" and "switch (1): case 2:" around the same define(). After "if
 * (false): $d = 1; endif; foreach ([] as $x): $d = 1; endforeach; if
 * (true): while (false): $d = 1; endwhile; endif;", define('AFTER',
 * '/after/') leaves AFTER "/after/"; so it does after "function
 * boot(array $xs): void { if ([] === $xs) { echo 1; } foreach ($xs as
 * $x): echo $x; endforeach; }", and after "if (!defined('BEFORE')):
 * echo 'no'; exit; endif;" when BEFORE is defined.
 *
 * Both strategies read the same.
 */
final class AlternativeSyntaxDeclarationTest extends TestCase
{
    use ExtractsAcrossFiles;

    private const READER = "<?php\n\npreg_match(GUARDED, 'x');\npreg_match(BEFORE, 'x');\n";

    /**
     * Each block holds a statement before its define(): the call follows
     * a statement, as one of the top level does, and the block alone
     * says it may not run.
     *
     * @return iterable<string, array{block: string}>
     */
    public static function provideBlocksOfTheAlternativeSyntax(): iterable
    {
        $body = "\n    \$declared = true;\n    define('GUARDED', '/guarded/');\n";

        yield 'an if' => ['block' => "if (!defined('GUARDED')):".$body.'endif;'];
        yield 'an if written against its parenthesis' => ['block' => "if(!defined('GUARDED')):".$body.'endif;'];
        yield 'the else of an if' => ['block' => "if (defined('GUARDED')):\n    \$declared = false;\nelse:".$body.'endif;'];
        yield 'a while' => ['block' => "while (!defined('GUARDED')):".$body.'endwhile;'];
        yield 'a for' => ['block' => 'for ($i = 0; $i < 1; $i++):'.$body.'endfor;'];
        yield 'a foreach' => ['block' => "foreach (['GUARDED'] as \$name):".$body.'endforeach;'];
        yield 'a switch' => ['block' => "switch (\\PHP_OS_FAMILY):\n    default:".$body.'endswitch;'];
        yield 'an if, after a block it holds is closed' => ['block' => "if (\\PHP_VERSION_ID >= 80000):\n    if (\\PHP_INT_SIZE === 8):\n        \$wide = true;\n    endif;".$body.'endif;'];
        yield 'an if, after a block in braces it holds' => ['block' => "if (\\PHP_VERSION_ID >= 80000):\n    if (\\PHP_INT_SIZE === 8) {\n        \$wide = true;\n    }".$body.'endif;'];
    }

    #[Test]
    #[DataProvider('provideBlocksOfTheAlternativeSyntax')]
    public function test_a_define_in_a_block_of_the_alternative_syntax_is_not_read(string $block): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$block."\n",
            'Reader.php' => self::READER,
        ];

        $this->assertSame(['/before/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'opaque'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{blocks: string}>
     */
    public static function provideClosedBlocksOfTheAlternativeSyntax(): iterable
    {
        yield 'an if' => ['blocks' => "if (!extension_loaded('mbstring')):\n    \$missing = true;\nendif;"];
        yield 'an if and its else' => ['blocks' => "if (!extension_loaded('mbstring')):\n    \$missing = true;\nelse:\n    \$missing = false;\nendif;"];
        yield 'a while' => ['blocks' => "while (\$pending > 0):\n    \$pending--;\nendwhile;"];
        yield 'a for' => ['blocks' => "for (\$i = 0; \$i < 3; \$i++):\n    \$seen = \$i;\nendfor;"];
        yield 'a foreach' => ['blocks' => "foreach (\$argv as \$argument):\n    \$seen = \$argument;\nendforeach;"];
        yield 'a switch' => ['blocks' => "switch (\\PHP_OS_FAMILY):\n    default:\n        \$family = 'other';\nendswitch;"];
        yield 'a block in another' => ['blocks' => "if (\$argc > 1):\n    while (\$pending > 0):\n        \$pending--;\n    endwhile;\nendif;"];
        yield 'two blocks, one after the other' => ['blocks' => "if (\$argc > 1):\n    \$many = true;\nendif;\n\nforeach (\$argv as \$argument):\n    \$seen = \$argument;\nendforeach;"];
        // The exit stops the script or lets the declaration run: no code reads the constant without it.
        yield 'an if that guards an exit' => ['blocks' => "if (!defined('BEFORE')):\n    echo 'No direct access.';\n    exit;\nendif;"];
    }

    /**
     * Past its "endif;", the file is at its top level again: the block
     * hides nothing of what follows it.
     */
    #[Test]
    #[DataProvider('provideClosedBlocksOfTheAlternativeSyntax')]
    public function test_a_define_after_a_closed_block_of_the_alternative_syntax_is_read(string $blocks): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$blocks."\n\ndefine('GUARDED', '/guarded/');\n",
            'Reader.php' => self::READER,
        ];

        $this->assertSame(['/before/', '/guarded/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    /**
     * @return iterable<string, array{body: string}>
     */
    public static function provideBlocksWrittenInABody(): iterable
    {
        yield 'blocks in braces, in a function' => ['body' => "function boot(array \$xs): void\n{\n    if ([] === \$xs) {\n        echo 'none';\n    }\n\n    foreach (\$xs as \$x) {\n        echo \$x;\n    }\n}"];
        yield 'blocks of the alternative syntax, in a function' => ['body' => "function boot(array \$xs): void\n{\n    if ([] === \$xs):\n        echo 'none';\n    endif;\n\n    foreach (\$xs as \$x):\n        echo \$x;\n    endforeach;\n}"];
        yield 'a block holding a return, in a method' => ['body' => "final class Boot\n{\n    public static function run(array \$xs): int\n    {\n        while ([] !== \$xs):\n            return \\count(\$xs);\n        endwhile;\n\n        return 0;\n    }\n}"];
        yield 'a block in a closure' => ['body' => "\$boot = static function (array \$xs): void {\n    switch (\\count(\$xs)):\n        case 0:\n            echo 'none';\n    endswitch;\n};"];
    }

    /**
     * A block a function, a method or a closure holds opens and closes
     * in that body: the define() written after the body stands at the
     * top level of the file.
     */
    #[Test]
    #[DataProvider('provideBlocksWrittenInABody')]
    public function test_a_block_written_in_a_body_is_no_block_of_the_file(string $body): void
    {
        $files = [
            'Constants.php' => "<?php\n\ndefine('BEFORE', '/before/');\n\n".$body."\n\ndefine('GUARDED', '/guarded/');\n",
            'Reader.php' => self::READER,
        ];

        $this->assertSame(['/before/', '/guarded/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'GUARDED', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => 'BEFORE', 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }
}
