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
 * The spaces between the tokens of a file change nothing to what it
 * declares: a source written without them, each token against the one
 * before it, is read as the same source spaced out.
 *
 * PHP 8.4 (php -r): "<?php define('ONE','/one/');define('TWO','/two/');"
 * defines both; "define('BEFORE','/before/');function helper(): int{return
 * 1;}define('LATER','/later/');" and "$boot = static function() { return
 * 3; };define('LATER','/later/');" leave LATER "/later/";
 * "define('BEFORE','/before/');exit;define('NEVER','/never/');" never
 * defines NEVER; "namespace App{\define('IN_BRACES','/in-braces/');const
 * BRACED='/braced/';if(false):$d=1;\define('IN_IF',1);endif;}" defines
 * IN_BRACES and App\BRACED and leaves defined('IN_IF') false.
 *
 * Both strategies read the same.
 */
final class UnspacedDeclarationsTest extends TestCase
{
    use ExtractsAcrossFiles;

    /**
     * @return iterable<string, array{constants: string, reader: string, patterns: list<string>, states: list<string>}>
     */
    public static function provideSourcesWrittenWithoutSpaces(): iterable
    {
        yield 'two define() calls, one against the other' => [
            'constants' => "<?php define('ONE','/one/');define('TWO','/two/');",
            'reader' => "<?php\n\npreg_match(ONE, 'x');\npreg_match(TWO, 'x');\n",
            'patterns' => ['/one/', '/two/'],
            'states' => ['constant', 'constant'],
        ];

        yield 'a function against the statement before it, whose body returns' => [
            'constants' => "<?php define('BEFORE','/before/');function helper(): int{return 1;}define('LATER','/later/');",
            'reader' => "<?php\n\npreg_match(BEFORE, 'x');\npreg_match(LATER, 'x');\n",
            'patterns' => ['/before/', '/later/'],
            'states' => ['constant', 'constant'],
        ];

        yield 'a closure against its parenthesis, whose body returns' => [
            'constants' => "<?php define('BEFORE','/before/');\$boot=static function(){return 3;};define('LATER','/later/');",
            'reader' => "<?php\n\npreg_match(BEFORE, 'x');\npreg_match(LATER, 'x');\n",
            'patterns' => ['/before/', '/later/'],
            'states' => ['constant', 'constant'],
        ];

        yield 'an exit against the statement before it' => [
            'constants' => "<?php define('BEFORE','/before/');exit;define('NEVER','/never/');",
            'reader' => "<?php\n\npreg_match(BEFORE, 'x');\npreg_match(NEVER, 'x');\n",
            'patterns' => ['/before/'],
            'states' => ['constant', 'opaque'],
        ];

        yield 'a namespace against its brace' => [
            'constants' => "<?php namespace App{\\define('IN_BRACES','/in-braces/');const BRACED='/braced/';}",
            'reader' => "<?php\n\npreg_match(IN_BRACES, 'x');\npreg_match(\\App\\BRACED, 'x');\n",
            'patterns' => ['/braced/', '/in-braces/'],
            'states' => ['constant', 'constant'],
        ];

        yield 'a block of the alternative syntax in the braces of a namespace' => [
            'constants' => "<?php namespace App{\\define('IN_BRACES','/in-braces/');if(false):\$d=1;\\define('IN_IF','/in-if/');endif;}",
            'reader' => "<?php\n\npreg_match(IN_BRACES, 'x');\npreg_match(IN_IF, 'x');\n",
            'patterns' => ['/in-braces/'],
            'states' => ['constant', 'opaque'],
        ];
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $states
     */
    #[Test]
    #[DataProvider('provideSourcesWrittenWithoutSpaces')]
    public function test_a_source_written_without_spaces_declares_what_it_declares_spaced_out(string $constants, string $reader, array $patterns, array $states): void
    {
        $files = ['Constants.php' => $constants, 'Reader.php' => $reader];

        $this->assertSame($patterns, $this->patternsAcross($files));
        $this->assertSame($states, array_column($this->argumentsAcross($files), 'state'));
    }
}
