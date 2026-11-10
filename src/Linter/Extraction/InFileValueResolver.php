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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Resolution\Hole;
use PHPRegex\Parser\Resolution\PatternArgument;
use PHPRegex\Parser\Text\Ascii;

/**
 * What one PHP file proves about the values its names hold, and the
 * patterns a call reads through them.
 *
 * Each extraction strategy reads the file its own way and declares here the
 * constants, the properties and the assignments it meets, and every way a
 * variable or a property may hold a value no literal gives; this class then
 * decides alone what a name proves, so both strategies read the same
 * patterns:
 *
 * - a constant of the file: self::X and ClassName::X of a class (no trait)
 *   the file declares, static::X when the class or the constant is final,
 *   a namespace "const" or a define() with a literal name; an unqualified
 *   name tries the namespace, then the global constant;
 * - a property read as $this->p: a literal default, private, or protected,
 *   in a final class or final itself, never promoted, and never written in
 *   the file;
 * - a local variable: every assignment of the function body that may
 *   reach the read a value the file proves, and no way of writing it
 *   otherwise (a parameter, a reference, a compound assignment, global,
 *   static, extract(), $$, a destructuring, a foreach or catch target, a
 *   call it is passed to). It is read alone, as an operand, in an
 *   interpolated string, or through another variable assigned from it.
 *
 * A value is read as the values it may take, each a list of parts: the
 * text the file proves, and a hole for each part it does not, a text
 * known at run time only. A concatenation and an interpolated string keep
 * what is written around a part the file does not prove; preg_quote() is
 * a hole whose text reads as literal characters; an integer joined to a
 * string reads as PHP writes it. A ternary and a match are the values of
 * their arms; "A ?: B" and "A ?? B" the values PHP keeps of them;
 * sprintf() and implode() the string PHP builds of what the file proves.
 * Every pattern argument ends in one state, from one pattern to nothing
 * static.
 *
 * What a composition multiplies is bounded by PatternArgument::concat():
 * one operand of several values gives as many patterns, each one the code
 * builds; two of them, or more values than
 * PatternArgument::MAX_CANDIDATES, and nothing is known of the argument.
 * Folding follows 32 constants at most and stops on a cycle.
 *
 * A pattern that is one literal is reported once, where the literal is
 * written; one composed in the argument, where the argument starts; one
 * composed in the value of a name, where that composition is written.
 *
 * @internal
 */
final class InFileValueResolver
{
    /**
     * The constants one value may go through.
     */
    public const MAX_DEPTH = 32;

    /**
     * The longest string a concatenation folds to: constants that double
     * each other reach any size in a few lines, so past it nothing is read.
     */
    public const MAX_LENGTH = 65536;

    /**
     * The function of PHP whose result is a quoted text.
     */
    public const QUOTING = 'preg_quote';

    /**
     * The function of PHP whose result is its format, each place filled.
     */
    public const FORMATTING = 'sprintf';

    /**
     * The functions of PHP whose result is the items of an array, joined.
     */
    public const JOINING = ['implode' => true, 'join' => true];

    /**
     * The functions of PHP known to take every argument by value: a
     * variable passed to one of them keeps the value the file proves.
     */
    public const BY_VALUE = ['preg_quote' => true, 'sprintf' => true, 'implode' => true, 'join' => true];

    /**
     * What a call may write of what it is passed.
     */
    private const WRITES_VARIABLE = 0;
    private const WRITES_PROPERTY = 1;
    private const WRITES_ANY_PROPERTY = 2;

    /**
     * The constants of the project's other files, for a class the file
     * does not declare; none when the file is read on its own.
     */
    private ?ProjectConstants $project = null;

    /**
     * @var array<string, bool|null> lowercase class => whether it is final; null when the file declares it twice
     */
    private array $classes = [];

    /**
     * @var array<string, true> the lowercase traits the file declares
     */
    private array $traits = [];

    /**
     * @var array<string, list<string>> lowercase class or trait => the lowercase traits it uses
     */
    private array $traitUses = [];

    /**
     * @var array<string, array{ValueExpr, bool}> "class::NAME" => the value and whether the constant is final
     */
    private array $classConstants = [];

    /**
     * @var array<string, ValueExpr|null> constant key => the value; null when the file declares it twice
     */
    private array $constants = [];

    /**
     * @var array<string, ValueExpr|null> "class::name" => the default; null when the property proves nothing
     */
    private array $properties = [];

    /**
     * @var array<string, true> the property names the file writes, on any object
     */
    private array $writtenProperties = [];

    /**
     * @var array<string, true> the classes a property is written in under a computed name
     */
    private array $classesWritten = [];

    /**
     * Whether a property is written under a computed name out of any class
     * of the file: a closure may be bound to any of them.
     */
    private bool $anyClassWritten = false;

    /**
     * @var array<int, true> the function bodies any of whose variables may be written unseen
     */
    private array $opaqueScopes = [];

    /**
     * @var array<int, array<string, true>> scope => the variables written otherwise than by a plain assignment
     */
    private array $bailed = [];

    /**
     * @var array<int, array<string, list<array{ValueExpr, int}>>> scope => variable => the values assigned to it,
     *                                                             each with where it is assigned
     */
    private array $assignments = [];

    /**
     * @var array<int, list<array{int, int}>> scope => the byte ranges of its loops
     */
    private array $loops = [];

    /**
     * @var array<int, true> the function bodies a goto may run again
     */
    private array $jumpingScopes = [];

    /**
     * @var array<int, array<string, int>> scope => variable => where it is last read as a pattern; -1 for
     *                                     somewhere unknown
     */
    private array $lastReads = [];

    /**
     * @var array<int, array<string, int>> scope => variable => where it is last read at all, as a pattern or
     *                                     as a part of one; -1 for somewhere unknown
     */
    private array $lastUses = [];

    /**
     * @var array<int, array<string, true>> scope => the variables whose assigned values were read for the
     *                                      variables they name
     */
    private array $followed = [];

