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

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Path;

/**
 * The class constants, the enum cases and the global constants of the
 * project's files, read once before the patterns: what a call may name
 * through a class or a constant its own file does not declare,
 * `preg_match(Patterns::EMAIL, $s)`. The functions the files declare in a
 * namespace under the name of one of PHP are read with them: a call of
 * that namespace reaches them first.
 *
 * Each file contributes its classes, each with whether it is final, and
 * their constants whose value the file can prove on its own: a literal, a
 * number, a constant, a concatenation or an array of those, a ternary, a
 * match, "?:" or "??" over those. A value that holds a variable, a
 * property or an expression the file does not follow is left out. A class
 * declared in two files is ambiguous and gives nothing; a project class
 * hides a library class of the same name.
 *
 * A global constant is one a "const" declares at the level of a
 * namespace, or a define() with a literal name standing alone at the top
 * level of its file: one declared in a branch, a loop or a function may
 * not be declared at all when a call runs. It is read when one
 * declaration of the project gives it; a project constant hides a library
 * one of the same name.
 *
 * A contribution is a plain array holding ValueExpr trees, so that it
 * travels from a worker process; each literal knows the file, the line and
 * the column it is written at, where the pattern is reported.
 *
 * @phpstan-type Contribution array{classes: array<string, bool|null>, constants: array<string, array{ValueExpr, bool}>, globals?: array<string, ValueExpr|null>, functions?: list<string>}
 *
 * @internal
 */
final class ProjectConstants
{
    /**
     * A text declaring a constant or a backed enum case: "const" followed
     * by a name, or "case" followed by a name and "=" (a switch's case
     * takes none).
     */
    private const DECLARES_CONSTANTS = '/\b(?:const\s+[A-Za-z_\x80-\xff]|case\s+[A-Za-z_\x80-\xff][\w\x80-\xff]*\s*=)/';

    /**
     * A text calling define().
     */
    private const CALLS_DEFINE = '/\bdefine\s*\(/i';

    /**
     * The keywords that open a block of the alternative syntax, "if
     * (...):", and those that close one, "endif".
     */
    private const ALTERNATIVE_BLOCKS = [
        \T_IF => true, \T_WHILE => true, \T_FOR => true, \T_FOREACH => true, \T_SWITCH => true, \T_DECLARE => true,
        \T_ENDIF => true, \T_ENDWHILE => true, \T_ENDFOR => true, \T_ENDFOREACH => true, \T_ENDSWITCH => true, \T_ENDDECLARE => true,
    ];

