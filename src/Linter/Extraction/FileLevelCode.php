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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Text\Ascii;

/**
 * What the file level of a PHP source holds, by byte offset, that may not
 * stand between the assignment of a variable of that level and a read of
 * it.
 *
 * Such a variable is a global, and PHP runs code where the file writes no
 * call: a magic method, an ArrayAccess or IteratorAggregate method, a
 * destructor, a class loader, an output callback, a tick function. Each is
 * code of another file, which may write the global. The statements that
 * may run one have no end, so the variable is read only when its
 * assignment is a statement of its own, "$v = value;", and not one
 * written in an argument, a condition, an interpolated string or any
 * other expression, and when everything written between the end of that
 * assignment and the read is on a closed list:
 *
 * - whitespace and comments;
 * - plain assignments to a plain variable, "$v = value;", whose value is
 *   made only of strings without interpolation, integers, floats, true,
 *   false, arrays of such values under such keys, parentheses,
 *   concatenations, and variables proved a string: one such an assignment
 *   gave a string, in a block that still holds the place, with nothing
 *   off the list since; or one a pattern is read through, which the
 *   file proves to hold strings where it is written;
 * - an if, with its elseif and else, whose conditions hold comparisons,
 *   "&&", "||", "and", "or", "xor" and "!" over such values, and whose
 *   bodies hold what the list holds;
 * - in such an expression, a plain variable that is not proved, where it
 *   runs no code whatever it holds: under "===" and "!==", under "==",
 *   "!=", "<", ">", "<=" and ">=" against an integer, a float or a
 *   boolean, and where PHP converts it to a boolean, in parentheses of
 *   its own or not: under "!", as an operand of "&&", "||", "and", "or"
 *   or "xor", and alone as the condition;
 * - the beginning of the statement that holds the read: if, elseif, else,
 *   return, braces, parentheses, the target of a plain assignment, such
 *   comparisons and operands, and the name of each function whose call
 *   holds the read, the arguments written before it being values of the
 *   list.
 *
 * Anything else is off the list, whether it runs code or not. A file
 * that declares ticks runs a tick function after each statement that
 * follows: nothing after the declare is read; nor is anything after a
 * declaration the file never closes.
 *
 * One thing no list closes, and the reading does not see: the assignment
 * itself gives up what the variable held, and when that was the last
 * name of an object of another file, its destructor runs then, after the
 * new value is stored. "$re = '/x/';" is read although such a destructor
 * may write $re again.
 *
 * The tokens of the file level are read once, in order and as far as the
 * read asked for, each in the statement it stands in; the bodies of the
 * functions and of the classes are stepped over, which run nothing where
 * they stand. What is off the list is kept by its offset. A call is kept
 * where it ends, since it runs there: one that holds the read ends after
 * it. A statement made of what only the beginning of a statement may
 * hold is kept at its last token: whole, it stands between; cut by the
 * read, it does not.
 *
 * @internal
 */
final class FileLevelCode
{
    /**
     * What a bracket opens. The braces first: the body of an if, an
     * elseif or an else, any other block of statements, and braces that
     * hold an expression. Then the parentheses: the condition of an if or
     * an elseif, a parenthesised expression, the arguments of a function,
     * array(), the subject of a match, the head of a keyword of HEADS,
     * and any other, which calls what a variable or an expression names.
     * Then the square brackets: an array, and any other.
     */
    private const IF_BLOCK = 0;
    private const BLOCK = 1;
    private const BRACES = 2;
    private const CONDITION = 3;
    private const GROUP = 4;
    private const CALL = 5;
    private const ARRAY_PARENTHESIS = 6;
    private const MATCH_SUBJECT = 7;
    private const HEAD = 8;
    private const PARENTHESIS = 9;
    private const ARRAY_BRACKET = 10;
    private const BRACKET = 11;

    /**
     * What a bracket holds that only the beginning of a statement may
     * hold: the target of an assignment; a comparison, a negation, "&&",
     * "||", "and" or "or".
     */
    private const ASSIGNS = 1;
    private const COMPARES = 2;

    /**
     * The comparisons that convert their operands: an object compared
     * with a string is asked for its __toString().
     */
    private const COMPARISONS = [
        \T_IS_EQUAL => true, \T_IS_NOT_EQUAL => true, '<' => true, '>' => true,
        \T_IS_SMALLER_OR_EQUAL => true, \T_IS_GREATER_OR_EQUAL => true,
    ];

    /**
     * The comparisons that convert nothing.
     */
    private const IDENTITIES = [\T_IS_IDENTICAL => true, \T_IS_NOT_IDENTICAL => true];