    /**
     * @var array<string, list<ValueExpr>|null> "scope|name|read" => the values the variable holds where it is last
     *                                          read; null when no assignment reaches the read
     */
    private array $variableValues = [];

    /**
     * How deep the reading is in the value of a constant or in the
     * default of a property: a constant expression, in which a variable,
     * a branch or a call is a part the file does not prove.
     */
    private int $constantExpressions = 0;

    /**
     * @var array<string, list<string>> the states of the arguments a variable is for a function, "scope|name|function"
     */
    private array $variableStates = [];

    /**
     * @var array<string, true> the lowercase functions the file declares
     */
    private array $functions = [];

    /**
     * @var list<string>|null the functions the call being read may reach, one of BY_VALUE unless the file
     *                        declares one of them; null out of such a call
     */
    private ?array $callee = null;

    /**
     * @var list<array{list<string>, int, int|string|null, string}> the writes of the calls that may reach a
     *                                                              function of BY_VALUE, each with the functions its
     *                                                              call may reach: which write, its scope or its
     *                                                              class, its name
     */
    private array $passed = [];

    /**
     * How many times a guard on the depth or a cycle cut a value short: a
     * constant folded while it grew holds the value from there, not its
     * own.
     */
    private int $cuts = 0;

    /**
     * @var array<string, true> the variables already read for a function, "scope|name|function"
     */
    private array $variablesRead = [];

    /**
     * @var array<string, array{list<ValueExpr>, int}> constant => its folded values and the constants they went through
     */
    private array $folded = [];

    /**
     * @var array<string, true> the constants being folded
     */
    private array $folding = [];

    /**
     * @var array<string, array{int, list<ValueExpr>, int}> constant => the depth its reading was cut short from, what
     *                                                      it gave from there and the constants it went through
     */
    private array $cutShort = [];

    /**
     * @var array<string, array<int, array<string, ValueExpr>>> file ("" for this one) => offset => pattern => the literal already reported
     */
    private array $reported = [];

    /**
     * Resolve a class the file does not declare through the project's
     * constants: the file's own class of a name always wins.
     */
    public function useProject(ProjectConstants $project): void
    {
        $this->project = $project;
    }

    /**
     * What the file declared so far, as its contribution to the project's
     * constants: its classes, each with whether it is final (null when
     * declared twice), and their constants, the enum cases among them.
     *
     * @return array{classes: array<string, bool|null>, constants: array<string, array{ValueExpr, bool}>}
     */
    public function declarations(): array
    {
        return ['classes' => $this->classes, 'constants' => $this->classConstants];
    }

    public function declareClass(string $class, bool $final): void
    {
        $this->classes[$class] = \array_key_exists($class, $this->classes) ? null : $final;
    }

    /**
     * @param string $trait the lowercase name of a trait the file declares
     */
    public function declareTrait(string $trait): void
    {
        $this->traits[$trait] = true;
    }

    /**
     * A class or a trait of the file using a trait: one the file does not
     * declare may write any of its properties.
     *
     * @param string $user  the lowercase name of the class or trait
     * @param string $trait the lowercase name of the trait it uses
     */
    public function useTrait(string $user, string $trait): void
    {
        $this->traitUses[$user][] = $trait;
    }

    public function declareClassConstant(string $class, string $name, ValueExpr $value, bool $final): void
    {
        $this->classConstants[$class.'::'.$name] = [$value, $final];
    }

    /**
     * A backed enum case, as the constant its "->value" read names: a case
     * cannot be redeclared, so it proves as a final constant does.
     *
     * @param string $enum the lowercase name of the enum
     */
    public function declareEnumCase(string $enum, string $name, ValueExpr $value): void
    {
        $this->declareClassConstant($enum, ValueExpr::enumCaseName($name), $value, true);
    }

    /**
     * @param string $name the constant's fully qualified name
     */
    public function declareConstant(string $name, ValueExpr $value): void
    {
        $key = ValueExpr::constantKey($name);
        // PHP keeps its own value for a constant it defines.
        $this->constants[$key] = \array_key_exists($key, $this->constants) || isset(self::predefinedConstants()[$key]) ? null : $value;
    }

    /**
     * A function the file declares, by its fully qualified name: a call
     * that reaches it is not a call to the function of PHP of that name.
     */
    public function declareFunction(string $name): void
    {
        $this->functions[Ascii::lower(ltrim($name, '\\'))] = true;
    }

    /**
     * @param ValueExpr|null $default the literal default; null when the property may hold another value
     */
    public function declareProperty(string $class, string $name, ?ValueExpr $default): void
    {
        $this->properties[$class.'::'.$name] = $default;
    }

    /**
     * The writes declared while $writes runs are those of a call that
     * reaches a function of BY_VALUE unless the file declares one of the
     * functions PHP tries for it: they count only then, which is known
     * once the whole file is read.
     *
     * @param list<string>     $names  the lowercase functions the call may reach, in the order PHP tries them
     * @param \Closure(): void $writes declares what the call would write, were it to take a reference
     */
    public function byValue(array $names, \Closure $writes): void
    {
        $this->callee = $names;
        $writes();
        $this->callee = null;
    }

    public function writeProperty(string $name): void
    {
        if (null !== $this->callee) {
            $this->passed[] = [$this->callee, self::WRITES_PROPERTY, null, $name];

            return;
        }

        $this->writtenProperties[$name] = true;
    }

    /**
     * A property written under a name the file does not spell ($o->$name,
     * $o->{...}) in the class, or out of any class when null: none of its
     * properties is read.
     */
    public function writeAnyProperty(?string $class): void
    {
        if (null !== $this->callee) {
            $this->passed[] = [$this->callee, self::WRITES_ANY_PROPERTY, $class, ''];

            return;
        }

        if (null === $class) {
            $this->anyClassWritten = true;
        } else {
            $this->classesWritten[$class] = true;
        }
    }

    /**
     * @param int $at where the assignment is written
     */
    public function assign(int $scope, string $name, ValueExpr $value, int $at): void
    {
        $this->assignments[$scope][$name][] = [$value, $at];
    }

