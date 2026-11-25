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

namespace PHPRegex\Tests\Benchmark\Tally;

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Tally\Support\Cases;
use PHPRegex\Toolkit\Regex;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;
use Tally\Attribute\ExpectedFor;
use Tally\Attribute\MetricTolerance;

/**
 * The path every consumer takes first: a pattern tokenized, parsed and
 * validated, cold, then validated again by the facade that kept it.
 *
 * Cold: the hook reads the pattern once, so the code is loaded, then starts
 * from a facade with no cache and the library's caches emptied; the counted
 * call pays for reading the pattern, not for compiling the library.
 */
final class ValidationBench
{
    private Regex $regex;

    private Regex $cached;

    /**
     * @return iterable<string, array{string}>
     */
    public static function lexerCases(): iterable
    {
        return Cases::patterns('lexer');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function parserCases(): iterable
    {
        return Cases::patterns('parser');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validateCases(): iterable
    {
        return Cases::patterns('validate');
    }

    #[Before]
    public function load(string $pattern): void
    {
        try {
            ColdStart::regex()->validate($pattern);
            ColdStart::regex()->parse($pattern);
        } catch (RegexException) {
            // A refused pattern loads the code of its refusal.
        }

        // The kept facade keeps its result on its own; its reading fills the
        // process-wide caches too, which the cold facade, made last, empties.
        $this->cached = Regex::create();
        $this->cached->validate($pattern);
        $this->regex = ColdStart::regex();
    }

    #[DataProvider('lexerCases')]
    #[ExpectedFor('escape-dense', instructions: '121.6M', cycles: '179.5M', memory: '626.7KiB')]
    #[ExpectedFor('extended-comments', instructions: '268.6M', cycles: '376.7M', memory: '1.812MiB')]
    #[ExpectedFor('long-literal', instructions: '98.41M', cycles: '105.1M', memory: '3.166MiB')]
    #[ExpectedFor('quoted-run', instructions: '11.50M', cycles: '12.20M', memory: '119.4KiB')]
    #[ExpectedFor('raw-high-bytes', instructions: '502.0K', cycles: '1.129M', memory: '12.85KiB')]
    public function benchTokenize(string $pattern): void
    {
        $this->regex->tokenize($pattern);
    }

    #[DataProvider('parserCases')]
    #[ExpectedFor('big-class-unicode', instructions: '61.82M', cycles: '88.12M', memory: '435.8KiB')]
    #[ExpectedFor('deep-nesting', instructions: '18.73M', cycles: '28.03M', memory: '1.079MiB')]
    #[ExpectedFor('huge-alternation', instructions: '564.8M', cycles: '682.9M', memory: '7.205MiB')]
    #[ExpectedFor('many-named-groups', instructions: '369.1M', cycles: '475.6M', memory: '3.938MiB')]
    #[ExpectedFor('nested-bounded-repeats', instructions: '775.0K', cycles: '1.718M', memory: '12.30KiB')]
    #[ExpectedFor('route-hash-delimiter', instructions: '2.198M', cycles: '3.778M', memory: '23.58KiB')]
    public function benchParse(string $pattern): void
    {
        $this->regex->parse($pattern);
    }

    #[DataProvider('validateCases')]
    #[ExpectedFor('counted-4500', instructions: '613.3K', cycles: '1.589M', memory: '12.31KiB')]
    #[ExpectedFor('lookbehind-alternatives', instructions: '57.68M', cycles: '65.03M', memory: '507.8KiB')]
    #[ExpectedFor('many-backreferences', instructions: '21.19M', cycles: '29.09M', memory: '114.5KiB')]
    #[ExpectedFor('recursion-balanced', instructions: '1.166M', cycles: '2.574M', memory: '14.70KiB')]
    #[ExpectedFor('reversed-quantifier', instructions: '604.1K', cycles: '1.626M', memory: '24.12KiB')]
    #[ExpectedFor('unclosed-group', instructions: '925.3K', cycles: '1.888M', memory: '28.39KiB')]
    public function benchValidate(string $pattern): void
    {
        $this->regex->validate($pattern);
    }

    /**
     * What a pipeline judging the same pattern twice pays the second time.
     *
     * A pattern longer than 256 bytes is kept under its SHA-256, which the
     * call computes again, about 55 instructions a byte. The job has counted
     * those two cases about 9,600 cycles apart on heads that leave the
     * validation path alone, 141.1K or 150.6K and 84.51K or 94.18K, where a
     * count in the same image on those heads gives each the lower figure.
     * The cycles may move by 12,000; the instructions keep their 2 %.
     */
    #[DataProvider('validateCases')]
    #[MetricTolerance(instructions: 0.02, cycles: 0.05, memory: 0.05, cyclesAtLeast: 12_000)]
    #[ExpectedFor('counted-4500', instructions: '2.458K', cycles: '21.65K', memory: '40B')]
    #[ExpectedFor('lookbehind-alternatives', instructions: '106.2K', cycles: '141.1K', memory: '192B')]
    #[ExpectedFor('many-backreferences', instructions: '52.16K', cycles: '94.18K', memory: '192B')]
    #[ExpectedFor('recursion-balanced', instructions: '2.500K', cycles: '21.63K', memory: '56B')]
    #[ExpectedFor('reversed-quantifier', instructions: '2.453K', cycles: '21.75K', memory: '40B')]
    #[ExpectedFor('unclosed-group', instructions: '2.461K', cycles: '21.76K', memory: '40B')]
    public function benchValidateKept(string $pattern): void
    {
        $this->cached->validate($pattern);
    }
}
