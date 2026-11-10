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
 * - a local variable: every assignment of the function body a literal, a
 *   concatenation of literals or a constant the file proves, and no way of
 *   writing it otherwise (a parameter, a reference, a compound assignment,
 *   global, static, extract(), $$, a destructuring, a foreach or catch
 *   target, a call it is passed to).
 *
 * A value is read as the values it may take, each a list of parts: the
 * text the file proves, and a hole for each part it does not, a text
 * known at run time only. A concatenation keeps what is written around a
 * part the file does not prove; preg_quote() is a hole whose text reads as
 * literal characters; an integer joined to a string reads as PHP writes
 * it. Every pattern argument ends in one state, from one pattern to
 * nothing static.
 *
 * Folding follows 32 constants at most and stops on a cycle; a
 * concatenation gives at most PatternArgument::MAX_CANDIDATES values. A
 * pattern read through a name is reported once, where its literal is
 * written.
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
     * @var array<string, list<ValueExpr>> "scope|name" => the values the variable is read with
     */
    private array $variableValues = [];

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
     * Say where each variable is read as a pattern, before the patterns
     * are asked for: a value assigned after the last read never reaches
     * it.
     */
    public function read(ValueExpr $expr): void
    {
        if (ValueExpr::VARIABLE !== $expr->kind || null === $expr->scope) {
            return;
        }

        $last = $this->lastReads[$expr->scope][$expr->value] ?? null;
        $this->lastReads[$expr->scope][$expr->value] = $expr->offset < 0 || -1 === $last ? -1 : max($last ?? 0, $expr->offset);
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
     * reported yet, each where its literal is written, with the way the
     * call reaches it: "via self::RE", "via $re"; none for a pattern the
     * call assembles itself. An argument one of whose values is no whole
     * pattern gives none.
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

        $location = $expr->isReference() ? 'via '.$expr->written : null;
        $patterns = [];
        foreach ($this->arguments($expr, $function) as $alternatives) {
            foreach ($alternatives as $alternative) {
                if (ValueExpr::LITERAL !== $alternative->kind) {
                    continue 2;
                }
            }

            foreach ($alternatives as $literal) {
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

        return $patterns;
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
            $states[] = PatternArgument::state(array_map(static fn (ValueExpr $alternative): array => self::parts($alternative, false), $alternatives))->value;
        }

        return $states;
    }

    /**
     * The arguments a call reads through the expression, each as the
     * values it may take: one argument, or, for an array a name holds, one
     * for each item the call reads a pattern from. An array written where
     * a pattern is read is no pattern: PHP reads it as "Array". None when
     * the call reads its pattern from an option the value is known not to
     * hold. An argument with no value is one nothing is known of.
     *
     * @return list<list<ValueExpr>>
     */
    private function arguments(ValueExpr $expr, PatternFunction $function): array
    {
        $this->settleCalls();

        $values = match ($expr->kind) {
            ValueExpr::VARIABLE => $this->variableValues($expr),
            ValueExpr::PROPERTY => $this->propertyValues($expr),
            default => $this->values($expr),
        };

        if ([] === $values) {
            return [[]];
        }

        $arguments = [];
        $strings = [];
        $scalars = [];
        foreach ($values as $value) {
            $value = $this->optionValue($value, $function);
            if (null === $value) {
                continue;
            }

            if (ValueExpr::ARRAY === $value->kind && $expr->isReference()) {
                foreach ($this->arrayPatterns($value, $function) as $item) {
                    $arguments[] = ValueExpr::SET === $item->kind ? $item->alternatives : [$item];
                }

                continue;
            }

            foreach (ValueExpr::SET === $value->kind ? $value->alternatives : [$value] as $alternative) {
                if (ValueExpr::NON_STRING === $alternative->kind) {
                    $scalars[] = $alternative;
                } else {
                    $strings[] = $alternative;
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
     * that may be written unseen. Read once per variable.
     *
     * @return list<ValueExpr>
     */
    private function variableValues(ValueExpr $variable): array
    {
        $scope = $variable->scope;
        if (null === $scope || isset($this->opaqueScopes[$scope]) || isset($this->bailed[$scope][$variable->value])) {
            return [self::rawHole()];
        }

        $key = $scope.'|'.$variable->value;
        if (!\array_key_exists($key, $this->variableValues)) {
            $this->variableValues[$key] = $this->assignedValues($scope, $variable->value);
        }

        return $this->variableValues[$key];
    }

    /**
     * @return list<ValueExpr>
     */
    private function assignedValues(int $scope, string $name): array
    {
        $lastRead = $this->lastReads[$scope][$name] ?? -1;

        $values = [];
        // Never assigned: no value, the loop below gives none.
        foreach ($this->assignments[$scope][$name] ?? [] as [$expr, $at]) {
            if (!$this->reaches($scope, $at, $lastRead)) {
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

            foreach ($assigned as $value) {
                $values[] = $value;
            }
        }

        return $values;
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

        return null === $default ? [self::rawHole()] : $this->values($default);
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
     * that is not quoted. None at all when a concatenation would give more
     * values than a pattern argument may: nothing is known then.
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

            case ValueExpr::SET:
                $values = [];
                foreach ($expr->alternatives as $alternative) {
                    $alternativeHeight = 0;
                    $resolved = $this->resolve($alternative, $depth, $alternativeHeight);
                    if ([] === $resolved) {
                        return [];
                    }

                    foreach ($resolved as $value) {
                        $values[] = $value;
                    }

                    $height = max($height, $alternativeHeight);
                }

                return $values;

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
                // A variable or a property is no constant expression.
                return [self::rawHole()];
        }
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
     * followed by each. A whole pattern is reported where the
     * concatenation starts; around a hole, what is written is kept. One
     * that would fold past MAX_LENGTH is not read: a hole.
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
            $operand = [];
            foreach ($this->resolve($part, $depth, $partHeight) as $value) {
                $operand[] = self::parts($value, true);
            }

            $alternatives = PatternArgument::concat($alternatives, $operand);
            if (null === $alternatives) {
                return [];
            }

            $height = max($height, $partHeight);
        }

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
                $values[] = ValueExpr::literal($text ?? '', $expr->offset, $expr->line, $expr->file, $expr->column);

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
        $below = 0;
        $resolved = $this->resolve($value, $depth + 1, $below);
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
