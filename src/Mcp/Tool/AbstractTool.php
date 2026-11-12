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

namespace PHPRegex\Mcp\Tool;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\RangeFindings;
use PHPRegex\Linter\RangePoints;
use PHPRegex\Mcp\Exception\TargetException;
use PHPRegex\Mcp\RegexFactory;
use PHPRegex\Mcp\ToolInterface;
use PHPRegex\Mcp\ToolResult;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;

/**
 * What every tool does first: read the pattern, build the facade for the
 * target the call names. Each step answers the failure the CLI would print
 * for the same mistake, so a tool reads its arguments once and runs. What
 * every tool adds last: the target judged, and the verdict of each version
 * of its range, as the CLI's documents carry them; and to its text, what
 * the later versions find in the pattern and what resolving the target
 * changed, in lint's words.
 *
 * @internal
 *
 * @phpstan-import-type RangePoint from RangePoints
 * @phpstan-import-type TargetDescription from ProjectTarget
 */
abstract class AbstractTool implements ToolInterface
{
    public function __construct(protected readonly RegexFactory $regexes) {}

    /**
     * The pattern argument, or the usage failure of a call without one.
     *
     * @param array<string, mixed> $arguments
     */
    protected static function pattern(array $arguments): string|ToolResult
    {
        $pattern = $arguments['pattern'] ?? null;
        if (!\is_string($pattern) || '' === $pattern) {
            return ToolResult::failure('The "pattern" argument is required: a delimited pattern, as /…/flags.', 'usage');
        }

        return $pattern;
    }

    /**
     * The facade judging for the floor of the call's target, and the
     * target, or the failure of a target the library does not judge for.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{Regex, ProjectTarget}|ToolResult
     */
    protected function regex(array $arguments): array|ToolResult
    {
        try {
            $regex = $this->regexes->for($arguments);

            return [$regex, $this->regexes->targetOf($regex)];
        } catch (TargetException $e) {
            return ToolResult::failure($e->getMessage(), $e->stage);
        }
    }

    /**
     * The text answer, then what the later versions of the range find in a
     * pattern the floor accepts and what resolving the target changed on
     * the way, a line each, in lint's words: an error for the lowest
     * version that refuses the pattern, else a warning for the lowest that
     * reads it into another tree, else for the first change of meaning
     * that keeps the tree; then a note per notice. None of them makes the
     * answer a failure of the tool: the floor accepts the pattern. Beside
     * it, the keys every document of a tool ends with: the target judged,
     * under $key, and the verdict of each version of its range on the
     * pattern, the floor first. The parsers of the later versions are
     * built once for both.
     *
     * @return array{string, array<string, array{php: string, pcre: string, locale: string, source: string, range: list<TargetDescription>}|non-empty-list<RangePoint>>}
     */
    protected static function overRange(string $text, Regex $regex, ProjectTarget $target, string $pattern, string $key = 'target'): array
    {
        $floor = $regex->parser();
        $later = $target->rangeParsers($floor);

        $lines = [$text];
        $finding = self::rangeFinding($floor, $later, $pattern);
        if (null !== $finding) {
            $lines[] = $finding;
        }
        foreach ($target->notices() as $notice) {
            $lines[] = 'Note: '.$notice;
        }

        return [implode("\n", $lines), [$key => $target->toArray(), 'range' => RangePoints::of($floor, $later, $pattern)]];
    }

    /**
     * The failure of a pattern the engine refuses, with its validation, or
     * null for a pattern that is valid for the target: the tools that act
     * on a pattern stop here, as transpile does, so that a semantic error
     * never comes out translated, explained or judged.
     */
    protected static function refusal(Regex $regex, string $pattern): ?ToolResult
    {
        $validation = $regex->validate($pattern);
        if ($validation->isValid) {
            return null;
        }

        return ToolResult::failure($validation->error ?? 'Invalid pattern.', 'pattern', $validation);
    }

    /**
     * What the later versions of the range find in the pattern, as
     * overRange() words it, or null: for a single version, a pattern the
     * floor refuses, or one every later version reads as the floor does.
     *
     * @param list<RegexParser> $later a parser for each version after the floor
     */
    private static function rangeFinding(RegexParser $floor, array $later, string $pattern): ?string
    {
        // A pattern the floor accepts comes with the tree it read.
        $ast = [] === $later ? null : $floor->validate($pattern)->ast;
        if (null === $ast) {
            return null;
        }

        $wording = RangeFindings::of($floor, $later, $pattern, $ast, true)->wording();
        if (null !== $wording['refusal']) {
            return 'Error: '.$wording['refusal'];
        }

        return null === $wording['warning'] ? null : 'Warning: '.$wording['warning'];
    }
}
