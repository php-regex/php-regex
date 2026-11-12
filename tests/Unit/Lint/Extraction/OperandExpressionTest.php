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

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\AstValueCollector;
use PHPRegex\Linter\Extraction\CallArguments;
use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPRegex\Linter\Extraction\PhpTokens;
use PHPRegex\Linter\Extraction\TokenValueCollector;
use PHPRegex\Linter\Extraction\ValueExpr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the two strategies hand the resolution for an operand it is to
 * read in a new way: a preg_quote() call as a quoted hole keeping its
 * delimiter, an integer literal as a scalar keeping its value. The state
 * of an argument does not show the delimiter of a hole or the value of an
 * integer on its own, so both are read here on the expression each
 * collector builds, the operands of the concatenation laid flat.
 *
 * The tokenizer is checked always, nikic/php-parser where it is installed,
 * and the two must build the same operands.
 */
final class OperandExpressionTest extends TestCase
{
    /**
     * The delimiter is the second argument of preg_quote() when a string
     * literal gives it, decoded as PHP decodes it, and null otherwise.
     * PHP 8.4: preg_quote("a/b", "\x2f") and preg_quote(delimiter: "/",
     * str: "a/b") are both "a\/b"; preg_quote("a/b", null) is "a/b".
     *
     * @return iterable<string, array{call: string, delimiter: string|null}>
     */
    public static function provideQuotedCalls(): iterable
    {
        yield 'no delimiter' => ['call' => 'preg_quote($word)', 'delimiter' => null];
        yield 'a single-quoted delimiter' => ['call' => "preg_quote(\$word, '/')", 'delimiter' => '/'];
        yield 'a double-quoted delimiter' => ['call' => 'preg_quote($word, "#")', 'delimiter' => '#'];
        yield 'a delimiter written as an escape' => ['call' => 'preg_quote($word, "\x2f")', 'delimiter' => '/'];
        yield 'a delimiter passed by name' => ['call' => "preg_quote(delimiter: '~', str: \$word)", 'delimiter' => '~'];
        yield 'a delimiter in a variable' => ['call' => 'preg_quote($word, $delimiter)', 'delimiter' => null];
        yield 'a delimiter in a constant' => ['call' => 'preg_quote($word, DELIMITER)', 'delimiter' => null];
        yield 'null as the delimiter' => ['call' => 'preg_quote($word, null)', 'delimiter' => null];
        yield 'fully qualified' => ['call' => "\\preg_quote(\$word, '%')", 'delimiter' => '%'];
        yield 'spelled in upper case' => ['call' => "PREG_QUOTE(\$word, '/')", 'delimiter' => '/'];
        yield 'a quoted literal' => ['call' => "preg_quote('a.b', '/')", 'delimiter' => '/'];
    }

    #[Test]
    #[DataProvider('provideQuotedCalls')]
    public function test_a_preg_quote_operand_is_a_quoted_hole_keeping_its_delimiter(string $call, ?string $delimiter): void
    {
        $operands = $this->operands("'/^' . ".$call." . '$/'");

        $this->assertCount(3, $operands);
        $this->assertSame([ValueExpr::LITERAL, '/^'], [$operands[0]->kind, $operands[0]->value]);
        $this->assertSame(ValueExpr::HOLE, $operands[1]->kind);
        $this->assertTrue($operands[1]->quoted);
        $this->assertSame($delimiter, $operands[1]->delimiter);
        $this->assertSame([ValueExpr::LITERAL, '$/'], [$operands[2]->kind, $operands[2]->value]);
    }

    /**
     * A method or a function of another namespace named preg_quote quotes
     * nothing the library knows of.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideCallsThatAreNotPregQuote(): iterable
    {
        yield 'a method' => ['call' => '$text->preg_quote($word)'];
        yield 'a static method' => ['call' => 'Text::preg_quote($word)'];
        yield 'a function of another namespace' => ['call' => '\\Vendor\\Text\\preg_quote($word)'];
        yield 'another function' => ['call' => 'quotemeta($word)'];
    }

    #[Test]
    #[DataProvider('provideCallsThatAreNotPregQuote')]
    public function test_a_call_that_is_not_preg_quote_is_no_quoted_hole(string $call): void
    {
        // Beside a call that is one, so that the two are told apart.
        $operands = $this->operands("'/^' . ".$call." . preg_quote(\$word, '/')");

        $this->assertCount(3, $operands);
        $this->assertFalse($operands[1]->quoted);
        $this->assertNull($operands[1]->delimiter);
        $this->assertSame(ValueExpr::HOLE, $operands[2]->kind);
        $this->assertTrue($operands[2]->quoted);
    }

    /**
     * @return iterable<string, array{operand: string, integer: int}>
     */
    public static function provideIntegerLiterals(): iterable
    {
        yield 'zero' => ['operand' => '0', 'integer' => 0];
        yield 'one' => ['operand' => '1', 'integer' => 1];
        yield 'negative' => ['operand' => '-3', 'integer' => -3];
        yield 'with a plus sign' => ['operand' => '+3', 'integer' => 3];
        yield 'minus zero' => ['operand' => '-0', 'integer' => 0];
        yield 'a sign apart from its digits' => ['operand' => '- 3', 'integer' => -3];
        yield 'hexadecimal' => ['operand' => '0x10', 'integer' => 16];
        yield 'octal' => ['operand' => '010', 'integer' => 8];
        yield 'octal with its prefix' => ['operand' => '0o17', 'integer' => 15];
        yield 'binary' => ['operand' => '0b11', 'integer' => 3];
        yield 'with a digit separator' => ['operand' => '1_000', 'integer' => 1000];
        // The largest of the PHP that runs: 9223372036854775807 is a float where an integer holds 32 bits.
        yield 'the largest' => ['operand' => (string) \PHP_INT_MAX, 'integer' => \PHP_INT_MAX];
    }