    /**
     * The operators that convert their operands to booleans.
     */
    private const LOGICAL = [
        \T_BOOLEAN_AND => true, \T_BOOLEAN_OR => true, \T_LOGICAL_AND => true, \T_LOGICAL_OR => true, \T_LOGICAL_XOR => true,
    ];

    /**
     * The tokens a value may follow.
     */
    private const BEFORE_OPERAND = [
        '=' => true, '(' => true, '[' => true, ',' => true, \T_DOUBLE_ARROW => true, '.' => true, '!' => true, \T_RETURN => true,
    ] + self::COMPARISONS + self::IDENTITIES + self::LOGICAL;

    /**
     * The tokens the name of a function whose call holds the read may
     * follow.
     */
    private const BEFORE_CALL = [
        '=' => true, '(' => true, ',' => true, '!' => true, \T_RETURN => true,
    ] + self::COMPARISONS + self::IDENTITIES + self::LOGICAL;

    /**
     * The tokens that bind looser than a comparison, before its first
     * operand: what follows them, up to the comparison, is that operand
     * alone.
     */
    private const BEFORE_COMPARISON = ['(' => true, ',' => true, '=' => true, \T_RETURN => true] + self::LOGICAL;

    /**
     * The tokens that bind looser than a comparison, after its second
     * operand.
     */
    private const AFTER_COMPARISON = [')' => true, ';' => true, ',' => true, ']' => true] + self::LOGICAL;

    private const NAMES = [\T_STRING => true, \T_NAME_QUALIFIED => true, \T_NAME_FULLY_QUALIFIED => true];

    /**
     * The keywords whose parenthesis opens no call: nothing runs where
     * it closes.
     */
    private const HEADS = [
        \T_WHILE => true, \T_FOR => true, \T_FOREACH => true, \T_SWITCH => true, \T_CATCH => true, \T_DECLARE => true,
        \T_ISSET => true, \T_EMPTY => true, \T_UNSET => true, \T_LIST => true, \T_FN => true,
    ];

    private const MEMBER_OPERATORS = [\T_DOUBLE_COLON => true, \T_OBJECT_OPERATOR => true, \T_NULLSAFE_OBJECT_OPERATOR => true];

    /**
     * @var array<int, array{int, string, int}|string>
     */
    private array $tokens;

    /**
     * @var array<int, int> the byte offset of each token
     */
    private array $offsets;

    private bool $tokenized;

    private int $count = 0;

    /**
     * The token the reading stopped before.
     */
    private int $index = 0;

    /**
     * @var list<int> where each thing off the list stands, in order
     */
    private array $offList = [];

    /**
     * Where the first declare of ticks is written, or the keyword of the
     * first declaration the file never closes, whichever comes first:
     * nothing is read after it. Null for none read so far.
     */
    private ?int $unreadFrom = null;

    /**
     * @var array<int, true> where each plain assignment that starts a statement is written
     */
    private array $statements = [];

    /**
     * @var list<array{int, int, int, int}> the brackets open around the token: what each opens, the token that
     *                                      opens it, how many things were off the list before it, or before its
     *                                      last "," for the arguments of a function, and what it holds since,
     *                                      of ASSIGNS and COMPARES
     */
    private array $frames = [];

    /**
     * How many of the open brackets hold the items of an array, where a
     * value alone may stand.
     */
    private int $arrays = 0;

    /**
     * @var non-empty-list<array<string, int>> for the file, then for each brace open around the token, the
     *                                         variables a statement of the list assigned a string there, each
     *                                         with the count of the things off the list read by then
     */
    private array $proved = [[]];

    /**
     * How many things off the list were read: a variable proved before
     * the last of them is proved no more.
     */
    private int $epoch = 0;

    /**
     * Whether the next token starts a statement; and whether that
     * statement is the body of an if, an elseif or an else written
     * without braces, which may not run.
     */
    private bool $starts = true;

    private bool $unbraced = false;

    /**
     * The first token of the statement being read, and how many things
     * were off the list before it.
     */
    private int $statementAt = -1;

    private int $statementMark = 0;

    /**
     * The variable the statement being read assigns, when it starts as
     * a plain assignment does; and whether every token of its value, so
     * far, is one of a value of the list.
     */
    private ?string $target = null;

    private bool $listed = false;

    /**
     * What the value assigned holds out of any array: a concatenation, a
     * number or a boolean; and whether it holds an array.
     */
    private bool $concatenates = false;

    private bool $holdsScalar = false;

    private bool $holdsArray = false;

