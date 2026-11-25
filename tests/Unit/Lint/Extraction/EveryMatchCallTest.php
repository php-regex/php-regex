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
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The call a pattern runs in, as the cost of its search depends on it:
 * preg_match_all(), preg_replace(), preg_filter(), preg_replace_callback(),
 * preg_replace_callback_array() and preg_split() go on after each match, and
 * after an empty one PHP retries the same offset; preg_match() and
 * preg_grep() stop at the first match, and a literal limit stops the others
 * after that many. Each occurrence names the function whose loop it runs in,
 * or none, through both extraction strategies.
 *
 * The oracle runs that function: "(?:a+\d)?" matches the empty string at
 * each position of a run of "a", so the number of matches the call makes
 * grows with the run only where the call goes on.
 */
final class EveryMatchCallTest extends TestCase
{
    use TemporaryProject;

    private const PROBE = '/(?:a+\d)?/';

    /**
     * @param list<string> $presets
     */
    #[Test]
    #[DataProvider('provideCalls')]
    public function test_extraction_names_the_function_whose_loop_the_call_runs(string $call, string $function, ?int $limit, ?string $everyMatch, array $presets = []): void
    {
        $short = self::matchesMade($function, $limit, str_repeat('a', 1000));
        $long = self::matchesMade($function, $limit, str_repeat('a', 2000));
        $this->assertSame(null !== $everyMatch, $long > $short, \sprintf('Oracle: %s makes %d then %d matches.', $function, $short, $long));
        if (null !== $everyMatch) {
            $this->assertSame($function, $everyMatch);
        }

        $registry = [] === $presets ? PatternFunctionRegistry::native() : PatternFunctionRegistry::create($presets);
        $code = "<?php\n\$s = 'x';\n".$call."\n";
        $this->assertSame([['/p/', $everyMatch]], $this->readWith(new TokenBasedExtractionStrategy([], $registry), $code), 'The token strategy, on '.$call);
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', $everyMatch]], $this->readWith(new PhpParserExtractionStrategy([], $registry), $code), 'The parser strategy, on '.$call);
        }
    }

    /**
     * @return iterable<string, array{call: string, function: string, limit: int|null, everyMatch: string|null, presets?: list<string>}>
     */
    public static function provideCalls(): iterable
    {
        yield 'preg_match' => ['call' => "preg_match('/p/', \$s, \$m);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null];
        yield 'preg_match without matches' => ['call' => "preg_match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null];
        yield 'preg_grep' => ['call' => "preg_grep('/p/', [\$s]);", 'function' => 'preg_grep', 'limit' => null, 'everyMatch' => null];
        yield 'preg_match_all' => ['call' => "preg_match_all('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'preg_match_all with an offset' => ['call' => "preg_match_all('/p/', \$s, \$m, 0, 3);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'preg_replace' => ['call' => "preg_replace('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with no limit' => ['call' => "preg_replace('/p/', '', \$s, -1);", 'function' => 'preg_replace', 'limit' => -1, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit' => ['call' => "preg_replace('/p/', '', \$s, 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_replace with a limit of zero' => ['call' => "preg_replace('/p/', '', \$s, 0);", 'function' => 'preg_replace', 'limit' => 0, 'everyMatch' => null];
        yield 'preg_replace with a named limit' => ['call' => "preg_replace('/p/', '', limit: 2, subject: \$s);", 'function' => 'preg_replace', 'limit' => 2, 'everyMatch' => null];
        yield 'preg_replace with a limit it cannot read' => ['call' => "preg_replace('/p/', '', \$s, \$n);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit it computes' => ['call' => "preg_replace('/p/', '', \$s, 1 + \$n);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_replace with a limit under signs and parentheses' => ['call' => "preg_replace('/p/', '', \$s, (- -1));", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_replace with its arguments spread' => ['call' => "preg_replace('/p/', ...\$rest);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace'];
        yield 'preg_filter' => ['call' => "preg_filter('/p/', '', \$s);", 'function' => 'preg_filter', 'limit' => null, 'everyMatch' => 'preg_filter'];
        yield 'preg_replace_callback' => ['call' => "preg_replace_callback('/p/', static fn (\$m) => '', \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback'];
        yield 'preg_replace_callback with a limit' => ['call' => "preg_replace_callback('/p/', static fn (\$m) => '', \$s, 3);", 'function' => 'preg_replace_callback', 'limit' => 3, 'everyMatch' => null];
        yield 'preg_replace_callback_array' => ['call' => "preg_replace_callback_array(['/p/' => static fn (\$m) => ''], \$s);", 'function' => 'preg_replace_callback_array', 'limit' => null, 'everyMatch' => 'preg_replace_callback_array'];
        yield 'preg_replace_callback_array with a limit' => ['call' => "preg_replace_callback_array(['/p/' => static fn (\$m) => ''], \$s, 1);", 'function' => 'preg_replace_callback_array', 'limit' => 1, 'everyMatch' => null];
        yield 'preg_split' => ['call' => "preg_split('/p/', \$s);", 'function' => 'preg_split', 'limit' => null, 'everyMatch' => 'preg_split'];
        yield 'preg_split with a limit of zero' => ['call' => "preg_split('/p/', \$s, 0);", 'function' => 'preg_split', 'limit' => 0, 'everyMatch' => 'preg_split'];
        yield 'preg_split with no limit and a flag' => ['call' => "preg_split('/p/', \$s, -1, PREG_SPLIT_NO_EMPTY);", 'function' => 'preg_split', 'limit' => -1, 'everyMatch' => 'preg_split'];
        yield 'preg_split with a limit' => ['call' => "preg_split('/p/', \$s, 2);", 'function' => 'preg_split', 'limit' => 2, 'everyMatch' => null];
        yield 'preg_split with a negative limit' => ['call' => "preg_split('/p/', \$s, -2);", 'function' => 'preg_split', 'limit' => -2, 'everyMatch' => null];
        yield 'Safe drop-in' => ['call' => "\\Safe\\preg_match_all('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all'];
        yield 'composer/pcre matchAll' => ['call' => "\\Composer\\Pcre\\Preg::matchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre isMatchAll' => ['call' => "\\Composer\\Pcre\\Preg::isMatchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre match' => ['call' => "\\Composer\\Pcre\\Preg::match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre grep' => ['call' => "\\Composer\\Pcre\\Preg::grep('/p/', [\$s]);", 'function' => 'preg_grep', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replace' => ['call' => "\\Composer\\Pcre\\Preg::replace('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replace with a limit' => ['call' => "\\Composer\\Pcre\\Preg::replace('/p/', '', \$s, 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replaceCallback' => ['call' => "\\Composer\\Pcre\\Regex::replaceCallback('/p/', static fn (\$m) => '', \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre replaceCallbackArray' => ['call' => "\\Composer\\Pcre\\Preg::replaceCallbackArray(['/p/' => static fn (\$m) => ''], \$s);", 'function' => 'preg_replace_callback_array', 'limit' => null, 'everyMatch' => 'preg_replace_callback_array', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre split' => ['call' => "\\Composer\\Pcre\\Preg::split('/p/', \$s);", 'function' => 'preg_split', 'limit' => null, 'everyMatch' => 'preg_split', 'presets' => [InteropPresets::COMPOSER_PCRE]];
        yield 'composer/pcre split with a limit' => ['call' => "\\Composer\\Pcre\\Preg::splitWithOffsets('/p/', \$s, 2);", 'function' => 'preg_split', 'limit' => 2, 'everyMatch' => null, 'presets' => [InteropPresets::COMPOSER_PCRE]];

        // nette/utils 4.1, src/Utils/Strings.php: matchAll() calls
        // preg_match_all() unless lazy, where its generator calls
        // preg_match() from the end of each match, with no retry; split()
        // calls preg_split() and replace() preg_replace(), or
        // preg_replace_callback() for a callable, each with its $limit.
        yield 'nette/utils matchAll' => ['call' => "\\Nette\\Utils\\Strings::matchAll(\$s, '/p/');", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils matchAll not lazy' => ['call' => "\\Nette\\Utils\\Strings::matchAll(\$s, '/p/', lazy: false);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils matchAll lazy' => ['call' => "\\Nette\\Utils\\Strings::matchAll(\$s, '/p/', lazy: true);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils matchAll lazy by position' => ['call' => "\\Nette\\Utils\\Strings::matchAll(\$s, '/p/', false, 0, false, false, false, TRUE);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils split' => ['call' => "\\Nette\\Utils\\Strings::split(\$s, '/p/');", 'function' => 'preg_split', 'limit' => null, 'everyMatch' => 'preg_split', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils split with no limit' => ['call' => "\\Nette\\Utils\\Strings::split(\$s, '/p/', limit: -1);", 'function' => 'preg_split', 'limit' => -1, 'everyMatch' => 'preg_split', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils split with a limit' => ['call' => "\\Nette\\Utils\\Strings::split(\$s, '/p/', false, false, 2);", 'function' => 'preg_split', 'limit' => 2, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils replace' => ['call' => "\\Nette\\Utils\\Strings::replace(\$s, '/p/');", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils replace of a map' => ['call' => "\\Nette\\Utils\\Strings::replace(\$s, ['/p/' => '']);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils replace with a limit' => ['call' => "\\Nette\\Utils\\Strings::replace(\$s, '/p/', '', 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils replace with a callback' => ['call' => "\\Nette\\Utils\\Strings::replace(\$s, '/p/', static fn (\$m) => '');", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils replace with a callback and a limit' => ['call' => "\\Nette\\Utils\\Strings::replace(\$s, '/p/', static fn (\$m) => '', limit: 2);", 'function' => 'preg_replace_callback', 'limit' => 2, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];
        yield 'nette/utils match' => ['call' => "\\Nette\\Utils\\Strings::match(\$s, '/p/');", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::NETTE_UTILS]];

        // spatie/regex 3.1, src/Regex.php, MatchAllResult.php and
        // ReplaceResult.php: matchAll() calls preg_match_all(), replace()
        // preg_replace(), or preg_replace_callback() for a callable that is
        // no string, with its $limit.
        yield 'spatie/regex matchAll' => ['call' => "\\Spatie\\Regex\\Regex::matchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex replace' => ['call' => "\\Spatie\\Regex\\Regex::replace('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex replace with a limit' => ['call' => "\\Spatie\\Regex\\Regex::replace('/p/', '', \$s, 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null, 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex replace with a callback' => ['call' => "\\Spatie\\Regex\\Regex::replace('/p/', function (\$m) { return ''; }, \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex replace with Closure::fromCallable()' => ['call' => "\\Spatie\\Regex\\Regex::replace('/p/', \\Closure::fromCallable('strval'), \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex replace with a callable string' => ['call' => "\\Spatie\\Regex\\Regex::replace('/p/', 'strval', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::SPATIE_REGEX]];
        yield 'spatie/regex match' => ['call' => "\\Spatie\\Regex\\Regex::match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::SPATIE_REGEX]];

        // laravel/framework 13.44, src/Illuminate/Support/Str.php:
        // matchAll() calls preg_match_all(), replaceMatches() preg_replace(),
        // or preg_replace_callback() for a Closure, with its $limit.
        yield 'illuminate/support matchAll' => ['call' => "\\Illuminate\\Support\\Str::matchAll('/p/', \$s);", 'function' => 'preg_match_all', 'limit' => null, 'everyMatch' => 'preg_match_all', 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support replaceMatches' => ['call' => "\\Illuminate\\Support\\Str::replaceMatches('/p/', '', \$s);", 'function' => 'preg_replace', 'limit' => null, 'everyMatch' => 'preg_replace', 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support replaceMatches with a named limit' => ['call' => "\\Illuminate\\Support\\Str::replaceMatches('/p/', '', \$s, limit: 1);", 'function' => 'preg_replace', 'limit' => 1, 'everyMatch' => null, 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support replaceMatches with a callback' => ['call' => "\\Illuminate\\Support\\Str::replaceMatches('/p/', static fn (array \$m): string => '', \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support replaceMatches with a first-class callable' => ['call' => "\\Illuminate\\Support\\Str::replaceMatches(replace: \$this->replace(...), pattern: '/p/', subject: \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support replaceMatches with a closure in parentheses' => ['call' => "\\Illuminate\\Support\\Str::replaceMatches('/p/', (function (array \$m): string { return ''; }), \$s);", 'function' => 'preg_replace_callback', 'limit' => null, 'everyMatch' => 'preg_replace_callback', 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support isMatch' => ['call' => "\\Illuminate\\Support\\Str::isMatch('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
        yield 'illuminate/support match' => ['call' => "\\Illuminate\\Support\\Str::match('/p/', \$s);", 'function' => 'preg_match', 'limit' => null, 'everyMatch' => null, 'presets' => [InteropPresets::ILLUMINATE_SUPPORT]];
    }

    /**
     * RegexIterator::accept() runs the loop of preg_match_all() under
     * ALL_MATCHES, of preg_split() under SPLIT and of preg_replace() under
     * REPLACE, each without a limit (ext/spl/spl_iterators.c); MATCH, the
     * default, and GET_MATCH stop at the first match. The mode is read from
     * the constructor, written as a constant of the class or an integer.
     *
     * The oracle runs the iterator in that mode: on a longer run it makes
     * more matches only where it loops, and then as many as the function
     * named.
     */
    /**
     * @param int<0, 4> $mode
     */
    #[Test]
    #[DataProvider('provideIterators')]
    public function test_extraction_names_the_loop_of_a_regex_iterator_mode(string $call, string $class, int $mode, ?string $everyMatch): void
    {
        $short = self::iteratorMatches($class, $mode, str_repeat('a', 1000));
        $long = self::iteratorMatches($class, $mode, str_repeat('a', 2000));
        $this->assertSame(null !== $everyMatch, $long > $short, \sprintf('Oracle: %s in mode %d makes %d then %d matches.', $class, $mode, $short, $long));
        if (null !== $everyMatch) {
            $this->assertSame(self::matchesMade($everyMatch, null, str_repeat('a', 2000)), $long, 'Oracle: the mode loops as '.$everyMatch.'() does.');
        }

        $code = "<?php\n\$s = 'x';\n\$it = new \\RecursiveArrayIterator([\$s]);\n".$call."\n";
        $this->assertSame([['/p/', $everyMatch]], $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The token strategy, on '.$call);
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', $everyMatch]], $this->readWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code), 'The parser strategy, on '.$call);
        }
    }

    /**
     * @return iterable<string, array{call: string, class: string, mode: int<0, 4>, everyMatch: string|null}>
     */
    public static function provideIterators(): iterable
    {
        yield 'MATCH by default' => ['call' => "new RegexIterator(\$it, '/p/');", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::MATCH, 'everyMatch' => null];
        yield 'MATCH' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::MATCH);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::MATCH, 'everyMatch' => null];
        yield 'GET_MATCH' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::GET_MATCH);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::GET_MATCH, 'everyMatch' => null];
        yield 'ALL_MATCHES' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::ALL_MATCHES);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::ALL_MATCHES, 'everyMatch' => 'preg_match_all'];
        yield 'SPLIT, fully qualified' => ['call' => "new \\RegexIterator(\$it, '/p/', \\RegexIterator::SPLIT);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::SPLIT, 'everyMatch' => 'preg_split'];
        yield 'REPLACE' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::REPLACE);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::REPLACE, 'everyMatch' => 'preg_replace'];
        yield 'ALL_MATCHES as an integer' => ['call' => "new RegexIterator(\$it, '/p/', 2);", 'class' => \RegexIterator::class, 'mode' => 2, 'everyMatch' => 'preg_match_all'];
        yield 'ALL_MATCHES by name, in parentheses' => ['call' => "new RegexIterator(\$it, '/p/', mode: (RegexIterator::ALL_MATCHES));", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::ALL_MATCHES, 'everyMatch' => 'preg_match_all'];
        yield 'a mode the subclass inherits' => ['call' => "new RegexIterator(\$it, '/p/', RecursiveRegexIterator::SPLIT);", 'class' => \RegexIterator::class, 'mode' => \RegexIterator::SPLIT, 'everyMatch' => 'preg_split'];
        yield 'RecursiveRegexIterator ALL_MATCHES' => ['call' => "new RecursiveRegexIterator(\$it, '/p/', RecursiveRegexIterator::ALL_MATCHES);", 'class' => \RecursiveRegexIterator::class, 'mode' => \RecursiveRegexIterator::ALL_MATCHES, 'everyMatch' => 'preg_match_all'];
        yield 'RecursiveRegexIterator GET_MATCH' => ['call' => "new RecursiveRegexIterator(\$it, '/p/', RecursiveRegexIterator::GET_MATCH);", 'class' => \RecursiveRegexIterator::class, 'mode' => \RecursiveRegexIterator::GET_MATCH, 'everyMatch' => null];
    }

    /**
     * An argument that decides whether the call loops, and that the call
     * does not spell out, leaves it as one that stops at the first match:
     * only a call sure to loop names its loop.
     *
     * @param list<string> $presets
     */
    #[Test]
    #[DataProvider('provideUndecidedCalls')]
    public function test_extraction_names_no_loop_an_unread_argument_decides(string $call, array $presets = []): void
    {
        $registry = [] === $presets ? PatternFunctionRegistry::native() : PatternFunctionRegistry::create($presets);
        $code = "<?php\n".$call."\n";
        $this->assertSame([['/p/', null]], $this->readWith(new TokenBasedExtractionStrategy([], $registry), $code), 'The token strategy, on '.$call);
        if (class_exists(ParserFactory::class)) {
            $this->assertSame([['/p/', null]], $this->readWith(new PhpParserExtractionStrategy([], $registry), $code), 'The parser strategy, on '.$call);
        }
    }

    /**
     * @return iterable<string, array{call: string, presets?: list<string>}>
     */
    public static function provideUndecidedCalls(): iterable
    {
        yield 'a mode in a variable' => ['call' => "new RegexIterator(\$it, '/p/', \$mode);"];
        yield 'a mode a spread may hold' => ['call' => "new RegexIterator(\$it, '/p/', ...\$rest);"];
        yield 'a mode computed' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::ALL_MATCHES | 0);"];
        yield 'a constant of another class' => ['call' => "new RegexIterator(\$it, '/p/', Modes::ALL_MATCHES);"];
        yield 'a namespace\'s class of the same name' => ['call' => "namespace App;\nnew \\RegexIterator(\$it, '/p/', RegexIterator::ALL_MATCHES);"];
        yield 'a constant RegexIterator does not declare' => ['call' => "new RegexIterator(\$it, '/p/', RegexIterator::EVERY_MATCH);"];
        yield 'lazy in a variable' => ['call' => "\\Nette\\Utils\\Strings::matchAll(\$s, '/p/', lazy: \$lazy);", 'presets' => [InteropPresets::NETTE_UTILS]];
    }

    /**
     * Each pattern of a list runs in the loop of the call.
     */
    #[Test]
    public function test_extraction_names_the_loop_of_each_pattern_of_a_list(): void
    {
        $this->assertGreaterThan(self::matchesMade('preg_replace', null, str_repeat('a', 1000)), self::matchesMade('preg_replace', null, str_repeat('a', 2000)), 'Oracle: preg_replace() stops early.');

        $code = "<?php\n\$s = 'x';\npreg_replace(['/p/', '/q/'], '', \$s);\n";
        $expected = [['/p/', 'preg_replace'], ['/q/', 'preg_replace']];
        $this->assertSame($expected, $this->readWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $code));
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($expected, $this->readWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $code));
        }
    }

    /**
     * An occurrence a custom extractor builds names no call: its search
     * keeps the verdict of preg_match().
     */
    #[Test]
    public function test_an_occurrence_found_by_a_custom_extractor_names_no_loop(): void
    {
        $this->assertNull(PatternOccurrence::found('/p/', 'file.php', 1)->everyMatch);
    }

    /**
     * The copies of an occurrence keep the call it runs in.
     */
    #[Test]
    public function test_the_copies_of_an_occurrence_keep_its_loop(): void
    {
        $occurrence = new PatternOccurrence('/p/', 'file.php', 1, 'preg_split()', everyMatch: 'preg_split');

        $this->assertSame('preg_split', $occurrence->ignoredWhole()->everyMatch);
        $this->assertSame('preg_split', $occurrence->analysed()->everyMatch);
    }

    /**
     * How many matches the function makes with the probe on the subject,
     * under the limit when one is given: the oracle of the loop.
     */
    private static function matchesMade(string $function, ?int $limit, string $subject): int
    {
        $count = 0;
        $replace = static fn (array $matches): string => '';
        $made = match ($function) {
            'preg_match' => preg_match(self::PROBE, $subject),
            'preg_grep' => preg_grep(self::PROBE, [$subject]),
            'preg_match_all' => preg_match_all(self::PROBE, $subject),
            'preg_replace' => null === preg_replace(self::PROBE, '', $subject, $limit ?? -1, $count) ? false : $count,
            'preg_filter' => null === preg_filter(self::PROBE, '', $subject, $limit ?? -1, $count) ? false : $count,
            'preg_replace_callback' => null === preg_replace_callback(self::PROBE, $replace, $subject, $limit ?? -1, $count) ? false : $count,
            'preg_replace_callback_array' => null === preg_replace_callback_array([self::PROBE => $replace], $subject, $limit ?? -1, $count) ? false : $count,
            'preg_split' => preg_split(self::PROBE, $subject, $limit ?? -1),
            default => false,
        };
        // The pieces of preg_split() are one more than its matches.
        if (\is_array($made)) {
            $made = \count($made) - ('preg_split' === $function ? 1 : 0);
        }
        self::assertIsInt($made, 'Oracle: '.$function.' failed.');

        return $made;
    }

    /**
     * How many matches the iterator makes with the probe on the subject in
     * the mode: those of the match array, the pieces past the first, the
     * replacements, or one for a mode that stops at the first match.
     *
     * @param int<0, 4> $mode
     */
    private static function iteratorMatches(string $class, int $mode, string $subject): int
    {
        $iterator = \RecursiveRegexIterator::class === $class
            ? new \RecursiveRegexIterator(new \RecursiveArrayIterator([$subject]), self::PROBE, $mode)
            : new \RegexIterator(new \ArrayIterator([$subject]), self::PROBE, $mode);
        $iterator->replacement = '#';
        $current = null;
        foreach ($iterator as $value) {
            $current = $value;
        }

        return match ($mode) {
            \RegexIterator::ALL_MATCHES => \is_array($current) && isset($current[0]) && \is_array($current[0]) ? \count($current[0]) : 0,
            \RegexIterator::SPLIT => \is_array($current) ? \count($current) - 1 : 0,
            \RegexIterator::REPLACE => \is_string($current) ? substr_count($current, '#') : 0,
            default => null === $current ? 0 : 1,
        };
    }

    /**
     * The pattern and the loop of each occurrence the strategy reads.
     *
     * @return list<array{string, string|null}>
     */
    private function readWith(ExtractorInterface $strategy, string $code): array
    {
        $directory = $this->makeProject(['subject.php' => $code]);
        $read = [];
        foreach ($strategy->extract([$directory.'/subject.php']) as $occurrence) {
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $read[] = [$occurrence->pattern, $occurrence->everyMatch];
        }

        return $read;
    }
}
