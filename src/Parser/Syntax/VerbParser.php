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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;

/**
 * Reads the verbs of a pattern for TokenParser, "(*COMMIT)", "(*MARK:name)",
 * "(*LIMIT_MATCH=10)", and what the same spelling opens: the lookaround an
 * alphabetic assertion "(*pla:...)" stands for, a script run "(*sr:...)",
 * a substring scan "(*scs:(1)...)" and its list of groups.
 *
 * A body PCRE reads from the text of the verb is read apart, by the parser
 * this reader is given, where it stands in the whole pattern: the reader
 * hands over its offsets, never a copy of its text. It shares the parser's
 * ParseState, and reads one text, the pattern or a body read apart.
 *
 * @internal
 */
final class VerbParser
{
    private readonly TokenStream $stream;

    private readonly string $pattern;

    /**
     * The reader of the list of groups of a substring scan, made on the first.
     */
    private ?GroupListReader $groupLists = null;

    /**
     * @param \Closure(): NodeInterface         $branches  reads the alternatives of a group, up to its ")"
     * @param \Closure(int, int): NodeInterface $readApart reads the body that starts at the offset given in
     *                                                     the whole pattern, of the length given, by a parser
     *                                                     of its own
     */
    public function __construct(
        private readonly ParseState $state,
        private readonly \Closure $branches,
        private readonly \Closure $readApart,
    ) {
        $this->stream = $state->stream;
        $this->pattern = $state->pattern;
    }

    /**
     * The node of a verb token: a verb, or the group an alphabetic assertion
     * or a script run stands for, its body read from the pattern, between
     * its opener and the ")" its token ends on, whether the token holds the
     * text of the body or the opener alone. A body read in place by the
     * lexer, one that never closes or any body when every one is read in
     * place, has the opener alone for its token, with no ")" in its text,
     * "(*pla:" or "(?*": the tokens after it are its body, up to its ")",
     * or to the ")" PCRE misses at the end.
     */
    public function verbNode(Token $token, int $startPosition): NodeInterface
    {
        // "(**)" reads as "(?*)" does, a token whose value starts with "*":
        // the text tells them apart.
        if ('**' === substr($this->pattern, $token->position + 1, 2)) {
            throw PcreVerb::doubleStarError($this->pattern, $token->position + 2);
        }

        if ($token->sourceLength !== \strlen($token->value) + 2) {
            return $this->createPcreVerbNode($token->value, $startPosition, $startPosition + $token->sourceLength);
        }

        $read = PcreVerb::read($token->value);
        // "(*scs:(1)" that no ")" closes: PCRE reads its list of groups
        // before the body, refusing a group it names that it cannot be.
        if (null !== $read->scanSubstring) {
            $this->groupLists()->readGroupList($token->position + 3 + \strlen($read->scanSubstring) + 1);
        }

        $body = ($this->branches)();
        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected )', ErrorCode::GroupUnclosed);

        // Reached only from the tokens of Lexer::tokenizeInPlace(), which
        // reads a closed body in place too: "(*pla:(a))b". Lexer::tokenize()
        // keeps a closed body in the opener's token, so there no ")" closes
        // a body read here.
        return new GroupNode($body, $read->assertion ?? GroupType::NonCapturing, null, $read->nonAtomic ? GroupParser::NON_ATOMIC_FLAG : null, $startPosition, $endToken->position + 1);
    }

    /**
     * Parses PCRE verbs in group context: (?(*VERB)...)
     */
    public function parsePcreVerbInGroup(int $startPosition): NodeInterface
    {
        $verbStartPosition = $this->stream->current()->position;

        $verb = $this->state->consumeWhile(static fn (string $c): bool => ':' !== $c);

        // A verb may carry an argument, as "(*MARK:name)" does.
        $argument = $this->stream->matchLiteral(':')
            ? $this->state->consumeWhile(static fn (): bool => true)
            : '';

        $endToken = $this->stream->consume(TokenType::GroupClose, 'Expected ) to close PCRE verb', ErrorCode::VerbUnclosed);
        $endPosition = $endToken->position + 1;

        // Parse the rest of the pattern after the verb group
        if (!$this->stream->isAtEnd()) {
            $expr = ($this->branches)();
        } else {
            $expr = new LiteralNode('', $endPosition, $endPosition);
        }

        // Create a group node containing the verb and the following expression
        $verbNode = $this->createPcreVerbNode(
            '' !== $argument ? $verb.':'.$argument : $verb,
            $verbStartPosition,
            $endPosition,
        );

        // Create a sequence with the verb and the expression
        return new SequenceNode(
            [$verbNode, $expr],
            $startPosition,
            $expr->getEndPosition(),
        );
    }

    /**
     * "(*scs:(1,<name>)body)": the list of groups, then the body, read apart
     * like the body of any alphabetic assertion.
     */
    private function createScanSubstringNode(string $name, int $startPosition, int $endPosition): GroupNode
    {
        $listOpen = $startPosition + 2 + \strlen($name) + 1;
        if ('(' !== ($this->pattern[$listOpen] ?? '')) {
            throw $this->groupLists()->groupListError([$listOpen, ErrorCode::ScanSubstringMissingList, \sprintf('Missing "(" to open the groups of (*%s: at position %d.', $name, $listOpen)]);
        }

        [$groups, $bodyStart] = $this->groupLists()->readGroupList($listOpen + 1);

        return new GroupNode(
            ($this->readApart)($bodyStart, $endPosition - 1 - $bodyStart),
            GroupType::ScanSubstring,
            // The spelling, "scs" or "scan_substring".
            $name,
            null,
            $startPosition,
            $endPosition,
            false,
            $groups,
        );
    }

    /**
     * @param string $verb the verb as written between "(*" and ")", or the
     *                     opener alone of a body left in the pattern, "pla:"
     */
    private function createPcreVerbNode(string $verb, int $startPosition, int $endPosition): NodeInterface
    {
        $read = PcreVerb::read($verb);

        if (null !== $read->scanSubstring && $this->state->target->supports(PcreFeature::ScanSubstring)) {
            return $this->createScanSubstringNode($read->scanSubstring, $startPosition, $endPosition);
        }

        // The body stands between the opener and the ")" of the verb.
        $bodyStart = $startPosition + 2 + $read->payloadOffset;
        $bodyLength = $endPosition - 1 - $bodyStart;

        if (null !== $read->assertion) {
            // "(*pla:...)" and its friends are the alphabetic spelling of a
            // lookaround, so they parse into the group they stand for.
            return new GroupNode(
                ($this->readApart)($bodyStart, $bodyLength),
                $read->assertion,
                null,
                $read->nonAtomic ? GroupParser::NON_ATOMIC_FLAG : null,
                $startPosition,
                $endPosition,
            );
        }

        if (null !== $read->matchLimit) {
            return new LimitMatchNode($read->matchLimit, $startPosition, $endPosition);
        }

        if ($read->isScriptRun()) {
            return new ScriptRunNode(
                substr($this->pattern, $bodyStart, $bodyLength),
                $startPosition,
                $endPosition,
                ($this->readApart)($bodyStart, $bodyLength),
                $read->atomicScriptRun,
            );
        }

        return new PcreVerbNode($read->name, $startPosition, $endPosition);
    }

    private function groupLists(): GroupListReader
    {
        return $this->groupLists ??= new GroupListReader($this->state);
    }
}
