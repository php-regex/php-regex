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

namespace PHPRegex\Redos;

use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Redos\Backtrack\LastCodeUnit;
use PHPRegex\Redos\Backtrack\WitnessReplayer;

/**
 * Runs the pattern on inputs growing in length, through the engine: without
 * the JIT, a pattern holding "(*SKIP:NAME)" aside, which runs under the JIT,
 * under the limits of the options, the ini left as it was found.
 *
 * An exponential verdict with a witness is replayed: the witness is built
 * with one pump, then two, and so on up to 64, until preg_match() gives up
 * at the backtrack limit of the options, within a fixed budget of work. The
 * failing sample is the shortest build that gives up there: the same input
 * does on every run, whatever the machine.
 *
 * Where the engine cannot set the limits (ini_set() disabled or refusing
 * one), nothing is run: the confirmation is not confirmed, holds no sample, and its evidence
 * says the engine limits are unavailable.
 */
final class ConfirmationRunner implements ConfirmationRunnerInterface
{
    /**
     * The engine runs every pattern without the JIT, a pattern holding
     * "(*SKIP:NAME)" aside: this is the setting the confirmation reports,
     * whatever the ini says and whether or not the options asked for it.
     */
    private const JIT_SETTING = '0';

    /**
     * The setting the confirmation of a pattern holding "(*SKIP:NAME)"
     * reports: it runs under the JIT.
     */
    private const SKIP_TO_A_NAME_JIT_SETTING = '1';

    /**
     * @param string|null $locale the LC_CTYPE the pattern runs under, as RedosAnalyzer runs it under its
     *                            target's; null for the process's
     */
    public function __construct(private readonly PcreEngine $engine = new PcreEngine(), private readonly ?string $locale = null) {}

    /**
     * @throws InvalidRegexOptionException when the machine cannot set the locale
     */
    public function confirm(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options = null): Confirmation
    {
        if (null === $this->locale) {
            return $this->run($regex, $analysis, $options);
        }

        $confirmation = null;
        CharacterTables::under($this->locale, function () use ($regex, $analysis, $options, &$confirmation): void {
            $confirmation = $this->run($regex, $analysis, $options);
        });

        if (!$confirmation instanceof Confirmation) {
            throw InternalFailureException::unexpectedState('The confirmation ran under its locale without a result.'); // Unreachable: under() runs the read or throws.
        }

        return $confirmation;
    }

    private function run(string $regex, RedosAnalysis $analysis, ?ConfirmationOptions $options): Confirmation
    {
        $options ??= new ConfirmationOptions();
        $skipped = WitnessReplayer::skipped($options);
        if (null !== $skipped) {
            // Reached only where ini_set() is disabled; the tests run that case in a child PHP process.
            return $skipped;
        }

        $limits = new PcreLimits($options->backtrackLimit, $options->recursionLimit);

        if (null !== $analysis->witness && RedosComplexity::Exponential === $analysis->complexity) {
            return $this->replay($regex, $analysis->witness, $options);
        }

        [$baseChar, $suffixChar, $baseLength] = $this->resolveBaseInput($regex, $analysis, $options);
        $lengths = $this->buildLengths($baseLength, $options);
        $tree = self::tree($regex);
        $required = null === $tree ? null : self::requiredCharacter($tree);
        $underTheJit = null !== $tree && self::skipsToAName($tree->pattern);
        $engine = $underTheJit ? PcreEngine::withJit() : $this->engine;

        $samples = [];
        $confirmed = false;
        $timedOut = false;
        $evidence = null;

        foreach ($lengths as $length) {
            $input = $this->buildInput($baseChar, $suffixChar, $length, $required);
            $preview = $options->previewLength > 0 ? substr($input, 0, $options->previewLength) : null;

            $durationMs = 0.0;
            $iterationsRun = 0;
            $pregErrorCode = null;
            $pregError = null;

            for ($i = 0; $i < $options->iterations; $i++) {
                $iterationsRun++;
                $start = hrtime(true);
                $match = $engine->match($regex, $input, $limits);
                $elapsed = (hrtime(true) - $start) / 1_000_000;
                if (PcreEngine::limitsWereNotSet($match)) {
                    return WitnessReplayer::unavailable($options);
                }
                $durationMs += $elapsed;

                $errorCode = $match->errorCode;
                if (\PREG_NO_ERROR !== $errorCode) {
                    $pregErrorCode = $errorCode;
                    $pregError = $match->error;
                }

                $evidenceForError = $this->evidenceForError($errorCode);
                if (null !== $evidenceForError) {
                    $confirmed = true;
                    $evidence = $evidenceForError;

                    break;
                }

                if (($durationMs / $iterationsRun) > $options->timeoutMs) {
                    $timedOut = true;

                    break;
                }
            }

            $averageMs = $iterationsRun > 0 ? $durationMs / $iterationsRun : 0.0;
            $samples[] = new ConfirmationSample(
                $length,
                $averageMs,
                $preview,
                $pregErrorCode,
                $pregError,
            );

            if ($confirmed || $timedOut) {
                break;
            }
        }

        return new Confirmation(
            $confirmed,
            $samples,
            $underTheJit ? self::SKIP_TO_A_NAME_JIT_SETTING : self::JIT_SETTING,
            $options->backtrackLimit,
            $options->recursionLimit,
            $options->iterations,
            $options->timeoutMs,
            $timedOut,
            $evidence,
            null,
            null,
        );
    }

