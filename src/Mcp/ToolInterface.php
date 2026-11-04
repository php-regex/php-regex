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

namespace PHPRegex\Mcp;

/**
 * A tool of the catalog: one read-only question about a pattern, answered
 * from an array of arguments. No MCP type reaches a tool, so a tool runs
 * and is tested without the SDK.
 */
interface ToolInterface
{
    /**
     * @param array<string, mixed> $arguments the call's arguments, as the client sent them
     */
    public function execute(array $arguments): ToolResult;
}
