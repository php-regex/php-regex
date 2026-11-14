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

namespace PHPRegex\Parser\Node;

use PHPRegex\Parser\NodeVisitorInterface;

/**
 * Represents a script run verb (e.g., (*script_run:Latin), (*sr:Latin)),
 * or its atomic form (*atomic_script_run:Latin), (*asr:Latin).
 *
 * This node represents PCRE2 script run assertions that ensure
 * all characters in the subject string belong to the same script.
 */
final class ScriptRunNode extends AbstractNode
{
    /**
     */
    public string $script;
    /**
     */
    public ?NodeInterface $content = null;
    /**
     */
    public bool $isAtomic = false;

    public function __construct(
        string $script,
        int $startPosition,
        int $endPosition,
        ?NodeInterface $content = null,
        bool $isAtomic = false
    ) {
        $this->script = $script;
        /**
         * Parsed content of the script run, when available. Positions inside
         * are relative to the payload text.
         */
        $this->content = $content;
        /**
         * Whether the body is atomic: "(*atomic_script_run:...)", short
         * "(*asr:...)", the same as "(*sr:(?>...))".
         */
        $this->isAtomic = $isAtomic;
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitScriptRun($this);
    }

    #[\Override]
    public function getChildren(): array
    {
        return null === $this->content ? [] : [$this->content];
    }
}