    /**
     * A loop of the body, from its keyword to its last byte: what it holds
     * may run again after what follows in it.
     */
    public function loop(int $scope, int $start, int $end): void
    {
        $this->loops[$scope][] = [$start, $end];
    }

    /**
     * A goto in the body, or a loop whose end is not known: anything in
     * it may run again after anything else.
     */
    public function jumps(int $scope): void
    {
        $this->jumpingScopes[$scope] = true;
    }

    /**
     * Say where each variable is read, before the patterns are asked for:
     * a value assigned after the last read never reaches it. A variable is
     * read as a pattern, where an argument is the variable alone, or as a
     * part of one: an operand of the argument, or of the value assigned to
     * a variable that is read.
     */
    public function read(ValueExpr $expr): void
    {
        if (ValueExpr::VARIABLE === $expr->kind && null !== $expr->scope) {
            $last = $this->lastReads[$expr->scope][$expr->value] ?? null;
            $this->lastReads[$expr->scope][$expr->value] = $expr->offset < 0 || -1 === $last ? -1 : max($last ?? 0, $expr->offset);
        }

        // Each expression is walked once, and the values assigned to a
        // variable once for all its reads.
        $pending = [$expr];
        while ([] !== $pending) {
            $current = array_pop($pending);
            foreach ($current->parts as $part) {
                $pending[] = $part;
            }

            foreach ($current->alternatives as $alternative) {
                $pending[] = $alternative;
            }

            foreach ($current->items as [$key, $item]) {
                if (null !== $key) {
                    $pending[] = $key;
                }

                $pending[] = $item;
            }

            $scope = $current->scope;
            if (ValueExpr::VARIABLE !== $current->kind || null === $scope) {
                continue;
            }

            $name = $current->value;
            $last = $this->lastUses[$scope][$name] ?? null;
            $this->lastUses[$scope][$name] = $current->offset < 0 || -1 === $last ? -1 : max($last ?? 0, $current->offset);
            if (isset($this->followed[$scope][$name])) {
                continue;
            }

            $this->followed[$scope][$name] = true;
            foreach ($this->assignments[$scope][$name] ?? [] as [$assigned]) {
                $pending[] = $assigned;
            }
        }
    }

    public function bail(int $scope, string $name): void
    {
        if (null !== $this->callee) {
            $this->passed[] = [$this->callee, self::WRITES_VARIABLE, $scope, $name];

            return;
        }

        $this->bailed[$scope][$name] = true;
    }

    public function bailScope(int $scope): void
    {
        $this->opaqueScopes[$scope] = true;
    }

    /**
     * The patterns a call reads through the expression and that were not
     * reported yet, each where its literal is written, with the name the
     * call reaches it through: "via self::RE", "via $re"; none for a
     * pattern written or composed in the argument. One composed in the
     * argument stands where the argument starts. An argument one of whose
     * values is no whole pattern gives none. The patterns reported at one
     * place come in the order of their bytes.
     *
     * @return list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null}> each with the file, the column and the guard of its literal when it is another file's
     */
    public function patterns(ValueExpr $expr, PatternFunction $function): array
    {
        // A variable read again the same way gives the patterns its first
        // read reported: none is new.
        if (ValueExpr::VARIABLE === $expr->kind) {
            $key = ($expr->scope ?? -1).'|'.$expr->value.'|'.$function->patternSuffix.'|'.implode("\0", $function->optionPath).'|'.(int) $function->optionPathRequired;
            if (isset($this->variablesRead[$key])) {
                return [];
            }

            $this->variablesRead[$key] = true;
        }

        $patterns = [];
        foreach ($this->arguments($expr, $function) as $alternatives) {
            foreach ($alternatives as [$alternative]) {
                if (ValueExpr::LITERAL !== $alternative->kind) {
                    continue 2;
                }
            }

            foreach ($alternatives as [$literal, $location]) {
                if ('' === $literal->value) {
                    continue;
                }

                $pattern = $literal->value.$function->patternSuffix;
                // A literal of another file is told from one of the file
                // at the same offset: each file keeps its own.
                if (isset($this->reported[$literal->file ?? ''][$literal->offset][$pattern])) {
                    continue;
                }

                $this->reported[$literal->file ?? ''][$literal->offset][$pattern] = $literal;
                $patterns[] = ['pattern' => $pattern, 'offset' => $literal->offset, 'line' => $literal->line, 'location' => $location, 'file' => $literal->file, 'column' => $literal->column, 'guard' => $literal->guard];
            }
        }

        return self::inByteOrder($patterns);
    }

    /**
     * What is known of each argument the call reads through the
     * expression, as the values of ArgumentState: one argument, or, for an
     * array the file proves, one for each item the call reads a pattern
     * from.
     *
     * @return list<string>
     */
    public function states(ValueExpr $expr, PatternFunction $function): array
    {
        if (ValueExpr::VARIABLE !== $expr->kind) {
            return $this->statesOf($expr, $function);
        }

        // A variable read many times holds the same values at each read.
        $key = ($expr->scope ?? -1).'|'.$expr->value.'|'.implode("\0", $function->optionPath).'|'.(int) $function->optionPathRequired.'|'.(int) $function->keysArePatterns.'|'.($function->replacementIndex ?? '');

        return $this->variableStates[$key] ??= $this->statesOf($expr, $function);
    }

    /**
     * The constants PHP defines in this process, the core's and the
     * extensions' ones, the user's left out.
     *
     * @return array<string, true>
     */
    private static function predefinedConstants(): array
    {
        static $names = null;
        if (null === $names) {
            $names = [];
            foreach (get_defined_constants(true) as $category => $constants) {
                if ('user' !== $category) {
                    $names += array_fill_keys(array_keys($constants), true);
                }
            }
        }

        return $names;
    }

    /**
     * A part of a value the file does not prove, as a value: a hole that
     * is not quoted.
     */
    private static function rawHole(): ValueExpr
    {
        static $hole = null;

        return $hole ??= ValueExpr::hole(false, null, '');
    }

