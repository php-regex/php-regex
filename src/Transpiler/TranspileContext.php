<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Transpiler;

/**
 * Tracks state and diagnostics during transpilation.
 *
 * @internal
 */
final class TranspileContext
{
    /**
     * @var array<string, string>
     */
    private array $requiredFlags = [];

    /**
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * @var array<int, string>
     */
    private array $notes = [];

    public function __construct(
        public string $sourcePattern,
        public string $sourceFlags,
        public TranspileOptions $options,
    ) {}

    public function requireFlag(string $flag, string $reason): void
    {
        $this->requiredFlags[$flag] ??= $reason;
    }

    public function requiresFlag(string $flag): bool
    {
        return isset($this->requiredFlags[$flag]);
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredFlags(): array
    {
        return $this->requiredFlags;
    }

    public function addWarning(string $message): void
    {
        if (!\in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    public function addNote(string $message): void
    {
        if (!\in_array($message, $this->notes, true)) {
            $this->notes[] = $message;
        }
    }

    /**
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
