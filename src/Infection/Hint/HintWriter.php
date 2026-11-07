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

namespace PHPRegex\Infection\Hint;

use PHPRegex\Infection\Mutation\CandidateGenerator;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\RegexParser;

/**
 * Writes the kill string into a mutant, just before its closing delimiter,
 * as a comment the engine skips: "(?#kill: "…")", or "(?(DEFINE)kill: "…")"
 * when the delimiter is "#", which would end the pattern inside "(?#". The
 * hinted pattern is read back, and kept only when the engine compiles it
 * and the parser reads the hint as one comment, or one DEFINE group, over
 * exactly the text written: an open "\Q" or a "?" or ":" delimiter would
 * turn the hint into text to match, so the mutant then goes without it.
 * Under /x a comment running to the end of the pattern would swallow the
 * hint: a newline ends it first.
 *
 * @internal
 */
final class HintWriter
{
    private readonly RegexParser $parser;

    private readonly PcreEngine $engine;

    public function __construct(?RegexParser $parser = null, ?PcreEngine $engine = null)
    {
        $this->parser = $parser ?? RegexParser::create();
        $this->engine = $engine ?? new PcreEngine();
    }

    /**
     * The pattern with the hint written into it, or null when no hint
     * survives the reading back.
     */
    public function write(string $pattern, string $subject): ?string
    {
        try {
            $ast = $this->parser->parse($pattern);
        } catch (ExceptionInterface) {
            return null;
        }

        // The parser reads the body after the white space PHP skips: what
        // follows it is the closing delimiter and the modifiers, as written.
        $body = (string) $ast->source;
        $bodyStart = \strlen($pattern) - \strlen(ltrim($pattern, CandidateGenerator::LEADING_SPACE)) + 1;

        $strict = '#' === $ast->delimiter;
        $literal = KillString::encode($subject, $ast->delimiter, $strict);
        if (null === $literal) {
            return null;
        }

        $hint = ($strict ? '(?(DEFINE)kill: ' : '(?#kill: ').$literal.')';
        $head = substr($pattern, 0, $bodyStart);
        $tail = substr($pattern, $bodyStart + \strlen($body));

        foreach (str_contains($ast->flags, 'x') ? ['', "\n"] : [''] as $separator) {
            $hinted = $head.$body.$separator.$hint.$tail;
            $start = \strlen($body) + \strlen($separator);
            if ($this->readsBack($hinted, $start, $start + \strlen($hint), $strict)) {
                return $hinted;
            }
        }

        return null;
    }

    private function readsBack(string $hinted, int $start, int $end, bool $define): bool
    {
        if (null !== $this->engine->compile($hinted)) {
            return false;
        }

        try {
            $ast = $this->parser->parse($hinted);
        } catch (ExceptionInterface) {
            // The hint may carry a pattern past the parser's length limit:
            // the mutant then goes without it.
            return false;
        }

        return self::holdsHint($ast->pattern, $start, $end, $define);
    }

    private static function holdsHint(NodeInterface $node, int $start, int $end, bool $define): bool
    {
        $hint = $define ? $node instanceof DefineNode : $node instanceof CommentNode && !$node->isExtended;
        if ($hint && $start === $node->getStartPosition() && $end === $node->getEndPosition()) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsHint($child, $start, $end, $define)) {
                return true;
            }
        }

        return false;
    }
}
