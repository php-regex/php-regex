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

namespace PHPRegex\Tests\Counted;

use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Counted\Support\Cases;
use PHPRegex\Toolkit\Regex;
use Tally\Attribute\Before;
use Tally\Attribute\DataProvider;

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

        $this->regex = ColdStart::regex();
        $this->cached = Regex::create();
        $this->cached->validate($pattern);
    }

    #[DataProvider('lexerCases')]
    public function benchTokenize(string $pattern): void
    {
        $this->regex->tokenize($pattern);
    }

    #[DataProvider('parserCases')]
    public function benchParse(string $pattern): void
    {
        $this->regex->parse($pattern);
    }

    #[DataProvider('validateCases')]
    public function benchValidate(string $pattern): void
    {
        $this->regex->validate($pattern);
    }

    /**
     * What a pipeline judging the same pattern twice pays the second time.
     */
    #[DataProvider('validateCases')]
    public function benchValidateKept(string $pattern): void
    {
        $this->cached->validate($pattern);
    }
}
