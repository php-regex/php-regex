<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Node;

use RegexParser\NodeVisitor\NodeVisitorInterface;

/**
 * Represents a script run verb (e.g., (*script_run:Latin), (*sr:Latin)),
 * or its atomic form (*atomic_script_run:Latin), (*asr:Latin).
 *
 * This node represents PCRE2 script run assertions that ensure
 * all characters in the subject string belong to the same script.
 */
final readonly class ScriptRunNode extends AbstractNode
{
    public function __construct(
        public string $script,
        int $startPosition,
        int $endPosition,
        /**
         * Parsed content of the script run, when available. Positions inside
         * are relative to the payload text.
         */
        public ?NodeInterface $content = null,
        /**
         * Whether the body is atomic: "(*atomic_script_run:...)", short
         * "(*asr:...)", the same as "(*sr:(?>...))".
         */
        public bool $atomic = false,
    ) {
        parent::__construct($startPosition, $endPosition);
    }

    public function accept(NodeVisitorInterface $visitor)
    {
        return $visitor->visitScriptRun($this);
    }
}