    /**
     * The last variable read as the target of an assignment.
     */
    private int $targetAt = -1;

    /**
     * @var array{int, int, int} the last three tokens read that are no whitespace and no comment, from the last
     */
    private array $before = [-1, -1, -1];

    /**
     * The last of them, as the token it is; before the first, the text
     * no PHP tag opened.
     */
    private int|string $previous = \T_INLINE_HTML;

    /**
     * @var array{int, int} what the last bracket closed had opened, and the token that closed it
     */
    private array $closed = [-1, -1];

    /**
     * @var (\Closure(string, int): bool)|null while the tokens are read, whether the file proves a variable,
     *                                         by its name, to hold strings where it is written, by its offset
     */
    private ?\Closure $proves = null;

    /**
     * @param array<int, array{int, string, int}|string>|null $tokens  the tokens of the source, when it is read
     *                                                                 already
     * @param array<int, int>|null                            $offsets the byte offset of each
     */
    public function __construct(
        private readonly string $content,
        ?array $tokens = null,
        ?array $offsets = null
    ) {
        $this->tokenized = null !== $tokens && null !== $offsets;
        $this->tokens = $tokens ?? [];
        $this->offsets = $offsets ?? [];
    }

    /**
     * Whether everything written after the byte at $from and before the
     * one at $to is on the list, in a file that declares no ticks before
     * $to.
     *
     * @param \Closure(string, int): bool $proves whether the file proves a variable, by its name without "$", to
     *                                            hold strings where it is written, by its offset: asked of each
     *                                            variable no assignment of the list proves, once, where it is
     *                                            read, and answered from what stands before it
     */
    public function holdsOnlyTheList(int $from, int $to, \Closure $proves): bool
    {
        $this->readUntil($to, $proves);
        if (null !== $this->unreadFrom && $this->unreadFrom < $to) {
            return false;
        }

        // The first thing off the list after $from.
        $low = 0;
        $high = \count($this->offList);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->offList[$middle] <= $from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return !isset($this->offList[$low]) || $this->offList[$low] >= $to;
    }

    /**
     * Whether the assignment written at $at is a statement of its own,
     * "$v = value;", among the statements of the file or of a block: one
     * written in an argument, in a condition, in an interpolated string
     * or in any other expression is none, and what runs around it is not
     * followed.
     *
     * @param \Closure(string, int): bool $proves as for holdsOnlyTheList()
     */
    public function assignsInAStatement(int $at, \Closure $proves): bool
    {
        $this->readUntil($at + 1, $proves);

        return isset($this->statements[$at]);
    }

    /**
     * Read the tokens written before the byte at $offset that are not
     * read yet. Asked again while a token is read, of a variable that
     * token names, it reads nothing: what stands before the token is
     * read already.
     *
     * @param \Closure(string, int): bool $proves
     */
    private function readUntil(int $offset, \Closure $proves): void
    {
        if (null !== $this->proves) {
            return;
        }

        $this->proves = $proves;
        $this->readTokensUntil($offset);
        $this->proves = null;
    }

    private function readTokensUntil(int $offset): void
    {
        if (!$this->tokenized) {
            $this->tokens = token_get_all($this->content);
            $this->offsets = PhpTokens::offsets($this->tokens);
            $this->tokenized = true;
        }

        $this->count = \count($this->tokens);
        while ($this->index < $this->count && $this->offsets[$this->index] < $offset) {
            $index = $this->index++;
            $token = $this->tokens[$index];
            if (!\is_array($token)) {
                $this->read($index, $token, $token);
            } elseif (!isset(PhpTokens::IGNORABLE[$token[0]])) {
                $this->read($index, $token[0], $token[1]);
            }
        }
    }