    /**
     * @param array<string, bool|null>              $classes   lowercase class => whether it is final; null when ambiguous
     * @param array<string, array{ValueExpr, bool}> $constants "class::NAME" => the value and whether the constant is final
     * @param array<string, ValueExpr|null>         $globals   constant key => the value; null when it is declared twice,
     *                                                         out of the top level of its file, or with a value its
     *                                                         file does not prove
     * @param array<string, true>                   $functions the lowercase functions the files declare in a namespace
     *                                                         under the name of a function of PHP the extraction reads
     */
    private function __construct(
        private readonly array $classes,
        private readonly array $constants,
        private readonly array $globals = [],
        private readonly array $functions = [],
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * The contribution of a file that declares nothing.
     *
     * @return Contribution
     */
    public static function nothing(): array
    {
        return ['classes' => [], 'constants' => []];
    }

    /**
     * The contribution of a file on the disk; one that cannot be read, or
     * is too large to tokenize in the memory left, contributes nothing.
     *
     * @return Contribution
     */
    public static function scanFile(string $file): array
    {
        $size = @filesize($file);
        $content = false !== $size && MemoryBudget::allowsSize($size, MemoryBudget::TOKENIZE_FACTOR) ? @file_get_contents($file) : false;

        return \is_string($content) ? self::scan($content, Path::fromHost($file)) : self::nothing();
    }

    /**
     * The contribution of a PHP source: its classes and the constants they
     * prove, its global constants and the functions of a namespace it
     * declares under the name of one of PHP, each literal placed in $file.
     *
     * @return Contribution
     */
    public static function scan(string $content, string $file): array
    {
        // A text declaring none of these contributes nothing, and is not
        // tokenized.
        $constants = 1 === LibraryPcre::match(self::DECLARES_CONSTANTS, $content);
        $defines = 1 === LibraryPcre::match(self::CALLS_DEFINE, $content);
        $functions = 1 === LibraryPcre::match(self::declaresFunctions(), $content);
        if (!$constants && !$defines && !$functions) {
            return self::nothing();
        }

        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return self::nothing();
        }

        // The classes, the interfaces, the enums and the traits are
        // visited: their members declare the constants and the cases, a
        // trait has no class to declare its own under. A "const" out of
        // them declares a global constant. The functions are visited only
        // when one may be named as one of PHP.
        $visited = [\T_CLASS => true, \T_INTERFACE => true, \T_ENUM => true, \T_TRAIT => true];
        if ($constants) {
            $visited[\T_CONST] = true;
        }

        if ($functions) {
            $visited[\T_FUNCTION] = true;
        }

        $tokens = token_get_all($content);
        $offsets = PhpTokens::offsets($tokens);
        $closers = PhpTokens::closers($tokens);
        $collector = new TokenValueCollector($tokens, $offsets, $closers, $content, [], false);
        $context = new NameResolutionContext();
        /** @var array<string, ValueExpr|null> $defined */
        $defined = [];
        // Where the block the token stands in ends, when it is no block
        // of a namespace: out of every such block is the top level.
        $nestedUntil = -1;
        $alternativeBlocks = 0;
        $namespaceBrace = -1;
        for ($i = 0, $totalTokens = \count($tokens); $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                if ('{' === $token && $i > $nestedUntil && $i !== $namespaceBrace) {
                    $nestedUntil = $closers[$i] ?? $totalTokens;
                }

                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $i = TokenNameResolver::readNamespace($tokens, $i, $totalTokens, $context);
                $namespaceBrace = '{' === $tokens[$i] ? $i : PhpTokens::nextSignificant($tokens, $i + 1, $totalTokens) ?? -1;

                continue;
            }

            if (\T_USE === $token[0]) {
                // An import moves on past its statement; a trait use, or a
                // closure's, stays where it is and declares nothing here.
                $i = TokenNameResolver::readUse($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (isset($visited[$token[0]])) {
                $collector->visit($i, $context);

                continue;
            }

            if (!$defines) {
                continue;
            }

            if (isset(self::ALTERNATIVE_BLOCKS[$token[0]])) {
                $alternativeBlocks += $i > $nestedUntil ? self::alternativeBlock($tokens, $i, $closers) : 0;

                continue;
            }

            // A call is named by a name: most tokens are none.
            $name = \T_STRING === $token[0] || \T_NAME_FULLY_QUALIFIED === $token[0] ? self::definedName($tokens, $i, $closers) : null;
            if (null !== $name) {
                $value = $i > $nestedUntil && 0 === $alternativeBlocks ? self::definedValue($tokens, $i, $closers, $collector, $context) : null;
                $key = ValueExpr::constantKey($name);
                $defined[$key] = \array_key_exists($key, $defined) ? null : $value;
            }
        }

        // A constant declared in a block a version guard keeps, a class
        // for the versions that have it say, is judged for those versions
        // wherever it is read: its literal carries the guards of its file,
        // which are read when a first value is kept.
        $guards = null;
        ['classes' => $classes, 'constants' => $declared] = $collector->values()->declarations();
        $kept = [];
        foreach ($declared as $key => [$value, $final]) {
            if (self::proves($value)) {
                $guards ??= TokenGuards::regions($tokens, $offsets, $closers, $content);
                $kept[$key] = [$value->inFile($file, $content, $guards), $final];
            }
        }

        // A constant "const" and define() both declare is declared twice.
        $globals = [];
        foreach ([$collector->values()->globalConstants(), $defined] as $declarations) {
            foreach ($declarations as $key => $value) {
                if (\array_key_exists($key, $globals) || null === $value || !self::proves($value)) {
                    $globals[$key] = null;

                    continue;
                }

                $guards ??= TokenGuards::regions($tokens, $offsets, $closers, $content);
                $globals[$key] = $value->inFile($file, $content, $guards);
            }
        }

        $named = [];
        foreach ($collector->values()->functions() as $function) {
            $separator = strrpos($function, '\\');
            if (false !== $separator && self::isFollowed(substr($function, $separator + 1))) {
                $named[] = $function;
            }
        }

        $contribution = ['classes' => $classes, 'constants' => $kept];
        if ([] !== $globals) {
            $contribution['globals'] = $globals;
        }

        if ([] !== $named) {
            $contribution['functions'] = $named;
        }

        return $contribution;
    }

    /**
     * The constants of the project: the project files' contributions,
     * then the libraries' for the classes the project does not declare.
     *
     * @param list<Contribution> $project
     * @param list<Contribution> $libraries
     */
    public static function of(array $project, array $libraries = []): self
    {
        [$classes, $constants, $globals, $functions] = self::merge($project);
        [$libraryClasses, $libraryConstants, $libraryGlobals, $libraryFunctions] = self::merge($libraries);
        // The library constants grouped by their class once: a library tree
        // declares thousands of classes and of constants, and each class
        // takes its own group, not a pass over every constant.
        $byClass = [];
        foreach ($libraryConstants as $key => $constant) {
            $byClass[substr($key, 0, (int) strrpos($key, '::'))][$key] = $constant;
        }

        foreach ($libraryClasses as $class => $final) {
            if (\array_key_exists($class, $classes)) {
                continue;
            }

            $classes[$class] = $final;
            $constants += $byClass[$class] ?? [];
        }

        // A constant of the project hides one a library declares.
        return new self($classes, $constants, $globals + $libraryGlobals, $functions + $libraryFunctions);
    }

    /**
     * Whether the project declares the class, once or more.
     */
    public function has(string $class): bool
    {
        return \array_key_exists($class, $this->classes);
    }

    /**
     * Whether the class is final; null when the project does not declare
     * it, or declares it twice.
     */
    public function isFinal(string $class): ?bool
    {
        return $this->classes[$class] ?? null;
    }

    /**
     * The value of a constant and whether it is final; null when the
     * project does not prove it, or declares its class twice.
     *
     * @return array{ValueExpr, bool}|null
     */
    public function constant(string $class, string $name): ?array
    {
        return null === ($this->classes[$class] ?? null) ? null : $this->constants[$class.'::'.$name] ?? null;
    }

    /**
     * Whether a project file declares a global constant under the key,
     * once or more, whatever its value.
     */
    public function declaresGlobal(string $key): bool
    {
        return \array_key_exists($key, $this->globals);
    }

    /**
     * The value of a global constant; null when the project does not
     * prove it: declared twice, out of the top level of its file, or
     * with a value its file does not prove.
     */
    public function globalConstant(string $key): ?ValueExpr
    {
        return $this->globals[$key] ?? null;
    }

    /**
     * Whether a file declares, in a namespace, the function of that
     * lowercase name: one named as a function of PHP the extraction
     * reads, which a call of the namespace reaches before PHP's own.
     */
    public function declaresFunction(string $name): bool
    {
        return isset($this->functions[$name]);
    }

    /**
     * Whether a file proves the value on its own, whatever the names it
     * reads: a name of another file is resolved when the value is.
     */
    private static function proves(ValueExpr $value): bool
    {
        if (\in_array($value->kind, [ValueExpr::LITERAL, ValueExpr::NON_STRING, ValueExpr::CLASS_CONSTANT, ValueExpr::CONSTANT], true)) {
            return true;
        }

        if (!\in_array($value->kind, [ValueExpr::CONCAT, ValueExpr::ARRAY, ValueExpr::SET, ValueExpr::FALLBACK], true)) {
            return false;
        }

        foreach (self::children($value) as $child) {
            if (!self::proves($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The operands of a concatenation, the two sides of a fallback, the
     * arms of a ternary or of a match, or the keys and the values of an
     * array.
     *
     * @return list<ValueExpr>
     */
    private static function children(ValueExpr $value): array
    {
        $children = [...$value->parts, ...$value->alternatives];
        foreach ($value->items as [$key, $item]) {
            if (null !== $key) {
                $children[] = $key;
            }

            $children[] = $item;
        }

        return $children;
    }

    /**
     * The classes, the constants, the global constants and the functions
     * of several contributions as one: a class two of them declare is
     * ambiguous, and so is a global constant.
     *
     * @param list<Contribution> $contributions
     *
     * @return array{array<string, bool|null>, array<string, array{ValueExpr, bool}>, array<string, ValueExpr|null>, array<string, true>}
     */
    private static function merge(array $contributions): array
    {
        $classes = [];
        $constants = [];
        $globals = [];
        $functions = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution['classes'] as $class => $final) {
                $classes[$class] = \array_key_exists($class, $classes) ? null : $final;
            }

            $constants += $contribution['constants'];
            foreach ($contribution['globals'] ?? [] as $key => $value) {
                $globals[$key] = \array_key_exists($key, $globals) ? null : $value;
            }

            foreach ($contribution['functions'] ?? [] as $function) {
                $functions[$function] = true;
            }
        }

        return [$classes, $constants, $globals, $functions];
    }

    /**
     * Matches a text declaring a function under the name of one of PHP
     * the extraction reads a call of: one that calls a closure and lets
     * it go, or that takes its arguments by value.
     */
    private static function declaresFunctions(): string
    {
        static $pattern = null;

        return $pattern ??= '/\bfunction\s*&?\s*(?:'.implode('|', array_keys(InFileValueResolver::CALLING + InFileValueResolver::BY_VALUE)).')\s*\(/i';
    }

    /**
     * Whether the extraction reads a call of the function of PHP of that
     * lowercase name.
     */
    private static function isFollowed(string $name): bool
    {
        return isset(InFileValueResolver::CALLING[$name]) || isset(InFileValueResolver::BY_VALUE[$name]);
    }

    /**
     * What the token at $index, one of ALTERNATIVE_BLOCKS, does to the
     * blocks of the alternative syntax it stands in: 1 when it opens one,
     * "if (...):", -1 when it closes one, "endif", 0 for a keyword whose
     * body is no such block.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function alternativeBlock(array $tokens, int $index, array $closers): int
    {
        $token = $tokens[$index];
        $id = \is_array($token) ? $token[0] : null;
        if (\in_array($id, [\T_ENDIF, \T_ENDWHILE, \T_ENDFOR, \T_ENDFOREACH, \T_ENDSWITCH, \T_ENDDECLARE], true)) {
            return -1;
        }

        $count = \count($tokens);
        $open = PhpTokens::nextSignificant($tokens, $index + 1, $count);
        $close = null !== $open && '(' === $tokens[$open] ? $closers[$open] ?? null : null;
        $after = null === $close ? null : PhpTokens::nextSignificant($tokens, $close + 1, $count);

        return null !== $after && ':' === $tokens[$after] ? 1 : 0;
    }

    /**
     * The name a call to define() at $index gives its constant, when a
     * string literal gives it: define('NAME', ...), \define("NAME", ...).
     * Null for any other token, for a method or a function of another
     * name, and for a name the file does not spell.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function definedName(array $tokens, int $index, array $closers): ?string
    {
        $name = PhpTokens::name($tokens[$index]);
        if (null === $name || 'define' !== Ascii::lower(ltrim($name, '\\')) || substr_count($name, '\\') > (int) str_starts_with($name, '\\')) {
            return null;
        }

        $count = \count($tokens);
        $open = PhpTokens::nextSignificant($tokens, $index + 1, $count);
        $before = PhpTokens::previousSignificant($tokens, $index - 1);
        $beforeToken = null === $before ? null : $tokens[$before];
        if (null === $open || '(' !== $tokens[$open] || !isset($closers[$open])
            || (null !== $beforeToken && (PhpTokens::isObjectOrStaticOperator($beforeToken) || (\is_array($beforeToken) && \in_array($beforeToken[0], [\T_FUNCTION, \T_NEW, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))))) {
            return null;
        }

        $first = PhpTokens::nextSignificant($tokens, $open + 1, $closers[$open]);
        $firstToken = null === $first ? null : $tokens[$first];
        $comma = null === $first ? null : PhpTokens::nextSignificant($tokens, $first + 1, $closers[$open]);
        if (!\is_array($firstToken) || \T_CONSTANT_ENCAPSED_STRING !== $firstToken[0] || null === $comma || ',' !== $tokens[$comma]) {
            return null;
        }

        $constant = PhpTokens::stringValue($firstToken[1]);

        return '' === $constant || str_starts_with($constant, '\\') ? null : $constant;
    }

    /**
     * The value a call to define() at $index gives its constant, when
     * the call stands alone as a statement, one that follows another or
     * opens the file or a namespace, and passes its value as its second
     * and last argument. Null for a call a condition guards, "if
     * (!defined('X')) define(...)" or "defined('X') || define(...)", and
     * for a value a spread or a name passes.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     */
    private static function definedValue(array $tokens, int $index, array $closers, TokenValueCollector $collector, NameResolutionContext $context): ?ValueExpr
    {
        $count = \count($tokens);
        $before = PhpTokens::previousSignificant($tokens, $index - 1);
        $beforeToken = null === $before ? null : $tokens[$before];
        if (null !== $beforeToken && !\in_array($beforeToken, [';', '{', '}'], true) && !(\is_array($beforeToken) && \T_OPEN_TAG === $beforeToken[0])) {
            return null;
        }

        // definedName() found the parentheses and the comma after the name.
        $open = PhpTokens::nextSignificant($tokens, $index + 1, $count) ?? $index;
        $close = $closers[$open] ?? $open;
        $after = PhpTokens::nextSignificant($tokens, $close + 1, $count);
        if (null !== $after && ';' !== $tokens[$after] && !(\is_array($tokens[$after]) && \T_CLOSE_TAG === $tokens[$after][0])) {
            return null;
        }

        $comma = null;
        for ($i = $open + 1; $i < $close; $i++) {
            if (',' === $tokens[$i]) {
                // A second comma that ends the list is no third argument.
                if (null !== $comma) {
                    return null === PhpTokens::nextSignificant($tokens, $i + 1, $close) ? $collector->expression($comma + 1, $i, $context) : null;
                }

                $comma = $i;
            } elseif (PhpTokens::opensNesting($tokens[$i])) {
                $i = $closers[$i] ?? $close;
            }
        }

        $value = null === $comma ? null : PhpTokens::nextSignificant($tokens, $comma + 1, $close);
        $valueToken = null === $value ? null : $tokens[$value];
        $named = null === $value ? null : PhpTokens::nextSignificant($tokens, $value + 1, $close);
        if (null === $comma || null === $value || (\is_array($valueToken) && \T_ELLIPSIS === $valueToken[0])
            || (null !== $named && ':' === $tokens[$named] && null !== PhpTokens::identifier($valueToken ?? ''))) {
            return null;
        }

        return $collector->expression($comma + 1, $close, $context);
    }
}