    #[Test]
    #[DataProvider('provideIntegerLiterals')]
    public function test_an_integer_literal_operand_keeps_its_value(string $operand, int $integer): void
    {
        $operands = $this->operands("'/^a{' . ".$operand." . '}$/'");

        $this->assertCount(3, $operands);
        $this->assertSame(ValueExpr::NON_STRING, $operands[1]->kind);
        $this->assertSame($integer, $operands[1]->integer);
    }

    /**
     * PHP reads one past its largest integer as a float, 9223372036854775808
     * where an integer holds 64 bits, and so its negation: no integer is
     * kept for them, nor for any other float.
     *
     * @return iterable<string, array{operand: string}>
     */
    public static function provideFloatLiterals(): iterable
    {
        yield 'a float' => ['operand' => '1.5'];
        yield 'a float with no fraction' => ['operand' => '1.0'];
        yield 'a float with an exponent' => ['operand' => '1e3'];
        yield 'a negative float' => ['operand' => '-1.5'];
        // A power of two: the float holds it whole, and "%.0F" writes its digits.
        $pastTheLargest = \sprintf('%.0F', \PHP_INT_MAX + 1);
        yield 'one past the largest integer' => ['operand' => $pastTheLargest];
        yield 'the smallest integer, a float as a literal' => ['operand' => '-'.$pastTheLargest];
    }

    #[Test]
    #[DataProvider('provideFloatLiterals')]
    public function test_a_float_literal_operand_keeps_no_integer(string $operand): void
    {
        // Beside an integer, so that the two are told apart.
        $operands = $this->operands("'/^a{' . ".$operand." . ',' . 7 . '}$/'");

        $this->assertCount(5, $operands);
        $this->assertSame(ValueExpr::NON_STRING, $operands[1]->kind);
        $this->assertNull($operands[1]->integer);
        $this->assertSame(ValueExpr::NON_STRING, $operands[3]->kind);
        $this->assertSame(7, $operands[3]->integer);
    }

    /**
     * The operands of the concatenation as the tokenizer's collector
     * builds them, checked equal to those nikic/php-parser's collector
     * builds where it is installed.
     *
     * @return list<ValueExpr>
     */
    private function operands(string $argument): array
    {
        $content = "<?php\npreg_match(".$argument.", \$subject);\n";

        $tokens = token_get_all($content);
        $closers = PhpTokens::closers($tokens);
        $open = array_search('(', $tokens, true);
        $this->assertIsInt($open);
        $range = CallArguments::find($tokens, $open + 1, \count($tokens), 0, ['pattern'], $closers);
        $this->assertNotNull($range);

        $collector = new TokenValueCollector($tokens, PhpTokens::offsets($tokens), $closers, $content, [], false);
        $fromTokens = self::flat($collector->expression($range[0], $range[1], new NameResolutionContext()));

        if (class_exists(ParserFactory::class)) {
            $ast = (new ParserFactory())->createForHostVersion()->parse($content);
            $this->assertIsArray($ast);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            $statement = $traverser->traverse($ast)[0] ?? null;
            $this->assertInstanceOf(Expression::class, $statement);
            $call = $statement->expr;
            $this->assertInstanceOf(FuncCall::class, $call);

            $fromAst = self::flat((new AstValueCollector(static fn (): array => []))->expression($call->getArgs()[0]->value));

            $this->assertSame(array_map(self::shape(...), $fromAst), array_map(self::shape(...), $fromTokens), 'The token collector builds other operands than the parser collector.');
        }

        return $fromTokens;
    }

    /**
     * The operands of a concatenation, those of the concatenations it
     * holds in their place: php-parser nests what the tokenizer lists.
     *
     * @return list<ValueExpr>
     */
    private static function flat(ValueExpr $expr): array
    {
        if (ValueExpr::CONCAT !== $expr->kind) {
            return [$expr];
        }

        $operands = [];
        foreach ($expr->parts as $part) {
            array_push($operands, ...self::flat($part));
        }

        return $operands;
    }

    /**
     * What tells two operands apart for the resolution.
     *
     * @return array{kind: string, value: string, quoted: bool, delimiter: string|null, integer: int|null, written: string}
     */
    private static function shape(ValueExpr $expr): array
    {
        return [
            'kind' => $expr->kind,
            'value' => $expr->value,
            'quoted' => $expr->quoted,
            'delimiter' => $expr->delimiter,
            'integer' => $expr->integer,
            'written' => $expr->written,
        ];
    }
}