    private function read(int $index, int|string $id, string $text): void
    {
        $at = $this->offsets[$index];
        $starts = $this->starts;
        if ($starts) {
            $this->starts = false;
            $this->statementAt = $index;
            $this->statementMark = \count($this->offList);
            $this->target = null;
            $this->listed = false;
            $this->concatenates = $this->holdsScalar = $this->holdsArray = false;
        }

        $previous = $this->previous;
        // Whether the token may stand in the value of a plain assignment.
        $value = false;
        // The token the next one follows: the last of what is stepped over.
        $last = $index;

        switch ($id) {
            case \T_OPEN_TAG:
                $this->starts = true;
                $this->unbraced = false;

                break;
            case ';':
                if ($this->amongStatements()) {
                    $this->endStatement($starts);
                } else {
                    // The ";" of the head of a for.
                    $this->offList($at);
                }

                break;
            case '{':
                $this->openBrace($index, $previous);

                break;
            case '}':
                $this->closeBrace($index);

                break;
            case '(':
                $value = $this->openParenthesis($index, $previous, $starts);

                break;
            case ')':
                $value = $this->closeParenthesis($index);

                break;
            case '[':
                // An array where a value starts; an item read, written or
                // appended, and a destructuring assignment, anywhere else.
                if (!$starts && isset(self::BEFORE_OPERAND[$previous]) && '.' !== $previous) {
                    $this->frames[] = [self::ARRAY_BRACKET, $index, \count($this->offList), 0];
                    $this->arrays++;
                    $this->holdsArray = true;
                    $value = true;
                } else {
                    $this->frames[] = [self::BRACKET, $index, \count($this->offList), 0];
                    $this->offList($at);
                }

                break;
            case \T_ATTRIBUTE:
                $this->frames[] = [self::BRACKET, $index, \count($this->offList), 0];
                $this->offList($at);

                break;
            case ']':
                $kind = $this->kind();
                if (self::ARRAY_BRACKET !== $kind && self::BRACKET !== $kind) {
                    $this->offList($at);

                    break;
                }

                array_pop($this->frames);
                $this->closed = [$kind, $index];
                $this->leave($kind);
                $value = self::ARRAY_BRACKET === $kind;

                break;
            case ',':
                $kind = $this->kind();
                if (self::ARRAY_PARENTHESIS === $kind || self::ARRAY_BRACKET === $kind) {
                    $value = true;
                } elseif (self::CALL === $kind) {
                    $this->endArgument($at);
                } else {
                    $this->offList($at);
                }

                break;
            case \T_DOUBLE_ARROW:
                // The key of an item of an array, which is no array.
                $kind = $this->kind();
                if ((self::ARRAY_PARENTHESIS === $kind || self::ARRAY_BRACKET === $kind) && !$this->followsArray()) {
                    $value = true;
                } else {
                    $this->offList($at);
                }

                break;
            case '.':
                // An array joined to a string raises a warning.
                if ($this->followsArray()) {
                    $this->offList($at);
                } else {
                    $value = true;
                    $this->concatenates = $this->concatenates || 0 === $this->arrays;
                }

                break;
            case '=':
                if ($this->targetAt !== $this->before[0]) {
                    $this->offList($at);
                } else {
                    $value = null !== $this->target && $this->statementAt === $this->targetAt;
                }

                break;
            case '!':
            case '<':
            case '>':
            case \T_IS_EQUAL:
            case \T_IS_NOT_EQUAL:
            case \T_IS_SMALLER_OR_EQUAL:
            case \T_IS_GREATER_OR_EQUAL:
            case \T_IS_IDENTICAL:
            case \T_IS_NOT_IDENTICAL:
            case \T_BOOLEAN_AND:
            case \T_BOOLEAN_OR:
            case \T_LOGICAL_AND:
            case \T_LOGICAL_OR:
            case \T_LOGICAL_XOR:
                // An item of an array is a value, not a comparison.
                if ($this->arrays > 0) {
                    $this->offList($at);
                } else {
                    $this->hold(self::COMPARES);
                }

                break;
            case \T_VARIABLE:
                $value = $this->variable($index, $text, $previous, $starts);

                break;
            case \T_CONSTANT_ENCAPSED_STRING:
                $value = true;

                break;
            case \T_LNUMBER:
            case \T_DNUMBER:
                $value = true;
                $this->holdsScalar = $this->holdsScalar || 0 === $this->arrays;

                break;
            case \T_STRING:
            case \T_NAME_QUALIFIED:
            case \T_NAME_FULLY_QUALIFIED:
                $next = $this->next($index + 1);
                if (null !== $next && '(' === $this->tokens[$next]) {
                    // The function of a call, which is kept where it ends:
                    // out of an array, and where no string is made of its
                    // result.
                    if ((!$starts && !isset(self::BEFORE_CALL[$previous])) || $this->arrays > 0) {
                        $this->offList($at);
                    }
                } elseif (\T_STRING === $id && \in_array(Ascii::lower($text), ['true', 'false'], true) && (null === $next || !isset(self::MEMBER_OPERATORS[$this->id($next)]))) {
                    $value = true;
                    $this->holdsScalar = $this->holdsScalar || 0 === $this->arrays;
                } else {
                    // A constant, the name of a class, a label.
                    $this->offList($at);
                }

                break;
            case \T_ARRAY:
                $next = $this->next($index + 1);
                if (!$starts && isset(self::BEFORE_OPERAND[$previous]) && '.' !== $previous && null !== $next && '(' === $this->tokens[$next]) {
                    $this->holdsArray = true;
                    $value = true;
                } else {
                    $this->offList($at);
                }

                break;
            case \T_IF:
            case \T_ELSEIF:
            case \T_RETURN:
                if (!$starts) {
                    $this->offList($at);
                }

                break;
            case \T_ELSE:
                if (!$starts) {
                    $this->offList($at);

                    break;
                }

                // "else if" and a body without braces start a statement.
                $next = $this->next($index + 1);
                if (null === $next || '{' !== $this->tokens[$next]) {
                    $this->starts = true;
                    $this->unbraced = true;
                }

                break;
            case '"':
            case '`':
            case \T_START_HEREDOC:
                $this->offList($at);
                $last = $this->textEnd($index);

                break;
            case \T_FUNCTION:
            case \T_CLASS:
            case \T_INTERFACE:
            case \T_TRAIT:
            case \T_ENUM:
                $this->offList($at);
                // "use function", Name::class and a member named by a keyword
                // declare nothing.
                if (\T_USE === $previous || isset(self::MEMBER_OPERATORS[$previous])) {
                    break;
                }

                $last = $this->declarationEnd($index);
                if ($this->names($index)) {
                    // A declaration is a statement of its own; a closure and
                    // an anonymous class stand in one.
                    $this->target = null;
                    $this->starts = true;
                    $this->unbraced = false;
                }

                break;
            case \T_DECLARE:
                $this->offList($at);
                $this->readTicks($index);

                break;
            default:
                $this->offList($at);
        }

        $this->listed = $this->listed && $value;
        if ($last !== $index) {
            $this->index = $last + 1;
            $token = $this->tokens[$last];
            $id = \is_array($token) ? $token[0] : $token;
        }

        $this->before = [$last, $this->before[0], $this->before[1]];
        $this->previous = $id;
    }

