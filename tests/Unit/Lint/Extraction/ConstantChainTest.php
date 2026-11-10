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
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A constant read past the 32 a value may go through is cut short. Each
 * constant of a chain that names its predecessor twice is then read twice
 * by the next one: the reading of a constant that was cut is remembered
 * with the depth it was read at, or a chain a few constants longer than
 * the bound doubles the work at each of them.
 */
final class ConstantChainTest extends TestCase
{
    use TemporaryProject;

    /**
     * Reading 33 such constants without that memory took more than half a
     * minute, and twice as long for each one more.
     */
    #[Test]
    #[DataProvider('provideChains')]
    public function test_a_chain_of_constants_naming_their_predecessor_twice_is_read_in_bounded_time(string $first, int $length): void
    {
        $file = $this->makeProject(['chain.php' => self::chain($first, $length)]).'/chain.php';

        foreach (self::strategies() as $name => $strategy) {
            $occurrences = CpuClock::timed(static fn (): array => $strategy->extractWithArguments([$file]), $elapsed);

            $this->assertLessThan(5.0, $elapsed, \sprintf('%s: %.3fs for a chain of %d constants.', $name, $elapsed, $length));
            // The chain is past what is read: the argument is a pattern around a part that is not.
            $this->assertSame(['raw_shell'], self::states($occurrences), $name);
            $this->assertSame([], self::patterns($occurrences), $name);
        }
    }

    /**
     * @return iterable<string, array{first: string, length: int}>
     */
    public static function provideChains(): iterable
    {
        // An empty first constant keeps the string under the length bound: the depth alone cuts.
        yield 'one more than the depth, nothing to concatenate' => ['first' => '', 'length' => 33];
        yield '40 constants, nothing to concatenate' => ['first' => '', 'length' => 40];
        yield '200 constants, nothing to concatenate' => ['first' => '', 'length' => 200];
        // One byte doubled reaches the length bound at the sixteenth constant.
        yield '40 constants doubling one byte' => ['first' => 'a', 'length' => 40];
        yield '200 constants doubling one byte' => ['first' => 'a', 'length' => 200];
    }

    /**
     * A chain that closes on itself is read in bounded time too: PHP refuses
     * such constants, a file may still spell them.
     */
    #[Test]
    public function test_a_chain_of_constants_closing_on_itself_is_read_in_bounded_time(): void
    {
        $code = "<?php\n\nfinal class Ring\n{\n";
        for ($i = 0; $i < 40; $i++) {
            $code .= \sprintf("    public const C%d = self::C%d . self::C%d;\n", $i, ($i + 1) % 40, ($i + 1) % 40);
        }
        $code .= "\n    public function m(string \$s): int\n    {\n        return preg_match('/^' . self::C0 . '\$/', \$s);\n    }\n}\n";
        $file = $this->makeProject(['ring.php' => $code]).'/ring.php';

        foreach (self::strategies() as $name => $strategy) {
            $occurrences = CpuClock::timed(static fn (): array => $strategy->extractWithArguments([$file]), $elapsed);

            $this->assertLessThan(5.0, $elapsed, \sprintf('%s: %.3fs for a ring of 40 constants.', $name, $elapsed));
            $this->assertSame(['raw_shell'], self::states($occurrences), $name);
            $this->assertSame([], self::patterns($occurrences), $name);
        }
    }

    /**
     * The same constant read from nearer the call, after a reading that was
     * cut, still gives all it holds: what is remembered of a cut is the cut
     * from that depth, not the constant.
     */
    #[Test]
    public function test_a_constant_cut_from_afar_is_still_read_from_nearer_the_call(): void
    {
        $code = "<?php\n\nfinal class Near\n{\n    public const C0 = 'x';\n";
        for ($i = 1; $i <= 40; $i++) {
            $code .= \sprintf("    public const C%d = self::C%d;\n", $i, $i - 1);
        }
        $code .= "\n    public function m(string \$s): bool\n    {\n        return preg_match('/^' . self::C40 . '\$/', \$s) || preg_match('/^' . self::C20 . '\$/', \$s);\n    }\n}\n";
        $file = $this->makeProject(['near.php' => $code]).'/near.php';

        foreach (self::strategies() as $name => $strategy) {
            $occurrences = $strategy->extractWithArguments([$file]);

            $this->assertSame(['raw_shell', 'constant'], self::states($occurrences), $name);
            $this->assertSame(['/^x$/'], self::patterns($occurrences), $name);
        }
    }

    private static function chain(string $first, int $length): string
    {
        $code = "<?php\n\nfinal class Chain\n{\n    public const C0 = ".var_export($first, true).";\n";
        for ($i = 1; $i <= $length; $i++) {
            $code .= \sprintf("    public const C%d = self::C%d . self::C%d;\n", $i, $i - 1, $i - 1);
        }

        return $code.\sprintf("\n    public function m(string \$s): int\n    {\n        return preg_match('/^' . self::C%d . '\$/', \$s);\n    }\n}\n", $length);
    }

    /**
     * @return iterable<string, ExtractorInterface&ArgumentAwareInterface>
     */
    private static function strategies(): iterable
    {
        yield 'tokenizer' => new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native());
        if (class_exists(ParserFactory::class)) {
            yield 'php-parser' => new PhpParserExtractionStrategy([], PatternFunctionRegistry::native());
        }
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function states(array $occurrences): array
    {
        return array_values(array_filter(array_map(static fn (PatternOccurrence $occurrence): ?string => $occurrence->state, $occurrences), static fn (?string $state): bool => null !== $state));
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patterns(array $occurrences): array
    {
        return array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->state)));
    }
}