    /**
     * The same, as a part of a pattern argument.
     */
    private static function unread(): Hole
    {
        static $hole = null;

        return $hole ??= new Hole(false, null);
    }

    /**
     * The patterns reported at one place in the order of their bytes,
     * each place keeping its rank among the others: the patterns an
     * argument composes are listed the same way on every run.
     *
     * @param list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null}> $patterns
     *
     * @return list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null}>
     */
    private static function inByteOrder(array $patterns): array
    {
        $places = [];
        foreach ($patterns as $index => $pattern) {
            $places[($pattern['file'] ?? '').'|'.$pattern['offset']][] = $index;
        }

        foreach ($places as $indexes) {
            if (1 === \count($indexes)) {
                continue;
            }

            $here = [];
            foreach ($indexes as $index) {
                $here[] = $patterns[$index];
            }

            usort($here, static fn (array $a, array $b): int => strcmp($a['pattern'], $b['pattern']));
            foreach ($indexes as $rank => $index) {
                $patterns[$index] = $here[$rank];
            }
        }

        return $patterns;
    }

    /**
     * The pieces of a sprintf() format that holds only "%s", "%d" and
     * "%%": its text, and for each place whether it takes an integer.
     * Null for a format holding any other directive, or a "%" that ends
     * it.
     *
     * @return list<string|bool>|null
     */
    private static function places(string $format): ?array
    {
        $pieces = [];
        $text = '';
        $from = 0;
        while (false !== $percent = strpos($format, '%', $from)) {
            $text .= substr($format, $from, $percent - $from);
            $directive = $format[$percent + 1] ?? '';
            $from = $percent + 2;
            if ('%' === $directive) {
                $text .= '%';

                continue;
            }

            if ('s' !== $directive && 'd' !== $directive) {
                return null;
            }

            $pieces[] = $text;
            $pieces[] = 'd' === $directive;
            $text = '';
        }

        $pieces[] = $text.substr($format, $from);

        return $pieces;
    }

    /**
     * What "A ?: B" and "A ?? B" give of the values of A, as PHP does:
     * "?:" passes over '' and '0' for the values of B, "??" over none, a
     * string being never null. None at all when a value of A is no
     * string the file proves: whether PHP keeps it is not known. A
     * variable is not proved to be assigned on every path, and PHP takes B
     * for one that is not: its values are kept, and those of B beside them.
     *
     * @param list<array{ValueExpr, string|null}>             $first      the values of A, each with the name it is
     *                                                                    read through
     * @param \Closure(): list<array{ValueExpr, string|null}> $second     the values of B, asked for only when one
     *                                                                    of A is passed over
     * @param bool                                            $coalescing whether the operator is "??"
     * @param bool                                            $mayBeUnset whether A is a variable
     *
     * @return list<array{ValueExpr, string|null}>
     */
    private static function kept(array $first, \Closure $second, bool $coalescing, bool $mayBeUnset): array
    {
        $kept = [];
        $passedOver = $mayBeUnset;
        foreach ($first as $entry) {
            $value = $entry[0];
            if (ValueExpr::LITERAL !== $value->kind) {
                return [];
            }

            if (!$coalescing && ('' === $value->value || '0' === $value->value)) {
                $passedOver = true;

                continue;
            }

            $kept[] = $entry;
        }

        if (!$passedOver) {
            return $kept;
        }

        $fallback = $second();

        return [] === $fallback ? [] : [...$kept, ...$fallback];
    }

    /**
     * The parts of a folded value: its text, and a hole for each part
     * known at run time only. A scalar that is no string is such a part,
     * but an integer joined to a string, which reads as PHP writes it; an
     * array is one too.
     *
     * @param bool $operand whether the value is joined to others, as an operand of a concatenation
     *
     * @return list<string|Hole>
     */
    private static function parts(ValueExpr $value, bool $operand): array
    {
        switch ($value->kind) {
            case ValueExpr::LITERAL:
                return [$value->value];

            case ValueExpr::NON_STRING:
                return $operand && null !== $value->integer ? [(string) $value->integer] : [self::unread()];

            case ValueExpr::HOLE:
                return [$value->quoted ? new Hole(true, $value->delimiter) : self::unread()];

            case ValueExpr::CONCAT:
                $parts = [];
                foreach ($value->parts as $part) {
                    foreach (self::parts($part, true) as $piece) {
                        $parts[] = $piece;
                    }
                }

                return $parts;

            default:
                return [self::unread()];
        }
    }

    /**
     * @return list<string>
     */
    private function statesOf(ValueExpr $expr, PatternFunction $function): array
    {
        $states = [];
        foreach ($this->arguments($expr, $function) as $alternatives) {
            $states[] = PatternArgument::state(array_map(static fn (array $alternative): array => self::parts($alternative[0], false), $alternatives))->value;
        }

        return $states;
    }

    /**
     * The arguments a call reads through the expression, each as the
     * values it may take: one argument, or, for an array a name holds, one
     * for each item the call reads a pattern from. An array written where
     * a pattern is read is no pattern: PHP reads it as "Array". None when
     * the call reads its pattern from an option the value is known not to
     * hold. An argument with no value is one nothing is known of. Each
     * value comes with the name the call reads it through, "via $re", or
     * null for one written or composed in the argument.
     *
     * @return list<list<array{ValueExpr, string|null}>>
     */
    private function arguments(ValueExpr $expr, PatternFunction $function): array
    {
        $this->settleCalls();

        $values = $this->written($expr, $expr);
        if ([] === $values) {
            return [[]];
        }

        $arguments = [];
        $strings = [];
        $scalars = [];
        foreach ($values as [$value, $via]) {
            $value = $this->optionValue($value, $function);
            if (null === $value) {
                continue;
            }

            if (ValueExpr::ARRAY === $value->kind && null !== $via) {
                foreach ($this->arrayPatterns($value, $function) as $item) {
                    $arguments[] = array_map(static fn (ValueExpr $alternative): array => [$alternative, $via], ValueExpr::SET === $item->kind ? $item->alternatives : [$item]);
                }

                continue;
            }

            foreach (ValueExpr::SET === $value->kind ? $value->alternatives : [$value] as $alternative) {
                if (ValueExpr::NON_STRING === $alternative->kind) {
                    $scalars[] = [$alternative, $via];
                } else {
                    $strings[] = [$alternative, $via];
                }
            }
        }

        // A value that is no string beside others, false or 0 where no
        // pattern is set yet, is passed over: the call is read for the
        // patterns the name may hold.
        $alternatives = [] === $strings ? $scalars : $strings;

        return [] === $alternatives ? $arguments : [$alternatives, ...$arguments];
    }