    /**
     * A variable: the target of a plain assignment, one proved a string,
     * or one that is not, which is on the list only where it runs no code.
     */
    private function variable(int $index, string $name, int|string $previous, bool $starts): bool
    {
        $next = $this->next($index + 1);
        $after = null === $next ? null : $this->id($next);
        if ('=' === $after) {
            $this->targetAt = $index;
            if ($starts) {
                $this->target = $name;
                $this->listed = true;
                $this->statements[$this->offsets[$index]] = true;

                return true;
            }

            // Assigned in a condition, or in the value of another: what
            // it holds from there is not followed.
            $this->unprove($name);
            if (0 === $this->arrays && ('!' === $previous || isset(self::BEFORE_COMPARISON[$previous]))) {
                $this->hold(self::ASSIGNS);
            } else {
                $this->offList($this->offsets[$index]);
            }

            return false;
        }

        if ($this->isProved($name) || (null !== $this->proves && ($this->proves)(substr($name, 1), $this->offsets[$index]))) {
            return true;
        }

        if (!$this->runsNoCode($previous, $starts, $next)) {
            $this->offList($this->offsets[$index]);
        }

        return false;
    }

    /**
     * Whether a variable that is not proved stands where PHP asks it
     * nothing, whatever it holds: converted to a boolean, in parentheses
     * of its own or not, by "!", by "&&", "||", "and", "or" or "xor", or
     * as the whole condition of an if; under an identity; or compared
     * with a number or a boolean, which is the whole of the other
     * operand. A string made of it, an item or a property read from it,
     * a comparison with a string run its code when it is an object.
     */
    private function runsNoCode(int|string $previous, bool $starts, ?int $next): bool
    {
        // The parentheses that hold the variable alone, and what stands
        // around them.
        $left = $this->before[0];
        $right = $next;
        $level = \count($this->frames) - 1;
        $alone = true;
        while ($level >= 0 && self::GROUP === $this->frames[$level][0] && $this->frames[$level][1] === $left && null !== $right && ')' === $this->tokens[$right]) {
            $starts = $left === $this->statementAt;
            $left = PhpTokens::previousSignificant($this->tokens, $left - 1) ?? 0;
            $right = $this->next($right + 1);
            $level--;
            $alone = false;
        }

        $before = $alone ? $previous : $this->id($left);
        $after = null === $right ? null : $this->id($right);
        if ('!' === $before) {
            return true;
        }

        if ('(' === $before && ')' === $after && $level >= 0 && self::CONDITION === $this->frames[$level][0] && $this->frames[$level][1] === $left) {
            return true;
        }

        // An operand of "&&", "||", "and", "or" or "xor": the second one,
        // which nothing tighter takes; the first one.
        $opens = $starts || isset(self::BEFORE_COMPARISON[$before]);
        $closes = null !== $after && isset(self::AFTER_COMPARISON[$after]);
        if ((isset(self::LOGICAL[$before]) && $closes) || (null !== $after && isset(self::LOGICAL[$after]) && $opens)) {
            return true;
        }

        if (!$alone || null === $right) {
            return false;
        }

        // The first operand of a comparison.
        if ($opens) {
            if (isset(self::IDENTITIES[$after])) {
                return true;
            }

            if (isset(self::COMPARISONS[$after])) {
                $other = $this->next($right + 1);
                $end = null === $other ? null : $this->next($other + 1);

                return null !== $other && null !== $end && $this->isNumberOrBoolean($other) && isset(self::AFTER_COMPARISON[$this->id($end)]);
            }
        }

        // The second one.
        if (!$closes) {
            return false;
        }

        if (isset(self::IDENTITIES[$before])) {
            return true;
        }

        [, $other, $start] = $this->before;

        return isset(self::COMPARISONS[$before]) && $other >= 0 && $this->isNumberOrBoolean($other)
            && ($other === $this->statementAt || ($start >= 0 && isset(self::BEFORE_COMPARISON[$this->id($start)])));
    }

