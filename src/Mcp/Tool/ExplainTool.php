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

/**
 * regex_explain: the pattern in plain language, the document
 * `regex explain --json` prints.
 *
 * @internal
 */
final class ExplainTool extends AbstractTool
{
    public function execute(array $arguments): ToolResult
    {
        $pattern = self::pattern($arguments);
        if ($pattern instanceof ToolResult) {
            return $pattern;
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

        // A pattern the target compiles is one the explainer reads.
        $explanation = $regex->explain($pattern);

        return ToolResult::document(self::withRange($explanation, $regex, $target, $pattern), ['pattern' => $pattern, 'explanation' => $explanation, ...self::range($regex, $target, $pattern)]);
    }
}
