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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * analyze() reports what the pattern causes. A regex of the library that
 * gives up under PCRE limits PHP would not raise is no such thing: it
 * surfaces, as it does from validate(), never as an invalid pattern.
 *
 * The verdict of validate() is kept for the parser, so the pattern is
 * validated first under the default limits; the parse of analyze() then
 * runs under a backtrack limit of 1, the JIT off, every raise refused.
 */
final class AnalyzeLibraryFailureTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_library_regex_giving_up_is_no_invalid_pattern(): void
    {
        ini_set('pcre.jit', '0');
        $regex = Regex::create(['cache' => null]);
        $this->assertTrue($regex->validate('/(a|b)+c/')->isValid);

        $saved = (string) ini_get('pcre.backtrack_limit');
        LibraryPcre::useIniSetter(static fn (): bool => false);
        ini_set('pcre.backtrack_limit', '1');
        $caught = null;

        try {
            $regex->analyze('/(a|b)+c/');
        } catch (InternalFailureException $exception) {
            $caught = $exception;
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
            LibraryPcre::useIniSetter(null);
        }

        $this->assertInstanceOf(InternalFailureException::class, $caught);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $caught->pcreError);
    }
}
