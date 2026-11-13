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

namespace PHPRegex\Transpiler;

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
    private array $lastWarnings = [];

    /**
     * @var array<int, string>
     */
    private array $notes = [];

    /**
     * @param bool                       $scriptsAlone whether a bare script name, "\p{Han}", is the
     *                                                 script alone, as before PCRE2 10.40, rather
     *                                                 than its script extensions
     * @param string|null                $sourceBody   the body of the pattern, without its delimiters
     *                                                 and flags, which the positions of a refusal count in
     * @param (\Closure(): ?string)|null $underU       the message of the refusal the target gives the
     *                                                 pattern with u added, null when it carries it
     */
    public function __construct(
        public string $sourcePattern,
        public string $sourceFlags,
        public TranspileOptions $options,
        public bool $scriptsAlone = false,
        public ?string $sourceBody = null,
        private readonly ?\Closure $underU = null,
    ) {}

    /**
     * Why the target refuses the pattern with u added, or null when it
     * carries it there, or when nothing can tell.
     */
    public function refusalUnderU(): ?string
    {
        return null === $this->underU ? null : ($this->underU)();
    }

    /**
     * The text a refusal's snippet shows: the body of the pattern, where
     * its position counts, as a parse error's snippet shows it.
     */
    public function snippetSource(): string
    {
        return $this->sourceBody ?? $this->sourcePattern;
    }

    public function requireFlag(string $flag, string $reason): void
    {
        $this->requiredFlags[$flag] ??= $reason;
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

    /**
     * A warning on the pattern as a whole, listed after those on what was
     * rewritten, whenever they are added.
     */
    public function addLastWarning(string $message): void
    {
        $this->lastWarnings[] = $message;
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
        return [...$this->warnings, ...$this->lastWarnings];
    }

    /**
     * @return array<int, string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }
}