    private function openBrace(int $index, int|string $previous): void
    {
        $follows = $this->closed[1] === $this->before[0] ? $this->closed[0] : null;
        $kind = match (true) {
            ')' === $previous && self::CONDITION === $follows, \T_ELSE === $previous => self::IF_BLOCK,
            // The arms of a match, "${...}" and a member named by an
            // expression hold no statement.
            (')' === $previous && self::MATCH_SUBJECT === $follows) || '$' === $previous || isset(self::MEMBER_OPERATORS[$previous]) => self::BRACES,
            default => self::BLOCK,
        };

        $this->frames[] = [$kind, $index, \count($this->offList), 0];
        $this->proved[] = [];
        if (self::BRACES === $kind) {
            return;
        }

        if (null !== $this->target) {
            $this->unprove($this->target);
        }

        $this->target = null;
        $this->starts = true;
        $this->unbraced = false;
    }

    private function closeBrace(int $index): void
    {
        $kind = null;
        while ([] !== $this->frames) {
            [$kind] = array_pop($this->frames);
            if ($kind <= self::BRACES) {
                break;
            }

            // A bracket left open.
            $this->leave($kind);
            $kind = null;
        }

        if (null === $kind) {
            $this->offList($this->offsets[$index]);

            return;
        }

        // What the block assigned may not have run.
        if (\count($this->proved) > 1) {
            array_pop($this->proved);
        }

        if (self::BRACES === $kind) {
            return;
        }

        if (null !== $this->target) {
            $this->unprove($this->target);
        }

        // The end of a loop, which may turn again, of a try, of a switch.
        if (self::BLOCK === $kind) {
            $this->offList($this->offsets[$index]);
        }

        $this->closed = [$kind, $index];
        $this->target = null;
        $this->starts = true;
        $this->unbraced = false;
    }

    /**
     * @return bool whether the parenthesis may stand in the value of a plain assignment
     */
    private function openParenthesis(int $index, int|string $previous, bool $starts): bool
    {
        $kind = match (true) {
            \T_IF === $previous, \T_ELSEIF === $previous => self::CONDITION,
            isset(self::NAMES[$previous]) => self::CALL,
            \T_ARRAY === $previous => self::ARRAY_PARENTHESIS,
            \T_MATCH === $previous => self::MATCH_SUBJECT,
            isset(self::HEADS[$previous]) => self::HEAD,
            $starts, isset(self::BEFORE_OPERAND[$previous]) => self::GROUP,
            default => self::PARENTHESIS,
        };

        $this->frames[] = [$kind, $index, \count($this->offList), 0];
        if (self::ARRAY_PARENTHESIS === $kind) {
            $this->arrays++;
        } elseif (self::HEAD === $kind || self::PARENTHESIS === $kind) {
            // The head of a loop, of a switch, of a catch, of isset(); a
            // call of what a variable or an expression names.
            $this->offList($this->offsets[$index]);
        }

        return self::GROUP === $kind || self::ARRAY_PARENTHESIS === $kind;
    }

