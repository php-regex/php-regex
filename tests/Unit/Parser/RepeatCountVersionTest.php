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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 10.43 widened the repeat count: "{,2}" means "{0,2}", and spaces may
 * pad the numbers, "{ 2 }" or "{2, 3}". Before (10.40 and 10.42, which PHP
 * 8.2 and 8.3 bundle), those braces are literal text: pcre2test 10.42
 * matches "/^a{,2}$/" against "a{,2}" and not "aa", and under "x" reads
 * "a{ 2 }" as the text "a{2}". From PHP 8.4 they repeat.
 */
final class RepeatCountVersionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNewRepeatCounts')]
    public function test_new_repeat_count_is_text_before_php_8_4(string $pattern, bool $repeats, string $text): void
    {
        // Whether PHP 8.4 repeats it does not matter here: before, it is text.
        unset($repeats);

        foreach ([80200, 80300] as $phpVersion) {
            $regex = Regex::create(['cache' => null, 'php_version' => $phpVersion]);

            $result = $regex->validate($pattern);
            $this->assertTrue($result->isValid, \sprintf('%s compiles on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
            $this->assertFalse(self::hasBraceRepeat($regex->parse($pattern)->pattern), \sprintf('%s repeats nothing on PHP %d.', $pattern, $phpVersion));
            // Read as text, the braces keep every character they hold.
            $this->assertSame($text, self::literalText($regex->parse($pattern)->pattern), \sprintf('%s on PHP %d.', $pattern, $phpVersion));
        }
    }

    #[Test]
    #[DataProvider('provideNewRepeatCounts')]
    public function test_new_repeat_count_repeats_from_php_8_4(string $pattern, bool $repeats, string $text): void
    {
        unset($text);

        $regex = Regex::create(['cache' => null, 'php_version' => 80400]);

        if (!$repeats) {
            // Nothing before the braces to repeat: PHP 8.4 refuses it.
            $this->assertFalse($regex->validate($pattern)->isValid, $pattern);

            return;
        }

        $this->assertTrue($regex->validate($pattern)->isValid, $pattern);
        $this->assertTrue(self::hasBraceRepeat($regex->parse($pattern)->pattern), $pattern);
    }

    #[Test]
    #[DataProvider('provideNamedCharacterCounts')]
    public function test_n_with_a_new_repeat_count_is_refused_before_php_8_4(string $pattern, int $offset): void
    {
        // pcre2test 10.40 and 10.42: error 137, "\N{name}" is not supported,
        // at the end of the "\N".
        foreach ([80200, 80300] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s is refused by PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, $pattern);
        }

        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => 80400])->validate($pattern)->isValid, $pattern);
    }

    #[Test]
    public function test_the_running_php_reads_the_count_as_its_pcre2_does(): void
    {
        $wide = version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '>=');

        $this->assertSame($wide, self::hasBraceRepeat(Regex::create(['cache' => null])->parse('/a{,2}/')->pattern));
    }

    #[Test]
    public function test_a_count_inside_an_alpha_assertion_is_read_for_the_same_version(): void
    {
        // "(*pla:...)" is parsed apart; its payload follows the same target.
        $old = Regex::create(['cache' => null, 'php_version' => 80300])->validate('/(*pla:{,2})/');
        $new = Regex::create(['cache' => null, 'php_version' => 80400])->validate('/(*pla:{,2})/');

        $this->assertTrue($old->isValid, (string) $old->error);
        $this->assertFalse($new->isValid);
    }

    #[Test]
    public function test_tokenizing_for_a_version_reads_the_count_as_it_does(): void
    {
        $old = Regex::tokenize('/a{,2}/', PcreTarget::bundledWith(80300))->getTokens();
        $new = Regex::tokenize('/a{,2}/', PcreTarget::bundledWith(80400))->getTokens();

        $this->assertSame(TokenType::Literal, $old[1]->type);
        $this->assertSame('{', $old[1]->value);
        $this->assertSame(TokenType::Quantifier, $new[1]->type);
    }

    /**
     * @return iterable<string, array{pattern: string, repeats: bool, text: string}>
     */
    public static function provideNewRepeatCounts(): iterable
    {
        yield 'open minimum' => ['pattern' => '/a{,2}/', 'repeats' => true, 'text' => 'a{,2}'];
        yield 'open minimum first' => ['pattern' => '/{,2}/', 'repeats' => false, 'text' => '{,2}'];
        yield 'padded count' => ['pattern' => '/a{ 2 }/', 'repeats' => true, 'text' => 'a{ 2 }'];
        yield 'padded count first' => ['pattern' => '/{ 2 }\\h/', 'repeats' => false, 'text' => '{ 2 }'];
        yield 'space after the comma' => ['pattern' => '/a{2, 3}/', 'repeats' => true, 'text' => 'a{2, 3}'];
        yield 'space before the closing brace' => ['pattern' => '/a{2 }/', 'repeats' => true, 'text' => 'a{2 }'];
        yield 'padded count under x' => ['pattern' => '/(?x)a{ 2 }/', 'repeats' => true, 'text' => 'a{2}'];
        yield 'possessive open minimum' => ['pattern' => '/a{,2}+/', 'repeats' => true, 'text' => 'a{,2}'];
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNamedCharacterCounts(): iterable
    {
        yield 'open minimum' => ['pattern' => '/\\N{,2}/', 'offset' => 2];
        yield 'padded count' => ['pattern' => '/\\N{4 }/', 'offset' => 2];
        yield 'padded count after a letter' => ['pattern' => '/a\\N{ 4}/', 'offset' => 3];
    }

    /**
     * The literal text the tree holds, repeated items counted once.
     */
    private static function literalText(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof LiteralNode => $node->value,
            $node instanceof QuantifierNode => self::literalText($node->node),
            $node instanceof SequenceNode => implode('', array_map(self::literalText(...), $node->children)),
            $node instanceof GroupNode => self::literalText($node->child),
            default => '',
        };
    }

    private static function hasBraceRepeat(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return str_starts_with($node->quantifier, '{') || self::hasBraceRepeat($node->node);
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (self::hasBraceRepeat($child)) {
                    return true;
                }
            }
        }

        return false;
    }
}
