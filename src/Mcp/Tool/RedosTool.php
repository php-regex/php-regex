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

use PHPRegex\Mcp\ToolResult;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * regex_redos: the ReDoS verdict on a pattern, the `redos` member of the
 * document `regex analyze --json` prints: severity, complexity, the
 * witness that pumps, the hotspots, and in confirmed mode the engine's own
 * replay of the witness under the library's caps.
 *
 * @internal
 */
final class RedosTool extends AbstractTool
{
    public function execute(array $arguments): ToolResult
    {
        $pattern = self::pattern($arguments);
        if ($pattern instanceof ToolResult) {
            return $pattern;
        }
        $mode = $arguments['mode'] ?? RedosMode::Theoretical->value;
        if (!\is_string($mode) || !\in_array($mode, [RedosMode::Theoretical->value, RedosMode::Confirmed->value], true)) {
            return ToolResult::failure('The "mode" argument must be theoretical or confirmed.', 'usage');
        }
        $threshold = null;
        if (null !== ($arguments['threshold'] ?? null)) {
            if (!\is_string($arguments['threshold'])) {
                return ToolResult::failure('The "threshold" argument must be low, medium, high or critical.', 'usage');
            }

            try {
                $threshold = RedosSeverity::fromConfig($arguments['threshold']);
            } catch (InvalidRegexOptionException $e) {
                return ToolResult::failure('Invalid value for "threshold": '.$e->getMessage(), 'usage');
            }
        }
        $judged = $this->regex($arguments);
        if ($judged instanceof ToolResult) {
            return $judged;
        }
        [$regex, $target] = $judged;
        $refusal = self::refusal($regex, $pattern);
        if (null !== $refusal) {
            return $refusal;
        }

        $analysis = $regex->redos($pattern, $threshold, RedosMode::from($mode));

        [$text, $range] = self::overRange(implode("\n", [$analysis->headline(), ...$analysis->recommendations]), $regex, $target, $pattern);

        return ToolResult::document($text, ['pattern' => $pattern, 'redos' => $analysis, ...$range]);
    }
}