    /**
     * @return bool whether the parenthesis may stand in the value of a plain assignment
     */
    private function closeParenthesis(int $index): bool
    {
        $at = $this->offsets[$index];
        $frame = [] === $this->frames ? null : $this->frames[\count($this->frames) - 1];
        if (null === $frame || $frame[0] < self::CONDITION || $frame[0] > self::PARENTHESIS) {
            $this->offList($at);

            return false;
        }

        array_pop($this->frames);
        [$kind, , $mark, $holds] = $frame;
        $this->closed = [$kind, $index];
        $this->leave($kind);

        switch ($kind) {
            case self::CONDITION:
                // A condition that assigns is the beginning of a
                // statement, not an if of the list.
                if (0 !== ($holds & self::ASSIGNS) && \count($this->offList) === $mark) {
                    $this->offList($at);
                }

                $next = $this->next($index + 1);
                if (null === $next || '{' !== $this->tokens[$next]) {
                    $this->starts = true;
                    $this->unbraced = true;
                }

                return false;
            case self::CALL:
            case self::PARENTHESIS:
                // The call runs here.
                $this->offList($at);

                return false;
            case self::GROUP:
                $this->hold($holds);

                return true;
            default:
                return self::ARRAY_PARENTHESIS === $kind;
        }
    }

    /**
     * A bracket closed, or left open by what closes around it.
     */
    private function leave(int $kind): void
    {
        if (self::ARRAY_PARENTHESIS === $kind || self::ARRAY_BRACKET === $kind) {
            $this->arrays--;
        }
    }

    /**
     * The innermost open bracket holds the target of an assignment or a
     * comparison.
     *
     * @param int $what ASSIGNS, COMPARES, or both
     */
    private function hold(int $what): void
    {
        if ([] !== $this->frames) {
            $this->frames[\count($this->frames) - 1][3] |= $what;
        }
    }

    /**
     * The "," that ends an argument: one that assigns or compares is no
     * value, and stands whole before the arguments that follow it.
     */
    private function endArgument(int $at): void
    {
        [, $open, $mark, $holds] = array_pop($this->frames) ?? [self::CALL, -1, 0, 0];
        if (0 !== $holds && \count($this->offList) === $mark) {
            $this->offList($at);
        }

        $this->frames[] = [self::CALL, $open, \count($this->offList), 0];
    }

    /**
     * The ";" of a statement. A plain assignment of a value of the list
     * proves its variable when the value is a string, in a body that
     * runs; any other statement leaves its target unproved, and one made
     * of nothing off the list is off the list as a whole.
     */
    private function endStatement(bool $empty): void
    {
        if (!$empty) {
            if (null !== $this->target && $this->listed) {
                if ($this->holdsArray || ($this->holdsScalar && !$this->concatenates)) {
                    $this->unprove($this->target);
                } elseif (!$this->unbraced && !$this->isProved($this->target)) {
                    $this->proved[\count($this->proved) - 1][$this->target] = $this->epoch;
                }
            } else {
                if (null !== $this->target) {
                    $this->unprove($this->target);
                }

                if (\count($this->offList) === $this->statementMark && $this->before[0] >= 0) {
                    $this->offList($this->offsets[$this->before[0]]);
                }
            }
        }

        $this->target = null;
        $this->starts = true;
        $this->unbraced = false;
    }

