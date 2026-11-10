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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a pattern argument stands: the line, the column and the byte
 * offset of its first token once the parentheses around the whole of it
 * are stripped, and its expression, the source from that token to its
 * last, byte for byte. Columns count bytes from 1, as those of the
 * patterns do.
 *
 * The helper derives the expected line, column and offset from where the
 * expression stands in the code; both strategies must give them.
 */
final class ArgumentPositionTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_an_argument_in_parentheses_starts_at_its_inner_first_token(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/once' . \$a"],
            ['state' => 'raw_shell', 'expression' => "'/twice' . \$b"],
            ['state' => 'constant', 'expression' => "'/literal-in-parentheses/'"],
            ['state' => 'opaque', 'expression' => '$c', 'at' => '$c), $s)'],
        ], <<<'PHP'
            <?php

            function run(string $s, string $a, string $b, string $c): void
            {
                preg_match(('/once' . $a), $s);
                preg_match(  ( ( '/twice' . $b ) )  , $s);
                preg_match(('/literal-in-parentheses/'), $s);
                preg_match(($c), $s);
            }
            PHP);
    }

    /**
     * Parentheses that do not wrap the whole argument are part of it: the
     * argument starts on the first of them, where the pattern of a literal
     * written this way is reported today.
     */
    #[Test]
    public function test_parentheses_around_a_part_of_the_argument_belong_to_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $a): void
            {
                preg_match(('/part') . $a, $s);
                preg_match('/tail' . ($a), $s);
                preg_match(('/literal-part/') . 'i', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/literal-part/i', 'at' => "('/literal-part/') . 'i'"],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "('/part') . \$a"],
            ['state' => 'raw_shell', 'expression' => "'/tail' . (\$a)"],
            ['state' => 'constant', 'expression' => "('/literal-part/') . 'i'"],
        ], $code);
    }

    /**
     * The expression of an argument written over several lines holds its
     * line breaks and its indentation; the line is that of its first
     * token.
     */
    #[Test]
    public function test_an_argument_over_several_lines_keeps_its_line_breaks(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^first' .\n            \$word .\n        '$/'"],
            ['state' => 'opaque', 'expression' => "\$word\n            . '/second/'"],
        ], <<<'PHP'
            <?php

            function run(string $s, string $word): void
            {
                preg_match(
                    '/^first' .
                        $word .
                    '$/',
                    $s
                );
                preg_match(
                    (
                        $word
                        . '/second/'
                    ),
                    $s,
                );
            }
            PHP);
    }

    /**
     * Windows line endings are bytes of the expression as any other.
     */
    #[Test]
    public function test_an_argument_over_several_lines_keeps_its_carriage_returns(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^crlf' .\r\n        \$word"],
        ], "<?php\r\n\r\nfunction run(string \$s, string \$word): void\r\n{\r\n    preg_match(\r\n        '/^crlf' .\r\n        \$word,\r\n        \$s\r\n    );\r\n}\r\n");
    }

    /**
     * 'été' is five bytes and three characters: after it on the line, the
     * column and the offset count the bytes, as the pattern of the literal
     * on the same line does (PHP 8.4: strlen('été') is 5).
     */
    #[Test]
    public function test_an_argument_after_multi_byte_text_is_placed_in_bytes(): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$word): void\n{\n    \$label = 'été'; preg_match('/whole/', \$s); preg_match('/^été' . \$word, \$s);\n}\n";

        $read = $this->argumentsReadByBoth($code);
        $this->assertSame(['constant', 'raw_shell'], array_map(static fn (array $argument): string => $argument['state'], $read));

        // 4 spaces, "$label = 'été'; " is 18 bytes, "preg_match(" 11.
        $this->assertSame(5, $read[0]['line']);
        $this->assertSame(34, $read[0]['column']);
        $this->assertSame(\strlen("<?php\n\nfunction run(string \$s, string \$word): void\n{\n") + 33, $read[0]['offset']);

        // "'/whole/', $s); preg_match(" is 27 bytes more.
        $this->assertSame(5, $read[1]['line']);
        $this->assertSame(61, $read[1]['column']);
        $this->assertSame("'/^été' . \$word", $read[1]['expression']);
        $this->assertSame(17, \strlen($read[1]['expression']));

        $this->assertExtractedByBoth([
            ['pattern' => '/whole/', 'at' => "'/whole/'"],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/whole/'"],
            ['state' => 'raw_shell', 'expression' => "'/^été' . \$word"],
        ], $code);
    }

    /**
     * An argument passed by name starts after its name: the label is no
     * part of the expression, wherever the argument stands in the call.
     */
    #[Test]
    public function test_a_named_argument_starts_after_its_name(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/named-last' . \$word", 'source' => 'preg_match()'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, subject: $s)', 'source' => 'preg_match_all()'],
            ['state' => 'constant', 'expression' => "'/named-literal/'", 'source' => 'preg_split()'],
        ], <<<'PHP'
            <?php

            function run(string $s, string $re, string $word): void
            {
                preg_match(subject: $s, pattern: '/named-last' . $word);
                preg_match_all(pattern: $re, subject: $s);
                preg_split(subject: $s, pattern  :  '/named-literal/');
            }
            PHP);
    }

    /**
     * A comment before or after the argument is not the argument; one
     * inside it is among its bytes.
     */
    #[Test]
    public function test_a_comment_around_the_argument_is_left_out_of_it(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/commented' . \$word"],
            ['state' => 'raw_shell', 'expression' => "'/inside' /* why */ . \$word"],
        ], <<<'PHP'
            <?php

            function run(string $s, string $word): void
            {
                preg_match(/* before */ '/commented' . $word /* after */, $s);
                preg_match('/inside' /* why */ . $word, $s);
            }
            PHP);
    }

    /**
     * Each key of preg_replace_callback_array() is placed on its own, the
     * parentheses around a key stripped as around any argument.
     */
    #[Test]
    public function test_each_key_of_a_callback_array_has_its_own_place(): void
    {
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/key-one/'"],
            ['state' => 'raw_shell', 'expression' => "'/key-two' . \$word"],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re => $upper'],
            ['state' => 'raw_shell', 'expression' => "'/key-four' . \$word"],
        ], <<<'PHP'
            <?php

            function run(string $s, string $re, string $word, callable $upper): ?string
            {
                return preg_replace_callback_array(['/key-one/' => $upper, '/key-two' . $word => $upper,
                    $re => $upper,
                    ('/key-four' . $word) => $upper], $s);
            }
            PHP);
    }

    /**
     * preg_replace() with an array mixing a literal and what is none: each
     * item at its own first token, on its own line where it has one.
     */
    #[Test]
    public function test_each_item_of_an_array_literal_has_its_own_place(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $re, string $word): string
            {
                return (string) preg_replace(['/item-one/', $re,
                    '/item-three' . $word,
                    ('/item-four/'),
                ], '', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/item-one/', 'at' => "'/item-one/'"],
            ['pattern' => '/item-four/', 'at' => "'/item-four/'"],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/item-one/'"],
            ['state' => 'opaque', 'expression' => '$re', 'at' => "\$re,\n"],
            ['state' => 'raw_shell', 'expression' => "'/item-three' . \$word"],
            ['state' => 'constant', 'expression' => "'/item-four/'"],
        ], $code);
    }

    /**
     * A literal argument stands where its pattern does: the same line, the
     * same column, the same offset.
     */
    #[Test]
    public function test_a_literal_argument_stands_where_its_pattern_does(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): void
            {
                preg_match(  '/spaced/'  , $s);
                preg_match(<<<'RE'
                    /nowdoc/
                    RE, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/spaced/', 'at' => "'/spaced/'"],
            ['pattern' => '/nowdoc/', 'at' => "<<<'RE'"],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/spaced/'"],
            ['state' => 'constant', 'expression' => "<<<'RE'\n        /nowdoc/\n        RE"],
        ], $code);
    }
}