    /**
     * The values of an expression written in an argument, each with the
     * name it is read through. A name gives the values it holds, reported
     * where they are written; a ternary, a match, "?:" and "??" give those
     * of their arms, each read the same way; anything else is composed in
     * the argument, and a pattern it gives is reported where the argument
     * starts. None when nothing is known of one of them.
     *
     * @param ValueExpr $argument the whole argument
     *
     * @return list<array{ValueExpr, string|null}>
     */
    private function written(ValueExpr $expr, ValueExpr $argument): array
    {
        switch ($expr->kind) {
            case ValueExpr::VARIABLE:
                // The argument itself is read as a pattern, an arm as a
                // part of one.
                $values = $expr === $argument ? $this->variableValues($expr, false) ?? [] : $this->variableValues($expr, true) ?? [self::rawHole()];

                break;
            case ValueExpr::PROPERTY:
                $values = $this->propertyValues($expr);

                break;
            case ValueExpr::CLASS_CONSTANT:
            case ValueExpr::CONSTANT:
                $values = $this->values($expr);

                break;
            case ValueExpr::SET:
                $arms = [];
                foreach ($expr->alternatives as $alternative) {
                    $arm = $this->written($alternative, $argument);
                    if ([] === $arm) {
                        return [];
                    }

                    foreach ($arm as $entry) {
                        $arms[] = $entry;
                    }
                }

                return $arms;
            case ValueExpr::FALLBACK:
                return self::kept($this->written($expr->parts[0], $argument), fn (): array => $this->written($expr->parts[1], $argument), '??' === $expr->value, ValueExpr::VARIABLE === $expr->parts[0]->kind);
            default:
                $composed = [];
                foreach ($this->values($expr) as $value) {
                    // A pattern composed in an arm is composed in the
                    // argument all the same.
                    $moved = $expr !== $argument && ValueExpr::LITERAL !== $expr->kind && ValueExpr::LITERAL === $value->kind;
                    $composed[] = [$moved ? ValueExpr::literal($value->value, $argument->offset, $argument->line) : $value, null];
                }

                return $composed;
        }

        $via = 'via '.$expr->written;
        $read = [];
        foreach ($values as $value) {
            $read[] = [$value, $via];
        }

        return $read;
    }

    /**
     * Counts the writes of the calls that reach a function of the file,
     * and drops those of the calls that reach a function of PHP: every
     * declaration of the file is read by the time a value is asked for.
     */
    private function settleCalls(): void
    {
        $passed = $this->passed;
        $this->passed = [];
        foreach ($passed as [$names, $write, $owner, $name]) {
            if ($this->isNative($names)) {
                continue;
            }

            if (self::WRITES_VARIABLE === $write && \is_int($owner)) {
                $this->bail($owner, $name);
            } elseif (self::WRITES_PROPERTY === $write) {
                $this->writeProperty($name);
            } elseif (self::WRITES_ANY_PROPERTY === $write && !\is_int($owner)) {
                $this->writeAnyProperty($owner);
            }
        }
    }