    /**
     * Whether the name follows the keyword of a declaration: a function,
     * a class, an interface, a trait or an enum of the file, as opposed
     * to a closure and an anonymous class.
     */
    private function names(int $keyword): bool
    {
        $next = $this->next($keyword + 1);
        if (null !== $next && \in_array($this->id($next), [\T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
            $next = $this->next($next + 1);
        }

        return null !== $next && \T_STRING === $this->id($next);
    }

    /**
     * The last token of the declaration, a closure and an anonymous class
     * included, whose keyword is at $keyword: the brace that closes its
     * body, or the token before what ends a declaration without one.
     */
    private function declarationEnd(int $keyword): int
    {
        $depth = 0;
        for ($i = $keyword + 1; $i < $this->count; $i++) {
            $token = $this->tokens[$i];
            if (\is_array($token)) {
                if (\T_ATTRIBUTE === $token[0]) {
                    $depth++;
                } elseif (\T_CLOSE_TAG === $token[0]) {
                    return $i - 1;
                }
            } elseif ('(' === $token || '[' === $token) {
                $depth++;
            } elseif (')' === $token || ']' === $token) {
                if (--$depth < 0) {
                    return $i - 1;
                }
            } elseif (0 === $depth && ';' === $token) {
                return $i - 1;
            } elseif (0 === $depth && '{' === $token) {
                return $this->bodyEnd($i, $keyword);
            }
        }

        // The declaration never ends: what follows its keyword is no
        // statement of the file.
        $this->unreadFrom ??= $this->offsets[$keyword];

        return $this->count - 1;
    }

    /**
     * The brace that closes the body opened at $open, of the declaration
     * whose keyword is at $keyword. A declare of ticks written in it
     * holds for the rest of the file.
     */
    private function bodyEnd(int $open, int $keyword): int
    {
        $depth = 0;
        for ($i = $open; $i < $this->count; $i++) {
            $token = $this->tokens[$i];
            if (\is_array($token)) {
                if (\T_CURLY_OPEN === $token[0] || \T_DOLLAR_OPEN_CURLY_BRACES === $token[0]) {
                    $depth++;
                } elseif (\T_DECLARE === $token[0]) {
                    $this->readTicks($i);
                }
            } elseif ('{' === $token) {
                $depth++;
            } elseif ('}' === $token && 0 === --$depth) {
                return $i;
            }
        }

        $this->unreadFrom ??= $this->offsets[$keyword];

        return $this->count - 1;
    }

    /**
     * The token that ends the interpolated string, the command or the
     * heredoc opened at $open: its own quote or its closing label. The
     * braces of an interpolation hold code, where another string may
     * open and close, with braces of its own: each is ended by what
     * opened it.
     */
    private function textEnd(int $open): int
    {
        // What ends each text and each brace open so far.
        $ends = [];
        for ($i = $open; $i < $this->count; $i++) {
            $id = $this->id($i);
            $end = [] === $ends ? null : $ends[\count($ends) - 1];
            if (null !== $end && '}' !== $end) {
                // In a text: its end, or the braces of an interpolation.
                if ($id === $end) {
                    array_pop($ends);
                    if ([] === $ends) {
                        return $i;
                    }
                } elseif (\T_CURLY_OPEN === $id || \T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                    $ends[] = '}';
                }
            } elseif ('"' === $id || '`' === $id) {
                $ends[] = $id;
            } elseif (\T_START_HEREDOC === $id) {
                $ends[] = \T_END_HEREDOC;
            } elseif ('{' === $id) {
                // A brace of the code, as in "{$o->{'name'}}".
                $ends[] = '}';
            } elseif ('}' === $id) {
                array_pop($ends);
            }
        }

        return $this->count - 1;
    }

    /**
     * A declare: one of ticks, "declare(ticks=1)", alone or beside
     * another directive, compiles what follows it with ticks.
     */
    private function readTicks(int $declare): void
    {
        for ($i = $declare + 1; $i < $this->count; $i++) {
            $token = $this->tokens[$i];
            if (')' === $token || ';' === $token || '{' === $token) {
                return;
            }

            if (\is_array($token) && \T_STRING === $token[0] && 'ticks' === Ascii::lower($token[1])) {
                $this->unreadFrom ??= $this->offsets[$declare];

                return;
            }
        }
    }

    private function offList(int $offset): void
    {
        $this->offList[] = $offset;
        $this->epoch++;
    }

    private function isProved(string $name): bool
    {
        foreach ($this->proved as $block) {
            if ($this->epoch === ($block[$name] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function unprove(string $name): void
    {
        foreach ($this->proved as $depth => $block) {
            if (isset($block[$name])) {
                unset($this->proved[$depth][$name]);
            }
        }
    }

    /**
     * Whether the token stands among the statements of a block, or of
     * the file, and not in a bracket of an expression.
     */
    private function amongStatements(): bool
    {
        return [] === $this->frames || $this->frames[\count($this->frames) - 1][0] <= self::BLOCK;
    }

    /**
     * Whether the last token closed an array.
     */
    private function followsArray(): bool
    {
        return $this->closed[1] === $this->before[0] && (self::ARRAY_BRACKET === $this->closed[0] || self::ARRAY_PARENTHESIS === $this->closed[0]);
    }

    private function isNumberOrBoolean(int $index): bool
    {
        $token = $this->tokens[$index];
        if (!\is_array($token)) {
            return false;
        }

        return \T_LNUMBER === $token[0] || \T_DNUMBER === $token[0]
            || (\T_STRING === $token[0] && \in_array(Ascii::lower($token[1]), ['true', 'false'], true));
    }

    /**
     * What the innermost open bracket opens; null out of any.
     */
    private function kind(): ?int
    {
        return [] === $this->frames ? null : $this->frames[\count($this->frames) - 1][0];
    }

    private function id(int $index): int|string
    {
        $token = $this->tokens[$index];

        return \is_array($token) ? $token[0] : $token;
    }

    private function next(int $from): ?int
    {
        return PhpTokens::nextSignificant($this->tokens, $from, $this->count);
    }
}
