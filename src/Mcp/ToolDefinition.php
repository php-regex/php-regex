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
 * A tool as a client lists it: its name, what it does, the schema of its
 * arguments and of its answer, and the tool that answers. Every tool of
 * the catalog is read-only and idempotent: a client may call it without
 * asking.
 */
final class ToolDefinition
{
    /**
     * @var array{readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool}
     */
    public readonly array $annotations;

    /**
     * @param array<string, mixed> $inputSchema  JSON Schema of the arguments
     * @param array<string, mixed> $outputSchema JSON Schema of the structured answer, the error envelope included
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly array $outputSchema,
        public readonly ToolInterface $tool,
    ) {
        $this->annotations = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }
}