    /**
     * Whether a call reaches a function of PHP: the file declares none of
     * the functions PHP tries for it.
     *
     * @param list<string> $names the lowercase functions the call may reach, in the order PHP tries them
     */
    private function isNative(array $names): bool
    {
        foreach ($names as $name) {
            if (isset($this->functions[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a call reads its pattern from in a value: the value itself, or,
     * for an array of options, the item its option path leads to (the last
     * of a repeated key, as PHP keeps it). Null when the path leads nowhere.
     */
    private function optionValue(ValueExpr $value, PatternFunction $function): ?ValueExpr
    {
        if ([] === $function->optionPath || ValueExpr::ARRAY !== $value->kind) {
            return [] !== $function->optionPath && $function->optionPathRequired ? null : $value;
        }

        foreach ($function->optionPath as $key) {
            $found = null;
            foreach (ValueExpr::ARRAY === $value->kind ? $value->items : [] as [$itemKey, $item]) {
                if (null !== $itemKey && ValueExpr::LITERAL === $itemKey->kind && $key === $itemKey->value) {
                    $found = $item;
                }
            }

            if (null === $found) {
                return null;
            }

            $value = $found;
        }

        return $value;
    }

    /**
     * The values the expression may take, read from the call.
     *
     * @return list<ValueExpr>
     */
    private function values(ValueExpr $expr): array
    {
        $height = 0;

        return $this->resolve($expr, 0, $height);
    }

    /**
     * Every value the assignments of the variable that may reach its reads
     * give it: a hole for one the file does not prove, and for a variable
     * that may be written unseen; null when no assignment reaches them.
     * Read once per variable for its reads as a pattern, and once more for
     * its reads as a part of one when some come later.
     *
     * @param bool $part whether the variable is read as a part of a pattern, not as the pattern itself
     *
     * @return list<ValueExpr>|null
     */
    private function variableValues(ValueExpr $variable, bool $part): ?array
    {
        $scope = $variable->scope;
        $name = $variable->value;
        if (null === $scope || isset($this->opaqueScopes[$scope]) || isset($this->bailed[$scope][$name])) {
            return [self::rawHole()];
        }

        $read = ($part ? $this->lastUses : $this->lastReads)[$scope][$name] ?? -1;
        $key = $scope.'|'.$name.'|'.$read;
        if (!\array_key_exists($key, $this->variableValues)) {
            // A variable assigned from itself, through any number of
            // others, holds there a part the file does not prove.
            $this->variableValues[$key] = [self::rawHole()];
            $this->variableValues[$key] = $this->assignedValues($scope, $name, $read);
        }

        return $this->variableValues[$key];
    }

    /**
     * @param int $read where the variable is last read; -1 for somewhere unknown
     *
     * @return list<ValueExpr>|null
     */
    private function assignedValues(int $scope, string $name, int $read): ?array
    {
        $values = [];
        $seen = [];
        $reached = false;
        foreach ($this->assignments[$scope][$name] ?? [] as [$expr, $at]) {
            if (!$this->reaches($scope, $at, $read)) {
                continue;
            }

            // A local array of literals is left out.
            if (ValueExpr::ARRAY === $expr->kind) {
                return [self::rawHole()];
            }

            $assigned = $this->values($expr);
            if ([] === $assigned) {
                return [];
            }

            $reached = true;
            // A value two assignments give, each naming the same
            // variable, is one value.
            foreach ($assigned as $value) {
                $id = spl_object_id($value);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $values[] = $value;
                }
            }
        }

        return $reached ? $values : null;
    }

    /**
     * Whether a value assigned at $at may reach a read at $read: written
     * before it, or after it in a loop holding both, or in a body a goto
     * may run again. A read somewhere unknown, -1, is reached by all.
     */
    private function reaches(int $scope, int $at, int $read): bool
    {
        if ($read < 0 || $at < $read || isset($this->jumpingScopes[$scope])) {
            return true;
        }

        foreach ($this->loops[$scope] ?? [] as [$start, $end]) {
            if ($start <= $read && $at <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ValueExpr>
     */
    private function propertyValues(ValueExpr $property): array
    {
        $class = $property->class;
        // A class the file declares twice proves no default: which one runs
        // is decided at run time.
        if (null === $class || !isset($this->classes[$class]) || isset($this->writtenProperties[$property->value])
            || $this->anyClassWritten || isset($this->classesWritten[$class]) || $this->usesForeignTrait($class)) {
            return [self::rawHole()];
        }

        $default = $this->properties[$class.'::'.$property->value] ?? null;
        if (null === $default) {
            return [self::rawHole()];
        }

        $this->constantExpressions++;
        $values = $this->values($default);
        $this->constantExpressions--;

        return $values;
    }

    /**
     * Whether the class or trait uses, directly or through a trait of the
     * file, a trait the file does not declare.
     *
     * Each reachable node is expanded once, even when paths share traits
     * or form a cycle. The work is bounded by the nodes and their edges.
     */
    private function usesForeignTrait(string $user): bool
    {
        // Keyed by trait, each queued once. The user is a class, which no
        // trait of the file can use: it needs no entry.
        $seen = [];
        $pending = [$user];
        while ([] !== $pending) {
            $current = array_pop($pending);
            foreach ($this->traitUses[$current] ?? [] as $trait) {
                if (!isset($this->traits[$trait])) {
                    return true;
                }

                $seen[$trait] ??= $pending[] = $trait;
            }
        }

        return false;
    }

    /**
     * The items of an array a call reads its patterns from: its keys or
     * its values, as a literal array at the call would give them.
     *
     * @return list<ValueExpr>
     */
    private function arrayPatterns(ValueExpr $array, PatternFunction $function): array
    {
        $useKeys = $function->keysArePatterns;
        if ($useKeys && null !== $function->replacementIndex) {
            // A key that is a string selects the keys, no key or an int key
            // the values; a key that is no literal is taken for a string.
            $key = $array->items[0][0] ?? null;
            $useKeys = null !== $key && ValueExpr::NON_STRING !== $key->kind
                && (ValueExpr::LITERAL !== $key->kind || PatternFunction::isStringKey($key->value));
        }

        $items = [];
        foreach ($array->items as [$key, $value]) {
            $read = $useKeys ? $key : $value;
            if (null !== $read) {
                $items[] = $read;
            }
        }

        return $items;
    }

    /**
     * The values an expression may take, each folded: a literal, a scalar
     * that is no string, an array of them, a hole, or a concatenation of
     * literals and holes. An expression the file does not prove is a hole
     * that is not quoted. None at all when a composition is one
     * PatternArgument::concat() does not build: nothing is known then.
     *
     * @param int $height set to the constants the values went through
     *
     * @return list<ValueExpr>
     */
    private function resolve(ValueExpr $expr, int $depth, int &$height): array
    {
        $height = 0;

        switch ($expr->kind) {
            case ValueExpr::LITERAL:
            case ValueExpr::NON_STRING:
                return [$expr];

            case ValueExpr::HOLE:
                // A function the file declares is not the preg_quote() of PHP.
                return [$expr->quoted && !$this->isNative($expr->names) ? self::rawHole() : $expr];

            case ValueExpr::CONCAT:
                return $this->concatenation($expr, $depth, $height);

            case ValueExpr::VARIABLE:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->variableValues($expr, true) ?? [self::rawHole()];

            case ValueExpr::PROPERTY:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->propertyValues($expr);

            case ValueExpr::SET:
                if ($this->constantExpressions > 0) {
                    return [self::rawHole()];
                }

                $values = [];
                $seen = [];
                foreach ($expr->alternatives as $alternative) {
                    $alternativeHeight = 0;
                    $resolved = $this->arm($alternative, $depth, $alternativeHeight);
                    if ([] === $resolved) {
                        return [];
                    }

                    // A value two arms give, each naming the same
                    // variable, is one value.
                    foreach ($resolved as $value) {
                        $id = spl_object_id($value);
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $values[] = $value;
                        }
                    }

                    $height = max($height, $alternativeHeight);
                }

                return $values;

            case ValueExpr::FALLBACK:
                if ($this->constantExpressions > 0) {
                    return [self::rawHole()];
                }

                $firstHeight = 0;
                $secondHeight = 0;
                $first = [];
                foreach ($this->arm($expr->parts[0], $depth, $firstHeight) as $value) {
                    $first[] = [$value, null];
                }

                $kept = self::kept($first, function () use ($expr, $depth, &$secondHeight): array {
                    $second = [];
                    foreach ($this->arm($expr->parts[1], $depth, $secondHeight) as $value) {
                        $second[] = [$value, null];
                    }

                    return $second;
                }, '??' === $expr->value, ValueExpr::VARIABLE === $expr->parts[0]->kind);
                $height = max($firstHeight, $secondHeight);

                return array_column($kept, 0);

            case ValueExpr::FORMAT:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->formatted($expr, $depth, $height);

            case ValueExpr::JOIN:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->joined($expr, $depth, $height);

            case ValueExpr::ARRAY:
                $items = [];
                foreach ($expr->items as [$key, $value]) {
                    $keyHeight = 0;
                    $valueHeight = 0;
                    $items[] = [
                        null === $key ? null : $this->item($key, $depth, $keyHeight),
                        $this->item($value, $depth, $valueHeight),
                    ];
                    $height = max($height, $keyHeight, $valueHeight);
                }

                return [ValueExpr::arrayOf($items)];

            case ValueExpr::CLASS_CONSTANT:
                $class = $expr->class;
                if (null === $class) {
                    return [self::rawHole()];
                }

                if (\array_key_exists($class, $this->classes)) {
                    $final = $this->classes[$class];
                    $constant = null === $final ? null : $this->classConstants[$class.'::'.$expr->value] ?? null;
                } else {
                    // A class the file does not declare may be another
                    // file's, read through the project's constants.
                    $final = $this->project?->isFinal($class);
                    $constant = $this->project?->constant($class, $expr->value);
                }

                // static:: names the class the method is called on: a
                // subclass may declare its own constant.
                if (null === $constant || ($expr->lateStatic && !$final && !$constant[1])) {
                    return [self::rawHole()];
                }

                return $this->fold('c:'.$class.'::'.$expr->value, $constant[0], $depth, $height);

            case ValueExpr::CONSTANT:
                foreach ($expr->names as $name) {
                    if (\array_key_exists($name, $this->constants)) {
                        $value = $this->constants[$name];

                        return null === $value ? [self::rawHole()] : $this->fold('g:'.$name, $value, $depth, $height);
                    }
                }

                return [self::rawHole()];

            default:
                return [self::rawHole()];
        }
    }

    /**
     * The values of an arm of a ternary, of a match or of a fallback. An
     * array written there is left out, as one assigned to a local variable
     * is: a hole.
     *
     * @param int $height set to the constants the values went through
     *
     * @return list<ValueExpr>
     */
    private function arm(ValueExpr $expr, int $depth, int &$height): array
    {
        return ValueExpr::ARRAY === $expr->kind ? [self::rawHole()] : $this->resolve($expr, $depth, $height);
    }

    /**
     * An item of an array: its value, or the set of those it may take.
     *
     * @param int $height set to the constants the item went through
     */
    private function item(ValueExpr $expr, int $depth, int &$height): ValueExpr
    {
        $values = $this->resolve($expr, $depth, $height);

        return 1 === \count($values) ? $values[0] : ValueExpr::setOf($values);
    }

    /**
     * The values a concatenation may take: those of its operands, each
     * followed by each, as far as PatternArgument::concat() builds them.
     * None when it does not, or when nothing is known of an operand: the
     * operands after that one are not read.
     *
     * @param int $height set to the constants the values went through
     *
     * @return list<ValueExpr>
     */
    private function concatenation(ValueExpr $expr, int $depth, int &$height): array
    {
        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        foreach ($expr->parts as $part) {
            $partHeight = 0;
            $alternatives = self::followedBy($alternatives, $this->resolve($part, $depth, $partHeight), static fn (ValueExpr $value): array => self::parts($value, true));
            if (null === $alternatives) {
                return [];
            }

            $height = max($height, $partHeight);
        }

        return self::composed($alternatives, $expr);
    }

    /**
     * The alternatives of a composition, each followed by each value of
     * one more operand; null when PatternArgument::concat() does not build
     * them, or when nothing is known of the operand. An operand of more
     * values than a composition may give is refused before any is read.
     *
     * @param list<list<string|Hole>>                $alternatives
     * @param list<ValueExpr>                        $values       the values of the operand
     * @param \Closure(ValueExpr): list<string|Hole> $parts        what a value reads as in the composition
     *
     * @return list<list<string|Hole>>|null
     */
    private static function followedBy(array $alternatives, array $values, \Closure $parts): ?array
    {
        if ([] === $values || \count($values) > PatternArgument::MAX_CANDIDATES) {
            return null;
        }

        return PatternArgument::concat($alternatives, array_map($parts, $values));
    }

    /**
     * The values of a sprintf() call: its format, one constant holding
     * only "%s", "%d" and "%%", each place filled with what its argument
     * reads as. A place for an integer given anything else is a part the
     * file does not prove; an argument past the places is not read, as
     * PHP ignores it. Any other format, or an argument short, and the
     * whole call is such a part. The arguments multiply as the operands
     * of a concatenation do.
     *
     * @param int $height set to the constants the values went through
     *
     * @return list<ValueExpr>
     */
    private function formatted(ValueExpr $expr, int $depth, int &$height): array
    {
        $formats = $this->resolve($expr->parts[0], $depth, $height);
        $pieces = 1 === \count($formats) && ValueExpr::LITERAL === $formats[0]->kind ? self::places($formats[0]->value) : null;
        // A function the file declares is not the sprintf() of PHP.
        if (null === $pieces || !$this->isNative($expr->names) || intdiv(\count($pieces), 2) >= \count($expr->parts)) {
            return [self::rawHole()];
        }

        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        $argument = 1;
        foreach ($pieces as $piece) {
            if (\is_string($piece)) {
                $alternatives = PatternArgument::concat($alternatives, [[$piece]]);
            } else {
                $argumentHeight = 0;
                $alternatives = self::followedBy(
                    $alternatives,
                    $this->resolve($expr->parts[$argument++], $depth, $argumentHeight),
                    static fn (ValueExpr $value): array => !$piece || null !== $value->integer ? self::parts($value, true) : [self::unread()],
                );
                $height = max($height, $argumentHeight);
            }

            if (null === $alternatives) {
                return [];
            }
        }

        return self::composed($alternatives, $expr);
    }

    /**
     * The values of an implode() or join() call: the items of its array,
     * each a string or an integer the file proves, joined by its
     * separator, one string the file proves, or by nothing without one.
     * Any other separator or item, and the whole call is a part the file
     * does not prove. The items multiply as the operands of a
     * concatenation do.
     *
     * @param int $height set to the constants the values went through
     *
     * @return list<ValueExpr>
     */
    private function joined(ValueExpr $expr, int $depth, int &$height): array
    {
        $parts = $expr->parts;
        $separator = '';
        if (2 === \count($parts)) {
            $separators = $this->resolve($parts[0], $depth, $height);
            if (1 !== \count($separators) || ValueExpr::LITERAL !== $separators[0]->kind) {
                return [self::rawHole()];
            }

            $separator = $separators[0]->value;
        }

        $arrayHeight = 0;
        $arrays = $this->resolve($parts[\count($parts) - 1], $depth, $arrayHeight);
        $height = max($height, $arrayHeight);
        // A function the file declares is not the implode() of PHP.
        if (1 !== \count($arrays) || ValueExpr::ARRAY !== $arrays[0]->kind || !$this->isNative($expr->names)) {
            return [self::rawHole()];
        }

        // Every item is looked at before any is joined: one the file does
        // not prove leaves nothing of the call, whatever the others give.
        $items = [];
        foreach ($arrays[0]->items as [, $item]) {
            $values = ValueExpr::SET === $item->kind ? $item->alternatives : [$item];
            foreach ($values as $value) {
                if (ValueExpr::LITERAL !== $value->kind && null === $value->integer) {
                    return [self::rawHole()];
                }
            }

            $items[] = $values;
        }

        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        foreach ($items as $position => $values) {
            $before = 0 === $position ? '' : $separator;
            $alternatives = self::followedBy($alternatives, $values, static fn (ValueExpr $value): array => [$before.(ValueExpr::LITERAL === $value->kind ? $value->value : (string) $value->integer)]);
            if (null === $alternatives) {
                return [];
            }
        }

        return self::composed($alternatives, $expr);
    }

    /**
     * The values a composition gives, from the parts of each: a whole
     * pattern is reported where the composition starts; around a hole,
     * what is written is kept. One that would fold past MAX_LENGTH is not
     * read: a hole.
     *
     * @param list<list<string|Hole>> $alternatives
     * @param ValueExpr               $expr         the concatenation, or the call
     *
     * @return list<ValueExpr>
     */
    private static function composed(array $alternatives, ValueExpr $expr): array
    {
        // Measured before anything is joined: constants that double each
        // other are refused without their text being built.
        foreach ($alternatives as $parts) {
            $size = 0;
            foreach ($parts as $part) {
                $size += \is_string($part) ? \strlen($part) : 1;
            }

            if ($size > self::MAX_LENGTH) {
                return [self::rawHole()];
            }
        }

        $values = [];
        foreach ($alternatives as $parts) {
            // Strings that follow each other are one string.
            $pieces = [];
            $text = null;
            foreach ($parts as $part) {
                if (\is_string($part)) {
                    // Joined in place: a concatenation of many operands
                    // is not copied once for each.
                    if (null === $text) {
                        $text = $part;
                    } else {
                        $text .= $part;
                    }

                    continue;
                }

                if (null !== $text) {
                    $pieces[] = ValueExpr::literal($text, $expr->offset, $expr->line);
                    $text = null;
                }

                $pieces[] = $part->quoted ? ValueExpr::hole(true, $part->delimiter, '') : self::rawHole();
            }

            if ([] === $pieces) {
                $values[] = ValueExpr::literal($text ?? '', $expr->offset, $expr->line, $expr->file, $expr->column, $expr->guard);

                continue;
            }

            if (null !== $text) {
                $pieces[] = ValueExpr::literal($text, $expr->offset, $expr->line);
            }

            $values[] = ValueExpr::concat($pieces, $expr->offset, $expr->line);
        }

        return $values;
    }

    /**
     * The values of a constant, at most MAX_DEPTH constants from the call,
     * none of them met twice on the way: a hole past them.
     *
     * @param int $height set to the constants the values went through, this one included
     *
     * @return list<ValueExpr>
     */
    private function fold(string $key, ValueExpr $value, int $depth, int &$height): array
    {
        if (isset($this->folded[$key])) {
            [$resolved, $height] = $this->folded[$key];
            if ($depth + $height > self::MAX_DEPTH) {
                $this->cuts++;

                return [self::rawHole()];
            }

            return $resolved;
        }

        if ($depth >= self::MAX_DEPTH || isset($this->folding[$key])) {
            $this->cuts++;

            return [self::rawHole()];
        }

        // A reading that was cut short gives no more from as far or
        // farther: it is not made again, or constants that each name the
        // one before twice would double the work at each of them.
        if (isset($this->cutShort[$key]) && $depth >= $this->cutShort[$key][0]) {
            $this->cuts++;
            [, $resolved, $height] = $this->cutShort[$key];

            return $resolved;
        }

        $cuts = $this->cuts;
        $this->folding[$key] = true;
        $this->constantExpressions++;
        $below = 0;
        $resolved = $this->resolve($value, $depth + 1, $below);
        $this->constantExpressions--;
        unset($this->folding[$key]);
        $height = $below + 1;

        // A value a guard cut short is the value from this depth: read
        // from nearer the call, the constant may give more. It is kept
        // for this depth and beyond only.
        if ($cuts === $this->cuts) {
            $this->folded[$key] = [$resolved, $height];
        } else {
            $this->cutShort[$key] = [$depth, $resolved, $height];
        }

        return $resolved;
    }
}
