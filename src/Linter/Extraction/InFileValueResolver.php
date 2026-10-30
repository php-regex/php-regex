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
 * Folding follows 32 constants at most and stops on a cycle. A pattern read
 * through a name is reported once, where its literal is written.
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
     * @var array<string, list<ValueExpr>|null> "scope|name" => the values the variable is read with
     */
    private array $variableValues = [];

    /**
     * @var array<string, true> the variables already read for a function, "scope|name|function"
     */
    private array $variablesRead = [];

    /**
     * @var array<string, array{ValueExpr, int}> constant => its folded value and the constants it went through
     */
    private array $folded = [];

    /**
     * @var array<string, true> the constants being folded
     */
    private array $folding = [];

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
     * @param ValueExpr|null $default the literal default; null when the property may hold another value
     */
    public function declareProperty(string $class, string $name, ?ValueExpr $default): void
    {
        $this->properties[$class.'::'.$name] = $default;
    }

    public function writeProperty(string $name): void
    {
        $this->writtenProperties[$name] = true;
    }

    /**
     * A property written under a name the file does not spell ($o->$name,
     * $o->{...}) in the class, or out of any class when null: none of its
     * properties is read.
     */
    public function writeAnyProperty(?string $class): void
    {
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
     * call assembles itself.
     *
     * @return list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null}> each with the file and the column of its literal when it is another file's
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

        $values = match ($expr->kind) {
            ValueExpr::VARIABLE => $this->variableValues($expr),
            ValueExpr::PROPERTY => $this->propertyValues($expr),
            ValueExpr::CONCAT, ValueExpr::CLASS_CONSTANT, ValueExpr::CONSTANT => $this->values($expr),
            default => null,
        };

        $location = $expr->isReference() ? 'via '.$expr->written : null;
        $patterns = [];
        foreach ($values ?? [] as $value) {
            $value = $this->optionValue($value, $function);
            if (null === $value) {
                continue;
            }

            foreach (ValueExpr::ARRAY === $value->kind ? $this->arrayPatterns($value, $function) : [$value] as $literal) {
                if (ValueExpr::LITERAL !== $literal->kind || '' === $literal->value) {
                    continue;
                }

                $pattern = $literal->value.$function->patternSuffix;
                // A literal of another file is told from one of the file
                // at the same offset: each file keeps its own.
                if (isset($this->reported[$literal->file ?? ''][$literal->offset][$pattern])) {
                    continue;
                }

                $this->reported[$literal->file ?? ''][$literal->offset][$pattern] = $literal;
                $patterns[] = ['pattern' => $pattern, 'offset' => $literal->offset, 'line' => $literal->line, 'location' => $location, 'file' => $literal->file, 'column' => $literal->column];
            }
        }

        return $patterns;
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
     * @return list<ValueExpr>|null
     */
    private function values(ValueExpr $expr): ?array
    {
        $height = 0;
        $value = $this->resolve($expr, 0, $height);

        return null === $value ? null : [$value];
    }

    /**
     * Every value the assignments of the variable that may reach its reads
     * give it, or null when one of them, or a way of writing it, proves
     * nothing. Read once per variable.
     *
     * @return list<ValueExpr>|null
     */
    private function variableValues(ValueExpr $variable): ?array
    {
        $scope = $variable->scope;
        if (null === $scope || isset($this->opaqueScopes[$scope]) || isset($this->bailed[$scope][$variable->value])) {
            return null;
        }

        $key = $scope.'|'.$variable->value;
        if (!\array_key_exists($key, $this->variableValues)) {
            $this->variableValues[$key] = $this->assignedValues($scope, $variable->value);
        }

        return $this->variableValues[$key];
    }

    /**
     * @return list<ValueExpr>|null
     */
    private function assignedValues(int $scope, string $name): ?array
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
                return null;
            }

            $height = 0;
            $value = $this->resolve($expr, 0, $height);
            if (null === $value) {
                return null;
            }

            $values[] = $value;
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
     * @return list<ValueExpr>|null
     */
    private function propertyValues(ValueExpr $property): ?array
    {
        $class = $property->class;
        // A class the file declares twice proves no default: which one runs
        // is decided at run time.
        if (null === $class || !isset($this->classes[$class]) || isset($this->writtenProperties[$property->value])
            || $this->anyClassWritten || isset($this->classesWritten[$class]) || $this->usesForeignTrait($class, [])) {
            return null;
        }

        $default = $this->properties[$class.'::'.$property->value] ?? null;

        return null === $default ? null : $this->values($default);
    }

    /**
     * Whether the class or trait uses, directly or through a trait of the
     * file, a trait the file does not declare.
     *
     * @param array<string, true> $seen the traits already looked into
     */
    private function usesForeignTrait(string $user, array $seen): bool
    {
        foreach ($this->traitUses[$user] ?? [] as $trait) {
            if (!isset($this->traits[$trait])) {
                return true;
            }

            if (!isset($seen[$trait]) && $this->usesForeignTrait($trait, $seen + [$trait => true])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The literals of an array a call reads: its keys or its values, as a
     * literal array at the call would give them.
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

        $literals = [];
        foreach ($array->items as [$key, $value]) {
            $read = $useKeys ? $key : $value;
            if (null !== $read) {
                $literals[] = $read;
            }
        }

        return $literals;
    }

    /**
     * The value an expression holds, folded: a literal, a scalar that is no
     * string, or an array of them. Null when the file does not prove it.
     *
     * @param int $height set to the constants the value went through
     */
    private function resolve(ValueExpr $expr, int $depth, int &$height): ?ValueExpr
    {
        $height = 0;

        switch ($expr->kind) {
            case ValueExpr::LITERAL:
            case ValueExpr::NON_STRING:
                return $expr;

            case ValueExpr::CONCAT:
                $value = '';
                foreach ($expr->parts as $part) {
                    $partHeight = 0;
                    $resolved = $this->resolve($part, $depth, $partHeight);
                    if (null === $resolved || ValueExpr::LITERAL !== $resolved->kind || \strlen($value) + \strlen($resolved->value) > self::MAX_LENGTH) {
                        return null;
                    }

                    $value .= $resolved->value;
                    $height = max($height, $partHeight);
                }

                // A whole pattern is reported where the concatenation starts.
                return ValueExpr::literal($value, $expr->offset, $expr->line, $expr->file, $expr->column);

            case ValueExpr::ARRAY:
                $items = [];
                foreach ($expr->items as [$key, $value]) {
                    $keyHeight = 0;
                    $valueHeight = 0;
                    $items[] = [
                        null === $key ? null : $this->resolve($key, $depth, $keyHeight) ?? ValueExpr::unknown(),
                        $this->resolve($value, $depth, $valueHeight) ?? ValueExpr::unknown(),
                    ];
                    $height = max($height, $keyHeight, $valueHeight);
                }

                return ValueExpr::arrayOf($items);

            case ValueExpr::CLASS_CONSTANT:
                $class = $expr->class;
                if (null === $class) {
                    return null;
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

                if (null === $constant) {
                    return null;
                }

                // static:: names the class the method is called on: a
                // subclass may declare its own constant.
                if ($expr->lateStatic && !$final && !$constant[1]) {
                    return null;
                }

                return $this->fold('c:'.$class.'::'.$expr->value, $constant[0], $depth, $height);

            case ValueExpr::CONSTANT:
                foreach ($expr->names as $name) {
                    if (\array_key_exists($name, $this->constants)) {
                        $value = $this->constants[$name];

                        return null === $value ? null : $this->fold('g:'.$name, $value, $depth, $height);
                    }
                }

                return null;

            default:
                // A variable or a property is no constant expression.
                return null;
        }
    }

    /**
     * The value of a constant, at most MAX_DEPTH constants from the call,
     * none of them met twice on the way.
     *
     * @param int $height set to the constants the value went through, this one included
     */
    private function fold(string $key, ValueExpr $value, int $depth, int &$height): ?ValueExpr
    {
        if (isset($this->folded[$key])) {
            [$resolved, $height] = $this->folded[$key];

            return $depth + $height > self::MAX_DEPTH ? null : $resolved;
        }

        if ($depth >= self::MAX_DEPTH || isset($this->folding[$key])) {
            return null;
        }

        $this->folding[$key] = true;
        $below = 0;
        $resolved = $this->resolve($value, $depth + 1, $below);
        unset($this->folding[$key]);

        if (null === $resolved) {
            return null;
        }

        $height = $below + 1;
        $this->folded[$key] = [$resolved, $height];

        return $resolved;
    }
}
