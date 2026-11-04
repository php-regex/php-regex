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

use PHPRegex\Mcp\Exception\TargetException;
use PHPRegex\Mcp\RegexFactory;
use PHPRegex\Mcp\ToolInterface;
use PHPRegex\Mcp\ToolResult;
use PHPRegex\Toolkit\Regex;

/**
 * What every tool does first: read the pattern, build the facade for the
 * target the call names. Each step answers the failure the CLI would print
 * for the same mistake, so a tool reads its arguments once and runs.
 *
 * @internal
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
     * The facade judging for the call's target, or the failure of a target
     * the library does not judge for.
     *
     * @param array<string, mixed> $arguments
     */
    protected function regex(array $arguments): Regex|ToolResult
    {
        try {
            return $this->regexes->for($arguments);
        } catch (TargetException $e) {
            return ToolResult::failure($e->getMessage(), $e->stage);
        }
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
}
