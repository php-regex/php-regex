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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under m, "^" holds at the subject's start and after a newline something
 * follows, never after a newline that ends the subject: preg_match() gives
 * 0 for "/\n^/m" on "a\n" and 1 on "a\nb". A success counted there is one
 * the engine never reaches, and a loop that fails on every way through it
 * would be proven linear.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, the interpreter, pcre.backtrack_limit
 * 1000000. The same holds under D and u, and with "(*LF)" or a "(*BSR_...)"
 * option, which leave "^" alone. Under another newline convention "^"
 * holds after that convention's newline, and never at the end either: the
 * model leaves those conventions out, and the verdict is heuristic.
 */
final class RedosMultilineCaretSoundnessTest extends TestCase
{
    private string|false $backtrackLimit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
    }

    #[Test]
    #[DataProvider('provideCaretsAfterTheFinalNewline')]
    public function test_redos_multiline_caret_after_the_final_newline_is_no_success(string $pattern, string $subject, ?RedosComplexity $complexity): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern($pattern), $subject), $pattern);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'", but one call on '.json_encode($subject).' exhausts the backtrack limit');
        $this->assertSame(null === $complexity ? RedosProof::Heuristic : RedosProof::Proven, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertSame($complexity ?? RedosComplexity::Unknown, $analysis->complexity, $pattern.' is "'.$analysis->headline().'"');
    }

    /**
     * The class each row is proven in; none outside the model.
     *
     * @return iterable<string, array{pattern: string, subject: string, complexity: RedosComplexity|null}>
     */
    public static function provideCaretsAfterTheFinalNewline(): iterable
    {
        $lines = str_repeat("\n", 20);

        yield 'the first case found' => ['pattern' => '/\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'a lazy loop under u' => ['pattern' => '/(?:\n|\n)+?(?:^\z|.)/mu', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'under u' => ['pattern' => '/\A(?:\n|\n)+(?:^\z|[^\n])/mu', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'under D' => ['pattern' => '/\A(?:\n|\n)+(?:^\z|[^\n])/mD', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'm set inline' => ['pattern' => '/\A(?:\n|\n)+(?:(?m)^\z|[^\n])/', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'the LF convention written out' => ['pattern' => '/(*LF)\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'a line break option, which leaves "^" alone' => ['pattern' => '/(*BSR_ANYCRLF)\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'the Unicode line break option' => ['pattern' => '/(*BSR_UNICODE)\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'a newline read before the caret' => ['pattern' => '/\A(?:\n|\n)+\n(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'the caret outside the alternation' => ['pattern' => '/\A(?:\n|\n)+\n^(?:\z|.)/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'one newline or two' => ['pattern' => '/^(?:\n|\n\n)+(?:^\z|.)/m', 'subject' => str_repeat("\n", 32), 'complexity' => RedosComplexity::Exponential];
        yield 'two runs, quadratic' => ['pattern' => '/\A\n*\n*\n^(?:\z|.)/m', 'subject' => str_repeat("\n", 1000), 'complexity' => RedosComplexity::Polynomial];
        yield 'NOTEMPTY under D' => ['pattern' => '/(*NOTEMPTY)(?:\W|\W)*(?:^\z|.)/mD', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        yield 'a counted repeat then an optional newline' => ['pattern' => '/(?:\s|\s){1,}(?:^\z|.)\n?/m', 'subject' => $lines, 'complexity' => RedosComplexity::Exponential];
        // Outside the model: heuristic, never proven.
        yield 'a carriage return under (*CR)' => ['pattern' => '/(*CR)\A(?:\r|\r)+(?:^\z|[^\r])/m', 'subject' => str_repeat("\r", 20), 'complexity' => null];
        yield 'a CRLF pair under (*CRLF)' => ['pattern' => '/(*CRLF)\A(?:\r\n|\r\n)+(?:^\z|[^\r\n])/m', 'subject' => str_repeat("\r\n", 20), 'complexity' => null];
        yield 'a line feed under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => null];
        yield 'a carriage return under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)\A(?:\r|\r)+(?:^\z|[^\r])/m', 'subject' => str_repeat("\r", 20), 'complexity' => null];
        yield 'a line feed under (*ANY)' => ['pattern' => '/(*ANY)\A(?:\n|\n)+(?:^\z|[^\n])/m', 'subject' => $lines, 'complexity' => null];
        yield 'a vertical tab under (*ANY)' => ['pattern' => '/(*ANY)\A(?:\x0b|\x0b)+(?:^\z|[^\x0b])/m', 'subject' => str_repeat("\x0b", 20), 'complexity' => null];
        yield 'a NUL under (*NUL)' => ['pattern' => '/(*NUL)\A(?:\x00|\x00)+(?:^\z|[^\x00])/m', 'subject' => str_repeat("\0", 20), 'complexity' => null];
    }

    /**
     * Where something follows the newline, or another branch holds at the
     * end, the success is the engine's too: the run stays linear, and no
     * proof may call it worse. A loop whose "^" holds only before one
     * more character fails at the end of the subject, but whatever follows
     * the newline it read, the success is sure: the engine meets the end
     * only on the last newline, gives it back, and succeeds before it. Two
     * runs or three in a row are linear for the same reason, each newline
     * the last run reads with one more after it a success.
     */
    #[Test]
    #[DataProvider('provideReachableSuccesses')]
    public function test_redos_multiline_caret_before_a_character_keeps_its_verdict(string $pattern, bool $provenSafe): void
    {
        $counted = PcreAttemptSteps::counted($pattern);
        $short = PcreAttemptSteps::steps($counted, str_repeat("\n", 16));
        $long = PcreAttemptSteps::steps($counted, str_repeat("\n", 32));
        $this->assertLessThan(3 * $short, $long, \sprintf('%s: %d steps on 16 newlines, %d on 32', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse(RedosProof::Proven === $analysis->proof && RedosComplexity::Linear !== $analysis->complexity, $pattern.' is "'.$analysis->headline().'"');
        if ($provenSafe) {
            $this->assertTrue($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'"');
        }
    }

    /**
     * @return iterable<string, array{pattern: string, provenSafe: bool}>
     */
    public static function provideReachableSuccesses(): iterable
    {
        yield 'the end without the caret' => ['pattern' => '/\A(?:\n|\n)+(?:\z|[^\n])/m', 'provenSafe' => true];
        yield 'a newline then the end without the caret' => ['pattern' => '/\A(?:\n|\n)+\n(?:\z|[^\n])/m', 'provenSafe' => false];
        yield 'the caret before the newline given back' => ['pattern' => '/\A(?:\n|\n)+^/m', 'provenSafe' => true];
        yield 'the caret or a letter' => ['pattern' => '/\A(?:\n|\n)+(?:^|x)/m', 'provenSafe' => true];
        yield 'the caret before a character, or a dollar' => ['pattern' => '/\A(?:\n|\n)+(?:^.|$)/m', 'provenSafe' => true];
        yield 'the caret before a character, or the end' => ['pattern' => '/\A(?:\n|\n)+(?:^[^\n]|\z)/m', 'provenSafe' => true];
        // D leaves "$" under m alone: it holds before the next newline.
        yield 'an empty line under D' => ['pattern' => '/\A(?:\n|\n)+(?:^$|[^\n])/mD', 'provenSafe' => true];
        yield 'a lazy loop before the caret' => ['pattern' => '/\A(?:\n|\n)+?^/m', 'provenSafe' => true];
        yield 'two runs before the caret' => ['pattern' => '/\A\n*\n*^/m', 'provenSafe' => true];
        yield 'three runs before the caret' => ['pattern' => '/\A\n*\n*\n*^/m', 'provenSafe' => true];
        yield 'two runs before the caret or a letter' => ['pattern' => '/\A\n*\n*(?:^|x)/m', 'provenSafe' => true];
        yield 'a run of spaces, then of newlines, before the caret' => ['pattern' => '/\A\s*\n*^/m', 'provenSafe' => true];
    }
}