    /**
     * The shortest build of the witness that makes the engine give up, or
     * the longest one tried when none does.
     */
    private function replay(string $regex, RedosWitness $witness, ConfirmationOptions $options): Confirmation
    {
        return (new WitnessReplayer($this->engine))->replay($regex, [$witness], $options, false)[0];
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function resolveBaseInput(string $regex, RedosAnalysis $analysis, ConfirmationOptions $options): array
    {
        try {
            $patternInfo = DelimitedPattern::fromDelimited($regex);
            $flags = $patternInfo->flags;
        } catch (ParserException) {
            $flags = '';
        }

        $baseInput = null;
        $culprit = $analysis->culpritNode;
        if (null !== $culprit) {
            $baseInput = (new InputGenerator())->generate($culprit, $flags, $analysis->severity);
        }

        if (null === $baseInput || '' === $baseInput) {
            $baseInput = 'a!';
        }

        $baseChar = $baseInput[0];
        $suffixChar = $baseInput[-1];

        $length = max(\strlen($baseInput), $options->minInputLength);

        return [$baseChar, $suffixChar, $length];
    }

    /**
     * @return array<int>
     */
    private function buildLengths(int $baseLength, ConfirmationOptions $options): array
    {
        $lengths = [];
        $length = min($baseLength, $options->maxInputLength);
        for ($i = 0; $i < $options->steps; $i++) {
            $lengths[] = $length;
            if ($length >= $options->maxInputLength) {
                break;
            }
            $length = min($length * 2, $options->maxInputLength);
            if (\in_array($length, $lengths, true)) {
                break;
            }
        }

        return $lengths;
    }

    /**
     * The base character repeated, then the one that fails the match, then
     * the character every match requires when the rest does not hold it:
     * PCRE2 rejects a subject without it before it tries the pattern.
     */
    private function buildInput(string $baseChar, string $suffixChar, int $length, ?string $required = null): string
    {
        $tail = $suffixChar;
        if (null !== $required && $required !== $baseChar && $required !== $suffixChar) {
            $tail .= $required;
        }

        return str_repeat($baseChar, max(1, $length - \strlen($tail))).$tail;
    }

    /**
     * The tree of the pattern, or null when it does not parse.
     */
    private static function tree(string $regex): ?RegexNode
    {
        try {
            return RegexParser::create()->parse($regex);
        } catch (ExceptionInterface) {
            return null;
        }
    }

    /**
     * The character PCRE2 requires in every subject it tries the pattern
     * on, as the pattern encodes it; null when it requires none.
     */
    private static function requiredCharacter(RegexNode $tree): ?string
    {
        $character = LastCodeUnit::of($tree);

        return null === $character ? null : Utf8::character($character, $tree->isUnicode());
    }

    /**
     * Whether the pattern holds a "(*SKIP:NAME)".
     */
    private static function skipsToAName(NodeInterface $node): bool
    {
        if ($node instanceof PcreVerbNode && 'SKIP' === Ascii::upper($node->name) && null !== $node->argument) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::skipsToAName($child)) {
                return true;
            }
        }

        return false;
    }

    private function evidenceForError(int $errorCode): ?string
    {
        if (\PREG_BACKTRACK_LIMIT_ERROR === $errorCode) {
            return 'backtrack_limit';
        }

        if (\PREG_RECURSION_LIMIT_ERROR === $errorCode) {
            return 'recursion_limit';
        }

        if (\defined('PREG_JIT_STACKLIMIT_ERROR') && \PREG_JIT_STACKLIMIT_ERROR === $errorCode) {
            return 'jit_stack_limit';
        }

        return null;
    }
}
