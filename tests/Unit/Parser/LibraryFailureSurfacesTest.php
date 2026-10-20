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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where PHP refuses to raise the PCRE limits (a disabled ini_set, a
 * php_admin_value) the library's own regexes run under the caller's, and
 * may give up. That is no verdict on the pattern: validate(), parse() and
 * parseTolerant() throw an InternalFailureException, never a syntax error.
 *
 * Engine facts (PHP 8.4.26, PCRE2 10.49, JIT off): the regex that reads a
 * quoted run gives up on 20 000 quoted letters under a backtrack limit of
 * 10 000; the regex that checks the modifiers gives up on "i", and on no
 * modifier at all, under a backtrack limit of 1. Under the default limits
 * every pattern here is valid.
 *
 * Each test runs in a process of its own, the JIT off before any regex is
 * compiled: a JIT-compiled copy counts its backtracks another way.
 */
final class LibraryFailureSurfacesTest extends TestCase
{
    /**
     * @param 'parse'|'parseTolerant'|'validate' $method
     */
    #[Test]
    #[RunInSeparateProcess]
    #[DataProvider('provideStarvedPatterns')]
    public function test_a_library_regex_giving_up_surfaces(string $pattern, string $limit, string $method): void
    {
        ini_set('pcre.jit', '0');
        $parser = RegexParser::create(['cache' => null]);
        $this->assertTrue($parser->validate($pattern)->isValid, 'Oracle: valid under the default limits.');
        $this->assertNotFalse(@preg_match($pattern, ''), 'Oracle: PHP compiles it.');

        $caught = $this->underRefusedLimit($limit, static fn (): mixed => RegexParser::create(['cache' => null])->{$method}($pattern));

        $this->assertInstanceOf(InternalFailureException::class, $caught);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $caught->pcreError);
    }

    /**
     * @return iterable<string, array{pattern: string, limit: string, method: 'parse'|'parseTolerant'|'validate'}>
     */
    public static function provideStarvedPatterns(): iterable
    {
        $quoted = '/\Q'.str_repeat('a', 20000).'\E/';

        yield 'validate, a long quoted run' => ['pattern' => $quoted, 'limit' => '10000', 'method' => 'validate'];
        yield 'parse, a long quoted run' => ['pattern' => $quoted, 'limit' => '10000', 'method' => 'parse'];
        yield 'parseTolerant, a long quoted run' => ['pattern' => $quoted, 'limit' => '10000', 'method' => 'parseTolerant'];
        yield 'validate, no modifier' => ['pattern' => '/abc/', 'limit' => '1', 'method' => 'validate'];
        yield 'validate, a modifier' => ['pattern' => '/abc/i', 'limit' => '1', 'method' => 'validate'];
        yield 'parseTolerant, a modifier' => ['pattern' => '/abc/i', 'limit' => '1', 'method' => 'parseTolerant'];
    }

    /**
     * Runs the call under the backtrack limit given, with every raise
     * refused, and gives back what it threw.
     *
     * @param \Closure(): mixed $call
     */
    private function underRefusedLimit(string $limit, \Closure $call): ?\Throwable
    {
        $saved = (string) ini_get('pcre.backtrack_limit');
        LibraryPcre::useIniSetter(static fn (): bool => false);
        ini_set('pcre.backtrack_limit', $limit);

        try {
            $call();
        } catch (\Throwable $thrown) {
            return $thrown;
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
            LibraryPcre::useIniSetter(null);
        }

        return null;
    }
}
