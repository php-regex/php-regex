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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\PcreFeature;

/**
 * Reads a list of groups, "(2,-1,<name>,'name')": the groups a call returns,
 * "(?1(2,<name>))", or the groups a substring scan matches,
 * "(*scs:(1,<name>)...)". PCRE reads the list from the text, and refuses
 * what it cannot take as it reads it.
 *
 * It reads the text of the parser's ParseState, and counts the groups opened
 * there.
 *
 * @internal
 */
final class GroupListReader
{
    public function __construct(private readonly ParseState $state) {}

    /**
     * A list of groups, "2,-1,<name>,'name'", from $at to its ")": the groups
     * a call returns or a substring scan matches. PCRE reads it from the
     * text, and refuses what it cannot take as it reads it: an item that is
     * no number or name, group zero, a relative zero, a group before the
     * first, or a number past 65535.
     *
     * @return array{0: list<string>, 1: int} the items as written, and the
     *                                        offset past the ")"
     */
    public function readGroupList(int $at): array
    {
        $pattern = $this->state->pattern;

        // What stops PCRE reading the list, if anything; the numbers it has
        // read before are judged first.
        $fault = PcreVerb::groupListFault($pattern, $at - 1, $this->state->target, $this->state->unicodeMode);

        $groups = [];
        while (true) {
            if (1 !== LibraryPcre::match('/\G(?:([+-]?)(\d++)|<[^>]*+>|\'[^\']*+\')/', $pattern, $matches, 0, $at)) {
                // The reader stops on such an item too: the fallback is for the type.
                throw $this->groupListError($fault ?? [$at, ErrorCode::GroupListItemExpected, \sprintf('Expected a capture group number or name at position %d.', $at)]);
            }

            $itemEnd = $at + \strlen($matches[0]);
            $sign = $matches[1] ?? '';
            $digits = $matches[2] ?? '';
            if ('' !== $digits) {
                [$refused, $code] = match (true) {
                    PcreNumber::exceeds($digits) => ['is too big', ErrorCode::GroupNumberTooBig],
                    0 === (int) $digits => '' === $sign
                        ? ['names no group', ErrorCode::GroupListMissingGroup]
                        : ['is a relative zero', ErrorCode::GroupListRelativeZero],
                    '-' === $sign && (int) $digits > $this->state->captureCount => ['names no group', ErrorCode::GroupListMissingGroup],
                    default => [null, null],
                };
                if (null !== $refused && null !== $code) {
                    throw $this->state->error(\sprintf('Group "%s" listed at position %d %s.', $matches[0], $itemEnd, $refused), $code, $itemEnd);
                }
            }

            // A name PCRE refuses, or no "," or ")" after the item.
            if (null !== $fault && $fault[0] <= $itemEnd) {
                throw $this->groupListError($fault);
            }

            $groups[] = $matches[0];
            $at = $itemEnd + 1;
            if (')' === ($pattern[$itemEnd] ?? '')) {
                return [$groups, $at];
            }
        }
    }

    /**
     * @param array{0: int, 1: ErrorCode, 2: string} $fault
     */
    public function groupListError(array $fault): ParserException
    {
        if (!$this->state->target->supports(PcreFeature::GroupListErrors)) {
            $fault = PcreVerb::groupListFaultBefore1047($fault, \strlen($this->state->pattern));
        }

        return $this->state->error($fault[2], $fault[1], $fault[0]);
    }
}
