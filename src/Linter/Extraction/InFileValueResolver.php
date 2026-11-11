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
 *   a namespace "const" or a define() with a literal name, when the call
 *   reaches the function of PHP; an unqualified name tries the namespace,
 *   then the global constant. Its value may be a ternary, a match, "?:"
 *   or "??": one of the values of its arms. A constant of another file
 *   is read through the project's constants;
 *   DIRECTORY_SEPARATOR, PHP_EOL and PATH_SEPARATOR are each one of the
 *   two values a system gives them;
 * - self::X and static::X in a closure or an arrow function, which may be
 *   bound to another class, only when each closure around the read is
 *   written as the callback of a call to a function of CALLING, which
 *   nothing declares again in the namespace of the call;
 * - a property read as $this->p: a literal default, private, or protected,
 *   in a final class or final itself, never promoted, and never written in
 *   the file;
 * - a local variable: every assignment of the function body that may
 *   reach the read a value the file proves, and no way of writing it
 *   otherwise (a parameter, a reference, a compound assignment, global,
 *   static, extract(), $$, a destructuring, a catch target, a call it is
 *   passed to). It is read alone, as an operand, in an interpolated
 *   string, or through another variable assigned from it. Joined to
 *   other texts, it is read when one assignment alone is written before
 *   that read: two are not told alternatives from successive ones, and
 *   nothing is known then;
 * - the target of a foreach, in the body of its loop: one of the values,
 *   or of the keys, of the array the file proves the loop iterates, when
 *   nothing else in the loop writes it;
 * - an array a variable is assigned, at a call PHP gives a list of
 *   patterns to, and an item of an array a variable or a constant holds:
 *   the one a key the file proves names, any of them otherwise. An array
 *   holds a key written twice once, with its last value;
 * - a variable of the file level, a global any code may write: by the
 *   rules of a local variable, in a file that includes no other, names no
 *   global from a function body and evaluates nothing, and where no call
 *   and no "new" may run between the assignment and the read, nor
 *   anything else than what the closed list of FileLevelCode holds: PHP
 *   runs code where no call is written.
 *
 * A value is read as the values it may take, each a list of parts: the
 * text the file proves, and a hole for each part it does not, a text
 * known at run time only. A concatenation and an interpolated string keep
 * what is written around a part the file does not prove; preg_quote() is
 * a hole whose text reads as literal characters; an integer joined to a
 * string reads as PHP writes it. A ternary and a match are the values of
 * their arms; "A ?: B" and "A ?? B" the values PHP keeps of them, those
 * of B among them when A is a variable or an item PHP may not find;
 * sprintf() and implode() the string PHP builds of what the file proves.
 * Every pattern argument ends in one state, from one pattern to nothing
 * static.
 *
 * What a composition multiplies is bounded by PatternArgument::concat():
 * one operand of several values gives as many patterns, each one the code
 * builds; two of them, or more values than
 * PatternArgument::MAX_CANDIDATES, and nothing is known of the argument.
 * A constant has one value wherever it stands: the same one twice in a
 * composition is one operand, and gives a pattern for each of its values.
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
     * The ternaries, "?:" and "??" an argument holds one inside another:
     * deeper, the innermost is not read. "A ?? B ?? C" is "A ?? (B ?? C)",
     * and reading a chain again from each of its values would take the
     * square of its length.
     */
    public const MAX_NESTED_ALTERNATIVES = 32;

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
     * The function of PHP that declares a constant.
     */
    public const DEFINING = 'define';

    /**
     * The functions of PHP that call a closure they are given as a
     * callback and let it go: none of them keeps it, hands it on or binds
     * it, so a closure written there keeps the class it is written in.
     * Each with where it takes its callback: the position of the
     * parameter PHP names "callback", from zero; or, for a function that
     * takes any number of arrays first, -1 for its last argument and -2
     * for its last two. Any other argument is no callback: PHP hands it
     * to the callback, or refuses it. A list of its own, not the
     * functions of the PHP that runs: what a file proves does not depend
     * on the extensions loaded.
     */
    public const CALLING = [
        'array_map' => 0, 'array_filter' => 1, 'array_reduce' => 1, 'array_walk' => 1,
        'array_walk_recursive' => 1, 'array_find' => 1, 'array_find_key' => 1, 'array_any' => 1,
        'array_all' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1, 'array_udiff' => -1,
        'array_udiff_assoc' => -1, 'array_udiff_uassoc' => -2, 'array_uintersect' => -1,
        'array_uintersect_assoc' => -1, 'array_uintersect_uassoc' => -2, 'array_diff_ukey' => -1,
        'array_diff_uassoc' => -1, 'array_intersect_ukey' => -1, 'array_intersect_uassoc' => -1,
        'preg_replace_callback' => 1, 'call_user_func' => 0, 'call_user_func_array' => 0,
        'iterator_apply' => 1,
    ];

    /**
     * The scope of the variables of the file level: globals, which any
     * code the file runs may write.
     */
    public const FILE = -1;

    /**
     * The name PHP gives the callback of a function of CALLING that takes
     * it at a position of its own.
     */
    private const CALLBACK = 'callback';

    /**
     * The constants of PHP whose value depends on the system the code
     * runs on, each with its two values and what a pattern built of one
     * says of it.
     */
    private const PLATFORM = [
        'DIRECTORY_SEPARATOR' => ['/' => 'DIRECTORY_SEPARATOR is "/"', '\\' => 'DIRECTORY_SEPARATOR is "\"'],
        'PHP_EOL' => ["\n" => 'PHP_EOL is "\n"', "\r\n" => 'PHP_EOL is "\r\n"'],
        'PATH_SEPARATOR' => [':' => 'PATH_SEPARATOR is ":"', ';' => 'PATH_SEPARATOR is ";"'],
    ];

    /**
     * How a variable is read: as the pattern itself; as a value an arm or
     * another variable selects, joined to nothing; or as a part of a
     * composition.
     */
    private const ALONE = 0;
    private const SELECTED = 1;
    private const COMPOSED = 2;

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
     * @var array<int, array<string, true>> scope => the variables written otherwise than by a plain assignment,
     *                                      or by a call they are passed to
     */
    private array $bailed = [];

    /**
     * @var array<int, array<string, list<int>>> scope => variable => where the calls it is passed to end: each
     *                                           may write it
     */
    private array $passedAt = [];

    /**
     * @var array<int, array<string, list<array{ValueExpr, int, int}>>> scope => variable => the values assigned to
     *                                                                  it, each with where its assignment starts
     *                                                                  and ends
     */
    private array $assignments = [];

    /**
     * @var array<int, array<string, array{list<int>, list<int>, list<int>}>> scope => variable => where its
     *                                                                        assignments start, in order, where
     *                                                                        they end, in order, and which
     *                                                                        assignment starts at each rank
     */
    private array $assignmentPlaces = [];

    /**
     * @var array<int, array<string, array<int, array<int, list<ValueExpr>>>>> scope => variable => assignment =>
     *                                                                         the values it gives a composition,
     *                                                                         under 0, and a variable read as a
     *                                                                         pattern, under 1
     */
    private array $assignmentValues = [];

    /**
     * @var array<int, array{list<array{int, int}>, list<int>}> scope => the byte ranges of its loops, in order, and
     *                                                          for each the rank of the loop that holds it, -1
     *                                                          for none
     */
    private array $loopTrees = [];

    /**
     * @var array<int, array<string, list<array{ValueExpr, int, int, int}>>> scope => variable => the foreach loops
     *                                                                       whose head assigns it at each turn:
     *                                                                       what the head gives it, where the
     *                                                                       head ends, and where the loop starts
     *                                                                       and ends
     */
    private array $targets = [];

    /**
     * @var array<int, array<string, true>> scope => the variables whose loops are in the order they start in
     */
    private array $targetsInOrder = [];

    /**
     * @var array<int, array<string, array<int, list<ValueExpr>>>> scope => variable => loop => the values its head
     *                                                             gives the variable
     */
    private array $headValues = [];

    /**
     * @var list<int> where each call and each "new" the file level runs ends
     */
    private array $calls = [];

    private bool $callsInOrder = true;

    /**
     * What the file level holds that may run code no call shows; null
     * when the source of the file was not given, and nothing is known of
     * what stands between an assignment of that level and a read.
     */
    private ?FileLevelCode $fileLevel = null;

    /**
     * Whether a "global" statement or $GLOBALS stands anywhere in the
     * file: a function body may then write a variable of the file level.
     */
    private bool $globalsNamed = false;

    /**
     * @var \WeakMap<ValueExpr, string> the constant an item of several values takes them from
     */
    private \WeakMap $sources;

    /**
     * @var \WeakMap<ValueExpr, string> what a value built of a constant of PLATFORM says of that constant
     */
    private \WeakMap $variants;

    /**
     * @var \WeakMap<ValueExpr, true> the arrays a variable is assigned as an array literal
     */
    private \WeakMap $localArrays;

    /**
     * @var \WeakMap<ValueExpr, array<int|string, ValueExpr>|false> a folded array => its items under the keys PHP
     *                                                              stores them by, read at its first item named by
     *                                                              a key; false when a key is not proved
     */
    private \WeakMap $keyedItems;

    /**
     * @var array<int, array<string, array<int, true>>> scope => variable => the assignments, by their rank, that
     *                                                  stand as statements of the body itself: every path to
     *                                                  what follows runs them
     */
    private array $certain = [];

    /**
     * @var array<int, array<string, array{list<int>, list<int>}>> scope => variable => where those assignments
     *                                                             are written and where they end, in order
     */
    private array $certainPlaces = [];

    /**
     * @var array<int, array<string, list<int>>> scope => variable => where the variable is read as a pattern or
     *                                           as a part of one, -1 for somewhere unknown
     */
    private array $uses = [];

    /**
     * @var array<int, array<string, true>> scope => variable => whether its uses are in order
     */
    private array $usesInOrder = [];

    /**
     * @var list<array{list<string>, string, ValueExpr}> the define() calls of the file, each with the functions it
     *                                                   may reach, the name it gives and the value: a constant
     *                                                   when the call reaches the function of PHP
     */
    private array $defined = [];

    /**
     * @var array<string, list<ValueExpr>> constant of PLATFORM => its values
     */
    private array $platformValues = [];

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
     * @var list<array{list<string>, int, int|string|null, string, int|null}> the writes of the calls that may reach
     *                                                                        a function of BY_VALUE, each with the
     *                                                                        functions its call may reach: which
     *                                                                        write, its scope or its class, its
     *                                                                        name, and where the call ends
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
     * @var array<string, array{list<ValueExpr>, int, string|null}> constant => its folded values, the constants they
     *                                                              went through and the constant they follow
     */
    private array $folded = [];

    /**
     * @var array<string, true> the constants being folded
     */
    private array $folding = [];

    /**
     * @var array<string, array{int, list<ValueExpr>, int, string|null}> constant => the depth its reading was cut
     *                                                                   short from, what it gave from there, the
     *                                                                   constants it went through and the
     *                                                                   constant its values follow
     */
    private array $cutShort = [];

    /**
     * @var array<string, array<int, array<string, ValueExpr>>> file ("" for this one) => offset => pattern => the literal already reported
     */
    private array $reported = [];

    public function __construct()
    {
        $this->sources = new \WeakMap();
        $this->variants = new \WeakMap();
        $this->localArrays = new \WeakMap();
        $this->keyedItems = new \WeakMap();
    }

    /**
     * Whether a function of CALLING calls what it is passed there: the
     * argument at the position of its callback, or named as it; for a
     * function that takes its callbacks last, one of them, when every
     * argument of the call is written at its position.
     *
     * @param string      $function the lowercase function of PHP
     * @param int|null    $position the position of the argument, from zero; null for one passed by name
     * @param string|null $name     the name it is passed by
     * @param int|null    $count    how many arguments the call passes, each at its position; null when one is
     *                              named or spread
     */
    public static function callsArgument(string $function, ?int $position, ?string $name, ?int $count): bool
    {
        $callback = self::CALLING[$function] ?? null;
        if (null === $callback) {
            return false;
        }

        if ($callback >= 0) {
            return null === $name ? $position === $callback : self::CALLBACK === $name;
        }

        // The first argument is an array, whatever follows.
        return null !== $position && null !== $count && $position >= max(1, $count + $callback);
    }

    /**
     * Resolve a class, a constant or a function the file does not declare
     * through what the project's other files declare: the file's own
     * declaration of a name always wins.
     */
    public function useProject(ProjectConstants $project): void
    {
        $this->project = $project;
    }

    /**
     * Read what stands between an assignment of the file level and a
     * read of its variable from the code of the file: a variable of that
     * level is read only across what the closed list holds.
     */
    public function useFileLevel(FileLevelCode $code): void
    {
        $this->fileLevel = $code;
    }

    /**
     * The constants the file declared so far at the level of a
     * namespace, by a "const" or a define(): null for one it declares
     * twice, or that PHP defines itself.
     *
     * @return array<string, ValueExpr|null>
     */
    public function globalConstants(): array
    {
        return $this->constants;
    }

    /**
     * The lowercase functions the file declared so far.
     *
     * @return list<string>
     */
    public function functions(): array
    {
        return array_map(strval(...), array_keys($this->functions));
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
     * A define() with a literal name: it declares the constant when the
     * call reaches the function of PHP, which is known once the whole
     * file is read. A function of that name the namespace of the call
     * declares, in the file or in another one of the project, is reached
     * first, and declares nothing the file can know.
     *
     * @param list<string> $names the lowercase functions the call may reach, in the order PHP tries them
     * @param string       $name  the constant's fully qualified name
     */
    public function define(array $names, string $name, ValueExpr $value): void
    {
        $this->defined[] = [$names, $name, $value];
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
            $this->passed[] = [$this->callee, self::WRITES_PROPERTY, null, $name, null];

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
            $this->passed[] = [$this->callee, self::WRITES_ANY_PROPERTY, $class, '', null];

            return;
        }

        if (null === $class) {
            $this->anyClassWritten = true;
        } else {
            $this->classesWritten[$class] = true;
        }
    }

    /**
     * @param int      $at      where the assignment is written
     * @param int|null $end     where its value ends; null when it is not known, which reads as where it is
     *                          written
     * @param bool     $certain whether the assignment stands as a statement of the function body itself, or of
     *                          the file, in no branch and no loop: every path to what follows it runs it
     */
    public function assign(int $scope, string $name, ValueExpr $value, int $at, ?int $end = null, bool $certain = false): void
    {
        if ($certain) {
            $this->certain[$scope][$name][\count($this->assignments[$scope][$name] ?? [])] = true;
        }

        $this->assignments[$scope][$name][] = [$value, $at, max($at, $end ?? $at)];
    }

    /**
     * A foreach whose head assigns the variable at each turn, as its
     * value target or its key target: what the head gives it, where the
     * head ends, and the loop from its keyword to its last byte.
     */
    public function iterate(int $scope, string $name, ValueExpr $each, int $head, int $start, int $end): void
    {
        $this->targets[$scope][$name][] = [$each, $head, $start, $end];
    }

    /**
     * A call or a "new" the file level runs, by where it ends: the code
     * it runs may write any variable of that level.
     */
    public function call(int $at): void
    {
        $this->callsInOrder = $this->callsInOrder && ([] === $this->calls || $this->calls[\count($this->calls) - 1] <= $at);
        $this->calls[] = $at;
    }

    /**
     * A "global" statement or $GLOBALS, anywhere in the file: the
     * variables of the file level may be written from a function body.
     */
    public function nameGlobals(): void
    {
        $this->globalsNamed = true;
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
            $this->uses[$scope][$name][] = $current->offset;
            unset($this->usesInOrder[$scope][$name]);
            if (isset($this->followed[$scope][$name])) {
                continue;
            }

            $this->followed[$scope][$name] = true;
            foreach ($this->assignments[$scope][$name] ?? [] as [$assigned]) {
                $pending[] = $assigned;
            }
        }
    }

    /**
     * A variable written otherwise than by a plain assignment.
     *
     * @param int|null $at where the call it is passed to ends, when a call may write it; null for any other
     *                     write, which may reach every read
     */
    public function bail(int $scope, string $name, ?int $at = null): void
    {
        if (null !== $this->callee) {
            $this->passed[] = [$this->callee, self::WRITES_VARIABLE, $scope, $name, $at];

            return;
        }

        if (null === $at) {
            $this->bailed[$scope][$name] = true;
        } else {
            $this->passedAt[$scope][$name][] = $at;
        }
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
     * argument stands where the argument starts, and so does one no file
     * writes, a value PHP gives one of its constants: its offset is -1,
     * and each argument reports it. An argument one of whose values is no
     * whole pattern gives none. The patterns reported at one place come
     * in the order of their bytes.
     *
     * @return list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null, variant: string|null}> each with the file, the column and the guard of its literal when it is another file's, and what it says of a constant of PHP whose value depends on the system
     */
    public function patterns(ValueExpr $expr, PatternFunction $function): array
    {
        // A variable read again the same way gives the patterns its first
        // read reported: none is new. One read by its place holds other
        // values at another read.
        $key = null;
        if (ValueExpr::VARIABLE === $expr->kind && !$this->readsByPlace($expr)) {
            $key = ($expr->scope ?? -1).'|'.$expr->value.'|'.$function->patternSuffix.'|'.implode("\0", $function->optionPath).'|'.(int) $function->optionPathRequired.'|'.(int) \in_array($function->label, PregFunctions::PATTERN_LISTS, true);
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
                $variant = $this->variants[$literal] ?? null;
                if ($literal->offset < 0 && null === $literal->file) {
                    $patterns[] = ['pattern' => $pattern, 'offset' => -1, 'line' => 0, 'location' => null, 'file' => null, 'column' => null, 'guard' => null, 'variant' => $variant];
                    // The next argument naming the variable reports it too.
                    if (null !== $key) {
                        unset($this->variablesRead[$key]);
                    }

                    continue;
                }

                // A literal of another file is told from one of the file
                // at the same offset: each file keeps its own.
                if (isset($this->reported[$literal->file ?? ''][$literal->offset][$pattern])) {
                    continue;
                }

                $this->reported[$literal->file ?? ''][$literal->offset][$pattern] = $literal;
                $patterns[] = ['pattern' => $pattern, 'offset' => $literal->offset, 'line' => $literal->line, 'location' => $location, 'file' => $literal->file, 'column' => $literal->column, 'guard' => $literal->guard, 'variant' => $variant];
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
        if (ValueExpr::VARIABLE !== $expr->kind || $this->readsByPlace($expr)) {
            return $this->statesOf($expr, $function);
        }

        // A variable read many times holds the same values at each read.
        $key = ($expr->scope ?? -1).'|'.$expr->value.'|'.(int) \in_array($function->label, PregFunctions::PATTERN_LISTS, true).'|'.implode("\0", $function->optionPath).'|'.(int) $function->optionPathRequired.'|'.(int) $function->keysArePatterns.'|'.($function->replacementIndex ?? '');

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
     * @param list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null, variant: string|null}> $patterns
     *
     * @return list<array{pattern: string, offset: int, line: int, location: string|null, file: string|null, column: int|null, guard: TargetConstraint|null, variant: string|null}>
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
     * So are those of an item of an array, which the array may not hold.
     *
     * @param list<array{ValueExpr, string|null}>             $first      the values of A, each with the name it is
     *                                                                    read through
     * @param \Closure(): list<array{ValueExpr, string|null}> $second     the values of B, asked for only when one
     *                                                                    of A is passed over
     * @param bool                                            $coalescing whether the operator is "??"
     * @param bool                                            $mayBeUnset whether A is a variable, or an item PHP
     *                                                                    may not find
     *
     * @return list<array{ValueExpr, string|null}>
     */
    private static function kept(array $first, \Closure $second, bool $coalescing, bool $mayBeUnset): array
    {
        // Nothing is known of A: nor of what PHP keeps of it.
        if ([] === $first) {
            return [];
        }

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
     * a pattern is read is no pattern: PHP reads it as "Array". An array
     * the body assigns a variable is read by the calls PHP gives a list
     * of patterns to, and nothing is known of it at any other. None when
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
            if (isset($this->localArrays[$value]) && !\in_array($function->label, PregFunctions::PATTERN_LISTS, true)) {
                return [[]];
            }

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
                // value the argument selects.
                $values = $expr === $argument ? $this->variable($expr, self::ALONE) ?? [] : $this->variable($expr, self::SELECTED) ?? [self::rawHole()];

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
                return self::kept($this->written($expr->parts[0], $argument), fn (): array => $this->written($expr->parts[1], $argument), '??' === $expr->value, $this->mayBeUnset($expr->parts[0], 0));
            default:
                $composed = [];
                foreach ($this->values($expr) as $value) {
                    // A pattern composed in an arm is composed in the
                    // argument all the same.
                    if ($expr !== $argument && ValueExpr::LITERAL !== $expr->kind && ValueExpr::LITERAL === $value->kind && ValueExpr::ITEM !== $expr->kind) {
                        $moved = ValueExpr::literal($value->value, $argument->offset, $argument->line);
                        if (isset($this->variants[$value])) {
                            $this->variants[$moved] = $this->variants[$value];
                        }

                        $value = $moved;
                    }

                    $composed[] = [$value, null];
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
     * and drops those of the calls that reach a function of PHP; declares
     * the constants of the define() calls that reach PHP's: every
     * declaration of the file is read by the time a value is asked for.
     */
    private function settleCalls(): void
    {
        $defined = $this->defined;
        $this->defined = [];
        foreach ($defined as [$names, $name, $value]) {
            if ($this->isNative($names)) {
                $this->declareConstant($name, $value);
            }
        }

        $passed = $this->passed;
        $this->passed = [];
        foreach ($passed as [$names, $write, $owner, $name, $at]) {
            if ($this->isNative($names)) {
                continue;
            }

            if (self::WRITES_VARIABLE === $write && \is_int($owner)) {
                $this->bail($owner, $name, $at);
            } elseif (self::WRITES_PROPERTY === $write) {
                $this->writeProperty($name);
            } elseif (self::WRITES_ANY_PROPERTY === $write && !\is_int($owner)) {
                $this->writeAnyProperty($owner);
            }
        }
    }

    /**
     * Whether a call reaches a function of PHP: the file declares none of
     * the functions PHP tries for it, and no other file of the project
     * declares the one of the namespace, which PHP tries first.
     *
     * @param list<string> $names the lowercase functions the call may reach, in the order PHP tries them
     */
    private function isNative(array $names): bool
    {
        $last = \count($names) - 1;
        foreach ($names as $position => $name) {
            if (isset($this->functions[$name]) || ($position < $last && true === $this->project?->declaresFunction($name))) {
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
     * Whether what a variable holds depends on where it is read: a
     * variable of the file level, which a call may write, and the target
     * of a foreach, which holds another array in each loop.
     */
    private function readsByPlace(ValueExpr $variable): bool
    {
        return self::FILE === $variable->scope || (null !== $variable->scope && isset($this->targets[$variable->scope][$variable->value]));
    }

    /**
     * The values a variable holds where it is read: a hole for one the
     * file does not prove, and for a variable that may be written unseen;
     * none when nothing is known of what it holds there; null when no
     * assignment reaches the read.
     *
     * @param int $mode how the variable is read, ALONE, SELECTED or COMPOSED
     *
     * @return list<ValueExpr>|null
     */
    private function variable(ValueExpr $variable, int $mode): ?array
    {
        $scope = $variable->scope;
        $name = $variable->value;
        if (null === $scope || isset($this->opaqueScopes[$scope]) || (self::FILE === $scope && $this->writtenOutOfSight())) {
            return [self::rawHole()];
        }

        if (isset($this->targets[$scope][$name])) {
            return isset($this->bailed[$scope][$name]) ? [self::rawHole()] : $this->targetValues($scope, $name, $variable->offset);
        }

        if (self::COMPOSED === $mode) {
            return $this->composedValues($scope, $name, $variable->offset);
        }

        if (isset($this->bailed[$scope][$name]) || isset($this->passedAt[$scope][$name])) {
            return [self::rawHole()];
        }

        return self::FILE === $scope ? $this->fileValues($name, $variable->offset) : $this->variableValues($scope, $name, self::SELECTED === $mode);
    }

    /**
     * Whether the file may write its variables of the file level where
     * no assignment shows: code it includes or evaluates, extract(),
     * compact() or a variable named by an expression, anywhere in it, and
     * a function body that names them with "global" or $GLOBALS.
     */
    private function writtenOutOfSight(): bool
    {
        return $this->globalsNamed || [] !== $this->opaqueScopes;
    }

    /**
     * Every value the assignments of a variable of a function body that
     * may reach its reads give it; null when no assignment reaches them.
     * Read once per variable for its reads as a pattern, and once more for
     * its reads as a value an arm or another variable selects when some
     * come later.
     *
     * @param bool $selected whether the variable is read through an arm or another variable, not as the pattern itself
     *
     * @return list<ValueExpr>|null
     */
    private function variableValues(int $scope, string $name, bool $selected): ?array
    {
        $read = ($selected ? $this->lastUses : $this->lastReads)[$scope][$name] ?? -1;
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
        $reaching = [];
        foreach ($this->assignments[$scope][$name] ?? [] as $assignment => [, $at, $end]) {
            if ($this->reaches($scope, $at, $read) && !$this->joinedIntoItsReplacement($scope, $name, $at, $end, $read)) {
                $reaching[] = $assignment;
            }
        }

        return $this->union($scope, $name, $reaching);
    }

    /**
     * Where the assignments of a variable that every path runs are
     * written and where they end, in order: statements of one body, they
     * follow each other.
     *
     * @return array{list<int>, list<int>}
     */
    private function certainPlaces(int $scope, string $name): array
    {
        if (!isset($this->certainPlaces[$scope][$name])) {
            $places = [];
            foreach ($this->certain[$scope][$name] ?? [] as $assignment => $_) {
                [, $at, $end] = $this->assignments[$scope][$name][$assignment];
                $places[$at] = $end;
            }

            ksort($places);
            $this->certainPlaces[$scope][$name] = [array_keys($places), array_values($places)];
        }

        return $this->certainPlaces[$scope][$name];
    }

    /**
     * Whether the value an assignment gives a variable is only a piece
     * of the next one: "$re = '/a'; $re = $re . 'b/';". The assignment
     * that follows it runs on every path, before the last read, and names
     * the variable in its own value, which joins what the variable held;
     * and neither a call nor the value of another variable a call reads
     * names the variable between the two. Such a piece is given to no
     * call: it is no pattern beside the one it is joined into. Written in
     * a loop, the assignment reaches what the loop reads before it at the
     * next turn: the loop counts from its start.
     */
    private function joinedIntoItsReplacement(int $scope, string $name, int $at, int $end, int $read): bool
    {
        if ($read < 0 || !isset($this->certain[$scope][$name]) || isset($this->jumpingScopes[$scope])) {
            return false;
        }

        [$written, $ended] = $this->certainPlaces($scope, $name);
        $next = self::before($written, $at + 1);
        if (!isset($written[$next]) || $ended[$next] >= $read) {
            return false;
        }

        if (!isset($this->usesInOrder[$scope][$name])) {
            $uses = $this->uses[$scope][$name] ?? [];
            sort($uses);
            $this->uses[$scope][$name] = $uses;
            $this->usesInOrder[$scope][$name] = true;
        }

        $uses = $this->uses[$scope][$name];
        if ([] === $uses || $uses[0] < 0) {
            return false;
        }

        // The first place that names the variable after the assignment
        // is in the value of the one that replaces it.
        $loop = $this->outerLoop($scope, $at);
        $from = null === $loop ? $end : min($end, $loop[0]);
        $use = self::before($uses, $from + 1);

        return isset($uses[$use]) && $uses[$use] > $written[$next] && $uses[$use] <= $ended[$next];
    }

    /**
     * The values several assignments of a variable give it, each once;
     * null for no assignment. None when nothing is known of what one of
     * them gives, and when one of several gives an array: two arrays are
     * not proved alternatives item by item.
     *
     * @param list<int> $assignments the assignments, by their rank among those declared
     *
     * @return list<ValueExpr>|null
     */
    private function union(int $scope, string $name, array $assignments): ?array
    {
        if ([] === $assignments) {
            return null;
        }

        $values = [];
        $seen = [];
        foreach ($assignments as $assignment) {
            $assigned = $this->assignmentValue($scope, $name, $assignment, true);
            if ([] === $assigned) {
                return [];
            }

            // A value two assignments give, each naming the same
            // variable, is one value.
            foreach ($assigned as $value) {
                if (\count($assignments) > 1 && isset($this->localArrays[$value])) {
                    return [];
                }

                $id = spl_object_id($value);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * The values one assignment gives its variable, read once for all the
     * reads it reaches. An array literal assigned there is an array of
     * the body, which the calls taking a list of patterns alone read.
     *
     * @param int  $assignment its rank among the assignments declared
     * @param bool $selects    whether the variable is read as a pattern, its value selected and joined to nothing
     *
     * @return list<ValueExpr>
     */
    private function assignmentValue(int $scope, string $name, int $assignment, bool $selects): array
    {
        $mode = (int) $selects;
        if (!isset($this->assignmentValues[$scope][$name][$assignment][$mode])) {
            $expr = $this->assignments[$scope][$name][$assignment][0];
            $values = $selects ? $this->selected($expr) : $this->values($expr);
            if (ValueExpr::ARRAY === $expr->kind) {
                foreach ($values as $value) {
                    $this->localArrays[$value] = true;
                }
            }

            $this->assignmentValues[$scope][$name][$assignment][$mode] = $values;
        }

        return $this->assignmentValues[$scope][$name][$assignment][$mode];
    }

    /**
     * The values a variable brings to a composition, where it is read:
     * those of the one assignment written before the read. Two
     * assignments written before it are not told alternatives from
     * successive ones, and joining each value to what is written around
     * would give a pattern the code never builds: none then, nothing is
     * known. None either when an assignment written after the read may
     * run before it, in a loop that holds both or in a body a goto runs
     * again; nor when the body assigns the variable only after the read,
     * where PHP joins nothing. A variable the body never assigns, or one
     * written otherwise than by a plain assignment, is a hole; so is one
     * of the file level a call may have written since its assignment.
     *
     * @return list<ValueExpr>
     */
    private function composedValues(int $scope, string $name, int $read): array
    {
        if (!isset($this->assignments[$scope][$name])) {
            return [self::rawHole()];
        }

        // Assigned only after the read: PHP joins nothing there, unless
        // something else wrote the variable first.
        $places = $this->assignmentPlaces[$scope][$name] ??= self::inOrder($this->assignments[$scope][$name]);
        if ($read >= 0 && 0 === self::before($places[1], $read)) {
            return isset($this->bailed[$scope][$name]) || $this->passedBetween($scope, $name, -1, $read) ? [self::rawHole()] : [];
        }

        if (isset($this->bailed[$scope][$name]) || isset($this->passedAt[$scope][$name])) {
            return [self::rawHole()];
        }

        $assignment = $this->reaching($scope, $name, $read);
        if (null === $assignment) {
            return [];
        }

        [, $at, $end] = $this->assignments[$scope][$name][$assignment];
        if (self::FILE === $scope && (!$this->assignsInAStatement($at) || $this->writtenBetween($at, $end, $read))) {
            return [self::rawHole()];
        }

        return $this->assignmentValue($scope, $name, $assignment, false);
    }

    /**
     * The one assignment of the variable that reaches a read at $read, as
     * its rank among the assignments declared; null when none does, or
     * several, or when one written after the read may run before it. A
     * read somewhere unknown, -1, comes after every assignment.
     */
    private function reaching(int $scope, string $name, int $read): ?int
    {
        [$starts, $ends, $order] = $this->assignmentPlaces[$scope][$name] ??= self::inOrder($this->assignments[$scope][$name]);
        $count = \count($starts);
        if ($read < 0) {
            return 1 === $count ? $order[0] : null;
        }

        // An assignment that started before the read and did not end
        // holds the read in its own value: it is written after it.
        $started = self::before($starts, $read);
        if (1 !== self::before($ends, $read)) {
            return null;
        }

        if ($started > 1 || $started < $count) {
            if (isset($this->jumpingScopes[$scope])) {
                return null;
            }

            $loop = $this->outerLoop($scope, $read);
            if (null !== $loop && ($started > 1 || $starts[$started] <= $loop[1])) {
                return null;
            }
        }

        for ($rank = 0; $rank < $started; $rank++) {
            if ($this->assignments[$scope][$name][$order[$rank]][2] < $read) {
                return $order[$rank];
            }
        }

        return null;
    }

    /**
     * The values a variable of the file level holds where it is read as
     * a pattern, or through an arm or another variable: those of every
     * assignment written before the read, when no call may have run since
     * the first of them. A call may write the variable out of sight, and
     * so may one of a loop that holds the read, which a later turn runs
     * before it: a hole then. Null when no assignment is written before
     * the read.
     *
     * @return list<ValueExpr>|null
     */
    private function fileValues(string $name, int $read): ?array
    {
        $assignments = $this->assignments[self::FILE][$name] ?? null;
        if (null === $assignments) {
            return null;
        }

        if ($read < 0) {
            return [self::rawHole()];
        }

        [$starts, $ends, $order] = $this->assignmentPlaces[self::FILE][$name] ??= self::inOrder($assignments);
        $started = self::before($starts, $read);
        $ended = self::before($ends, $read);
        if ($started > $ended || $started < \count($starts)) {
            // An assignment written after the read reaches it at a later
            // turn of a loop that holds both, past the calls of the loop.
            $loop = $this->outerLoop(self::FILE, $read);
            if (isset($this->jumpingScopes[self::FILE]) || (null !== $loop && ($started > $ended || $starts[$started] <= $loop[1]))) {
                return [self::rawHole()];
            }
        }

        $reaching = [];
        for ($rank = 0; $rank < $started; $rank++) {
            [, $at, $end] = $assignments[$order[$rank]];
            if ($end >= $read || $this->joinedIntoItsReplacement(self::FILE, $name, $at, $end, $read)) {
                continue;
            }

            // The first one written is the farthest from the read: a
            // call after it stands before the read whichever one runs.
            if (!$this->assignsInAStatement($at) || ([] === $reaching && $this->writtenBetween($at, $end, $read))) {
                return [self::rawHole()];
            }

            $reaching[] = $order[$rank];
        }

        return $this->union(self::FILE, $name, $reaching);
    }

    /**
     * The values the target of a foreach holds where it is read: in the
     * body of its loop, those the head gives it at each turn, when
     * nothing else in the loop writes it. Out of its loops it holds what
     * the last turn left: a hole. None when the loop writes it again, by
     * an assignment or by the head of a loop inside it. A call it is
     * passed to between the head and the read may have written it, and
     * at the file level any call there: a hole. A call after the read is
     * followed by the head, which assigns it again.
     *
     * @return list<ValueExpr>
     */
    private function targetValues(int $scope, string $name, int $read): array
    {
        if (!isset($this->targetsInOrder[$scope][$name])) {
            usort($this->targets[$scope][$name], static fn (array $a, array $b): int => $a[2] <=> $b[2]);
            $this->targetsInOrder[$scope][$name] = true;
        }

        // The last loop whose head stands before the read.
        $heads = $this->targets[$scope][$name];
        $low = 0;
        $high = \count($heads);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($heads[$middle][1] < $read) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $rank = $low - 1;
        if ($rank < 0 || $heads[$rank][3] < $read || isset($this->jumpingScopes[$scope])) {
            return [self::rawHole()];
        }

        [$each, $head, $start, $end] = $heads[$rank];
        if (isset($heads[$rank + 1]) && $heads[$rank + 1][2] <= $end) {
            return [];
        }

        if (isset($this->assignments[$scope][$name])) {
            $starts = ($this->assignmentPlaces[$scope][$name] ??= self::inOrder($this->assignments[$scope][$name]))[0];
            if (self::before($starts, $end + 1) > self::before($starts, $start)) {
                return [];
            }
        }

        if ($this->passedBetween($scope, $name, $head, $read) || (self::FILE === $scope && $this->writtenBetween($head, $head, $read))) {
            return [self::rawHole()];
        }

        return $this->headValues[$scope][$name][$rank] ??= $this->values($each);
    }

    /**
     * Whether a call the variable is passed to, which may write it, may
     * run between a write at $from and a read: one that ends after the
     * write and before the read, or anywhere in a loop that holds the
     * read and not the write, or in a body a goto runs again.
     *
     * @param int $from where the write is made; -1 for the start of the body
     */
    private function passedBetween(int $scope, string $name, int $from, int $read): bool
    {
        $passed = $this->passedAt[$scope][$name] ?? [];
        if ([] === $passed) {
            return false;
        }

        if (isset($this->jumpingScopes[$scope])) {
            return true;
        }

        $until = $read;
        foreach ($this->loopsHolding($scope, $read) as $loop) {
            if ($loop[0] <= $from) {
                break;
            }

            $until = $loop[1] + 1;
        }

        foreach ($passed as $at) {
            if ($at > $from && $at < $until) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a variable of the file level may be written between an
     * assignment of it and a read: by a call the file level runs there,
     * or by code PHP runs where no call is written, a magic method, a
     * class loader, an output callback, which only what the closed list
     * of FileLevelCode holds is known not to run. What stands between
     * runs up to the read, or, in a loop that holds the read and not the
     * assignment, up to the end of that loop, which a later turn runs
     * before the read. Nothing is known of a read somewhere unknown, nor
     * of a file whose code was not given.
     *
     * @param int $at  where the assignment is written
     * @param int $end where it ends
     */
    private function writtenBetween(int $at, int $end, int $read): bool
    {
        $until = $read;
        foreach ($this->loopsHolding(self::FILE, $read) as $loop) {
            if ($loop[0] <= $at) {
                break;
            }

            $until = $loop[1] + 1;
        }

        return $this->calledBetween($end, $until) || $read < 0
            || true !== $this->fileLevel?->holdsOnlyTheList($end, $until, fn (string $name, int $offset): bool => $this->holdsStrings($name, $offset));
    }

    /**
     * Whether an assignment of the file level, written at $at, is a
     * statement of its own: one written in an argument, in a condition
     * or in an interpolated string runs among what the expression around
     * it runs, which is not followed, and leaves its variable unread. So
     * does any assignment of a file whose code was not given.
     */
    private function assignsInAStatement(int $at): bool
    {
        return true === $this->fileLevel?->assignsInAStatement($at, fn (string $name, int $offset): bool => $this->holdsStrings($name, $offset));
    }

    /**
     * Whether a variable of the file level a pattern is read through
     * holds, where it is written, strings the file proves: joined to
     * another string or compared with one, it runs no code then. One no
     * pattern is read through is not followed, and proves nothing here;
     * neither does one whose assignment a branch may skip, which holds on
     * that path whatever it held before.
     */
    private function holdsStrings(string $name, int $at): bool
    {
        $variable = ValueExpr::variable(self::FILE, $name, $at);
        if (!isset($this->followed[self::FILE][$name]) || !$this->assignedOnEveryPath($variable)) {
            return false;
        }

        $values = $this->variable($variable, self::SELECTED) ?? [];
        foreach ($values as $value) {
            if (ValueExpr::LITERAL !== $value->kind) {
                return false;
            }
        }

        return [] !== $values;
    }

    /**
     * Whether a call the file level runs ends after the byte at $end and
     * before the one at $until.
     */
    private function calledBetween(int $end, int $until): bool
    {
        if (!$this->callsInOrder) {
            sort($this->calls);
            $this->callsInOrder = true;
        }

        return self::before($this->calls, $until) > self::before($this->calls, $end + 1);
    }

    /**
     * Where the assignments of a variable start and where they end, each
     * in order, and the assignment that starts at each rank.
     *
     * @param list<array{ValueExpr, int, int}> $assignments
     *
     * @return array{list<int>, list<int>, list<int>}
     */
    private static function inOrder(array $assignments): array
    {
        $order = array_keys($assignments);
        usort($order, static fn (int $a, int $b): int => [$assignments[$a][1], $a] <=> [$assignments[$b][1], $b]);

        $starts = [];
        $ends = [];
        foreach ($order as $index) {
            $starts[] = $assignments[$index][1];
            $ends[] = $assignments[$index][2];
        }

        sort($ends);

        return [$starts, $ends, $order];
    }

    /**
     * How many of the offsets, in order, come before $offset.
     *
     * @param list<int> $offsets
     */
    private static function before(array $offsets, int $offset): int
    {
        $low = 0;
        $high = \count($offsets);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($offsets[$middle] < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * The loop of the body that holds the offset and that no other loop
     * holds; null when no loop holds it.
     *
     * @return array{int, int}|null
     */
    private function outerLoop(int $scope, int $offset): ?array
    {
        $holding = $this->loopsHolding($scope, $offset);

        return [] === $holding ? null : $holding[\count($holding) - 1];
    }

    /**
     * The loops of the body that hold the offset, from the innermost.
     *
     * @return list<array{int, int}>
     */
    private function loopsHolding(int $scope, int $offset): array
    {
        if (!isset($this->loopTrees[$scope])) {
            $loops = $this->loops[$scope] ?? [];
            usort($loops, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            // Each loop with the one that holds it: the last still open
            // where it starts.
            $parents = [];
            $open = [];
            foreach ($loops as $rank => $loop) {
                while ([] !== $open && $loops[$open[\count($open) - 1]][1] < $loop[0]) {
                    array_pop($open);
                }

                $parents[] = [] === $open ? -1 : $open[\count($open) - 1];
                $open[] = $rank;
            }

            $this->loopTrees[$scope] = [$loops, $parents];
        }

        [$loops, $parents] = $this->loopTrees[$scope];
        $low = 0;
        $high = \count($loops);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($loops[$middle][0] <= $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        // A loop that holds the offset holds the last one started before
        // it too.
        $holding = [];
        for ($rank = $low - 1; $rank >= 0; $rank = $parents[$rank]) {
            if ($loops[$rank][1] >= $offset) {
                $holding[] = $loops[$rank];
            }
        }

        return $holding;
    }

    /**
     * The values of an expression assigned to a variable that is read as
     * a pattern. A variable, an arm of a ternary or of a match and a side
     * of "?:" or "??" select a value and join it to nothing: a variable
     * met there gives each value it may hold, as one read as a pattern
     * does. Anything else is a composition.
     *
     * @return list<ValueExpr>
     */
    private function selected(ValueExpr $expr): array
    {
        switch ($expr->kind) {
            case ValueExpr::VARIABLE:
                return $this->variable($expr, self::SELECTED) ?? [self::rawHole()];

            case ValueExpr::SET:
                $values = [];
                foreach ($expr->alternatives as $alternative) {
                    $arm = ValueExpr::ARRAY === $alternative->kind ? [self::rawHole()] : $this->selected($alternative);
                    if ([] === $arm) {
                        return [];
                    }

                    foreach ($arm as $value) {
                        $values[] = $value;
                    }
                }

                return $values;

            case ValueExpr::FALLBACK:
                $pairs = static fn (array $values): array => array_map(static fn (ValueExpr $value): array => [$value, null], $values);
                $second = $expr->parts[1];

                return array_column(self::kept(
                    $pairs(ValueExpr::ARRAY === $expr->parts[0]->kind ? [self::rawHole()] : $this->selected($expr->parts[0])),
                    fn (): array => $pairs(ValueExpr::ARRAY === $second->kind ? [self::rawHole()] : $this->selected($second)),
                    '??' === $expr->value,
                    $this->mayBeUnset($expr->parts[0], 0),
                ), 0);

            default:
                return $this->values($expr);
        }
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
     * @param int         $height set to the constants the values went through
     * @param string|null $source set to the constant several values are those of, each at its rank: a constant
     *                            has one value wherever it stands
     *
     * @return list<ValueExpr>
     */
    private function resolve(ValueExpr $expr, int $depth, int &$height, ?string &$source = null): array
    {
        $height = 0;
        $source = null;

        switch ($expr->kind) {
            case ValueExpr::LITERAL:
            case ValueExpr::NON_STRING:
                return [$expr];

            case ValueExpr::HOLE:
                // A function the file declares is not the preg_quote() of PHP.
                return [$expr->quoted && !$this->isNative($expr->names) ? self::rawHole() : $expr];

            case ValueExpr::CONCAT:
                return $this->concatenation($expr, $depth, $height, $source);

            case ValueExpr::VARIABLE:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->variable($expr, self::COMPOSED) ?? [self::rawHole()];

            case ValueExpr::PROPERTY:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->propertyValues($expr);

            case ValueExpr::SET:
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
                }, '??' === $expr->value, $this->mayBeUnset($expr->parts[0], $depth));
                $height = max($firstHeight, $secondHeight);

                return array_column($kept, 0);

            case ValueExpr::FORMAT:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->formatted($expr, $depth, $height, $source);

            case ValueExpr::JOIN:
                return $this->constantExpressions > 0 ? [self::rawHole()] : $this->joined($expr, $depth, $height, $source);

            case ValueExpr::ITEM:
                return $this->itemOf($expr, $depth, $height, $source);

            case ValueExpr::EACH:
                $array = $this->arrayOf($expr->parts[0], $depth, $height);
                if (!$array instanceof ValueExpr) {
                    return $array;
                }

                return ValueExpr::KEYS === $expr->value ? self::keysOf($array) : self::valuesOf($array);

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

                return [ValueExpr::arrayOf(self::stored($items))];

            case ValueExpr::CLASS_CONSTANT:
                $class = $expr->class;
                if (null === $class) {
                    return [self::rawHole()];
                }

                // A closure a function of the file or of the project is
                // given may be bound there to another class.
                foreach ($expr->calls as $names) {
                    if (!$this->isNative($names)) {
                        return [self::rawHole()];
                    }
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

                return $this->fold('c:'.$class.'::'.$expr->value, $constant[0], $depth, $height, $source);

            case ValueExpr::CONSTANT:
                foreach ($expr->names as $name) {
                    // PHP keeps its own value for these, whatever a file
                    // declares under their name.
                    if (isset(self::PLATFORM[$name])) {
                        $source = 'p:'.$name;

                        return $this->platformValues[$name] ??= $this->platform($name);
                    }

                    if (\array_key_exists($name, $this->constants)) {
                        $value = $this->constants[$name];

                        return null === $value ? [self::rawHole()] : $this->fold('g:'.$name, $value, $depth, $height, $source);
                    }

                    if (isset(self::predefinedConstants()[$name])) {
                        return [self::rawHole()];
                    }

                    // A constant the file does not declare may be another
                    // file's, declared once at its top level.
                    if (true === $this->project?->declaresGlobal($name)) {
                        $value = $this->project->globalConstant($name);

                        return null === $value ? [self::rawHole()] : $this->fold('g:'.$name, $value, $depth, $height, $source);
                    }
                }

                return [self::rawHole()];

            default:
                return [self::rawHole()];
        }
    }

    /**
     * The values of a constant of PLATFORM, each a text no file writes,
     * which says what it stands for.
     *
     * @return list<ValueExpr>
     */
    private function platform(string $name): array
    {
        $values = [];
        foreach (self::PLATFORM[$name] ?? [] as $text => $says) {
            $value = ValueExpr::literal($text, -1, 0);
            $this->variants[$value] = $says;
            $values[] = $value;
        }

        return $values;
    }

    /**
     * The one array an expression holds, when it is a variable or a
     * constant the file proves an array: the items of a property and of
     * an item are not followed. A hole otherwise, and none when nothing
     * is known of the expression.
     *
     * @param int $height set to the constants the array went through
     *
     * @return ValueExpr|list<ValueExpr> the array, or what the expression reads as without one
     */
    private function arrayOf(ValueExpr $expr, int $depth, int &$height): ValueExpr|array
    {
        if (!\in_array($expr->kind, [ValueExpr::VARIABLE, ValueExpr::CLASS_CONSTANT, ValueExpr::CONSTANT, ValueExpr::ARRAY], true)) {
            return [self::rawHole()];
        }

        $arrays = $this->resolve($expr, $depth, $height);
        if ([] === $arrays) {
            return [];
        }

        return 1 === \count($arrays) && ValueExpr::ARRAY === $arrays[0]->kind ? $arrays[0] : [self::rawHole()];
    }

    /**
     * The values of an item of an array: named by a key the file proves,
     * a string or an integer, that item, as PHP finds it; named by any
     * other key, one of the values of the array. A key the array does not
     * hold names nothing, and an item that is an array is no text: a
     * hole.
     *
     * @param int         $height set to the constants the values went through
     * @param string|null $source set to the constant the item takes several values from
     *
     * @return list<ValueExpr>
     */
    private function itemOf(ValueExpr $expr, int $depth, int &$height, ?string &$source): array
    {
        $array = $this->arrayOf($expr->parts[0], $depth, $height);
        if (!$array instanceof ValueExpr) {
            return $array;
        }

        $keyHeight = 0;
        $keys = $this->resolve($expr->parts[1], $depth, $keyHeight);
        $height = max($height, $keyHeight);
        $key = 1 === \count($keys) ? self::keyOf($keys[0]) : null;
        if (null === $key) {
            return self::valuesOf($array);
        }

        $item = ($this->keyedItems($array) ?: [])[$key] ?? null;
        if (null === $item || ValueExpr::ARRAY === $item->kind) {
            return [self::rawHole()];
        }

        if (ValueExpr::SET !== $item->kind) {
            return [$item];
        }

        $source = $this->sources[$item] ?? null;

        return [] === $item->alternatives ? [self::rawHole()] : $item->alternatives;
    }

    /**
     * Whether PHP may find nothing where "A ?: B" or "A ?? B" reads A, and
     * take B: a variable, which no path is proved to assign, and an item
     * of an array, unless a key the file proves names it in an array that
     * holds the key, a constant or a variable that a statement of the
     * body itself, or of the file, assigns before the read.
     */
    private function mayBeUnset(ValueExpr $expr, int $depth): bool
    {
        if (ValueExpr::ITEM !== $expr->kind) {
            return ValueExpr::VARIABLE === $expr->kind;
        }

        [$held, $named] = $expr->parts;
        $height = 0;
        $array = $this->arrayOf($held, $depth, $height);
        if (!$array instanceof ValueExpr || (ValueExpr::VARIABLE === $held->kind && !$this->assignedOnEveryPath($held))) {
            return true;
        }

        $keys = $this->resolve($named, $depth, $height);
        $key = 1 === \count($keys) ? self::keyOf($keys[0]) : null;

        return null === $key || !isset(($this->keyedItems($array) ?: [])[$key]);
    }

    /**
     * Whether the one assignment that reaches the read of a variable runs
     * on every path to it: a statement of the function body itself, or of
     * the file, which nothing jumps over.
     */
    private function assignedOnEveryPath(ValueExpr $variable): bool
    {
        $scope = $variable->scope;
        $name = $variable->value;
        if (null === $scope || !isset($this->certain[$scope][$name]) || isset($this->targets[$scope][$name]) || isset($this->jumpingScopes[$scope])) {
            return false;
        }

        $assignment = $this->reaching($scope, $name, $variable->offset);

        return null !== $assignment && isset($this->certain[$scope][$name][$assignment]);
    }

    /**
     * The items of a folded array under the keys PHP stores them by,
     * read once for all the items a key names in it; false when a key is
     * not proved.
     *
     * @return array<int|string, ValueExpr>|false
     */
    private function keyedItems(ValueExpr $array): array|false
    {
        return $this->keyedItems[$array] ??= self::keyed($array) ?? false;
    }

    /**
     * The items of an array literal as PHP keeps them: a key written
     * twice holds its last value, at the place of the first. A key is
     * written twice as PHP stores it: the index an item with no key
     * takes, the integer a string of digits writes. An item whose key
     * the file does not prove is kept beside the others, and so are the
     * items with no key after it, whose index is then PHP's to say.
     *
     * @param list<array{0: ValueExpr|null, 1: ValueExpr}> $items the items, each key and each value folded
     *
     * @return list<array{0: ValueExpr|null, 1: ValueExpr}>
     */
    private static function stored(array $items): array
    {
        $stored = [];
        $places = [];
        $next = 0;
        foreach ($items as $item) {
            [$key, $value] = $item;
            if (null === $key) {
                $at = $next;
                $next = null === $next ? null : $next + 1;
            } else {
                $at = self::keyOf($key);
                if (null === $at) {
                    $next = null;
                } elseif (\is_int($at) && null !== $next) {
                    // After a negative index alone, the next one is PHP's
                    // to say.
                    $next = $at < 0 && 0 === $next ? null : max($next, $at + 1);
                }
            }

            if (null !== $at && isset($places[$at])) {
                $stored[$places[$at]] = [$stored[$places[$at]][0], $value];

                continue;
            }

            if (null !== $at) {
                $places[$at] = \count($stored);
            }

            $stored[] = $item;
        }

        return $stored;
    }

    /**
     * The key PHP stores an item under, given the value of its key: an
     * integer for one, and for a string that writes one the canonical
     * way; null for a key the file does not prove.
     */
    private static function keyOf(ValueExpr $key): int|string|null
    {
        if (ValueExpr::LITERAL === $key->kind) {
            return PatternFunction::isStringKey($key->value) ? $key->value : (int) $key->value;
        }

        return ValueExpr::NON_STRING === $key->kind ? $key->integer : null;
    }

    /**
     * The items of an array under the keys PHP stores them by, the last
     * of a key written twice; null when a key is one the file does not
     * prove, which may be any of the others. An item with no key takes
     * the index after the highest one; after a negative index alone, PHP
     * counts on from it or from zero, as its version has it: not told.
     *
     * @return array<int|string, ValueExpr>|null
     */
    private static function keyed(ValueExpr $array): ?array
    {
        $items = [];
        $next = 0;
        $counted = true;
        foreach ($array->items as [$key, $value]) {
            if (null === $key) {
                if (!$counted) {
                    return null;
                }

                $items[$next++] = $value;

                continue;
            }

            $at = self::keyOf($key);
            if (null === $at) {
                return null;
            }

            $items[$at] = $value;
            if (\is_int($at) && $at >= $next) {
                $next = $at + 1;
            } elseif (\is_int($at) && $at < 0 && 0 === $next) {
                $counted = false;
            }
        }

        return $items;
    }

    /**
     * The values an array may give, one per item: each value an item may
     * take, and a hole for an item that is an array or of which nothing
     * is known.
     *
     * @return list<ValueExpr>
     */
    private static function valuesOf(ValueExpr $array): array
    {
        $values = [];
        foreach ($array->items as [, $item]) {
            foreach (self::itemValues($item) as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The keys an array may give, one per item: the key written, an
     * integer for a string that writes one, and the index PHP gives an
     * item with no key.
     *
     * @return list<ValueExpr>
     */
    private static function keysOf(ValueExpr $array): array
    {
        $keys = [];
        $next = 0;
        foreach ($array->items as [$key]) {
            if (null === $key) {
                $keys[] = null === $next ? self::rawHole() : ValueExpr::nonString($next++);

                continue;
            }

            foreach (self::itemValues($key) as $value) {
                $at = self::keyOf($value);
                if (\is_int($at)) {
                    $value = ValueExpr::nonString($at);
                    // After a negative index alone, the next one is PHP's
                    // to say.
                    $next = null === $next || ($at < 0 && 0 === $next) ? null : max($next, $at + 1);
                } elseif (null === $at) {
                    $next = null;
                }

                $keys[] = $value;
            }
        }

        return $keys;
    }

    /**
     * What one item of an array may be: each of its values, a hole for
     * an array or for an item nothing is known of.
     *
     * @return list<ValueExpr>
     */
    private static function itemValues(ValueExpr $item): array
    {
        if (ValueExpr::ARRAY === $item->kind) {
            return [self::rawHole()];
        }

        if (ValueExpr::SET !== $item->kind) {
            return [$item];
        }

        return [] === $item->alternatives ? [self::rawHole()] : $item->alternatives;
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
     * An item of an array: its value, or the set of those it may take,
     * which keeps the constant it takes them from.
     *
     * @param int $height set to the constants the item went through
     */
    private function item(ValueExpr $expr, int $depth, int &$height): ValueExpr
    {
        $source = null;
        $values = $this->resolve($expr, $depth, $height, $source);
        if (1 === \count($values)) {
            return $values[0];
        }

        $set = ValueExpr::setOf($values);
        if (null !== $source) {
            $this->sources[$set] = $source;
        }

        return $set;
    }

    /**
     * The values a concatenation may take: those of its operands, each
     * followed by each, as far as PatternArgument::concat() builds them.
     * None when it does not, or when nothing is known of an operand: the
     * operands after that one are not read.
     *
     * @param int         $height set to the constants the values went through
     * @param string|null $source set to the constant the values follow, each at its rank
     *
     * @return list<ValueExpr>
     */
    private function concatenation(ValueExpr $expr, int $depth, int &$height, ?string &$source = null): array
    {
        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        $variants = [null];
        $driver = null;
        foreach ($expr->parts as $part) {
            $partHeight = 0;
            $partSource = null;
            $alternatives = $this->followedBy($alternatives, $variants, $driver, $this->resolve($part, $depth, $partHeight, $partSource), $partSource, static fn (ValueExpr $value): array => self::parts($value, true));
            if (null === $alternatives) {
                return [];
            }

            $height = max($height, $partHeight);
        }

        $values = $this->composed($alternatives, $variants, $expr);
        $source = \count($values) > 1 ? $driver : null;

        return $values;
    }

    /**
     * The alternatives of a composition, each followed by each value of
     * one more operand; null when PatternArgument::concat() does not build
     * them, or when nothing is known of the operand. An operand of more
     * values than a composition may give is refused before any is read.
     *
     * A constant has one value wherever it stands: an operand that takes
     * its values from the constant the alternatives already follow gives
     * each alternative its own, and multiplies nothing.
     *
     * @param list<list<string|Hole>>                $alternatives
     * @param list<string|null>                      $variants     what each alternative says of a constant of PLATFORM,
     *                                                             brought up to date
     * @param string|null                            $driver       the constant the alternatives follow, each at its
     *                                                             rank, brought up to date
     * @param list<ValueExpr>                        $values       the values of the operand
     * @param string|null                            $source       the constant they are those of, each at its rank
     * @param \Closure(ValueExpr): list<string|Hole> $parts        what a value reads as in the composition
     *
     * @return list<list<string|Hole>>|null
     */
    private function followedBy(array $alternatives, array &$variants, ?string &$driver, array $values, ?string $source, \Closure $parts): ?array
    {
        $count = \count($values);
        if (0 === $count || $count > PatternArgument::MAX_CANDIDATES) {
            return null;
        }

        $operand = [];
        $says = [];
        foreach ($values as $value) {
            $operand[] = $parts($value);
            $says[] = $this->variants[$value] ?? null;
        }

        if ($count > 1 && null !== $source && $source === $driver && \count($alternatives) === $count) {
            $aligned = [];
            foreach ($alternatives as $rank => $alternative) {
                $aligned[] = [...$alternative, ...$operand[$rank] ?? []];
            }

            return $aligned;
        }

        $product = PatternArgument::concat($alternatives, $operand);
        if (null === $product) {
            return null;
        }

        if ($count > 1) {
            // One alternative, followed by each value.
            $before = $variants[0] ?? null;
            $variants = array_map(static fn (?string $say): ?string => $say ?? $before, $says);
            $driver = $source;
        } elseif (null !== $says[0]) {
            $said = $says[0];
            $variants = array_map(static fn (?string $variant): string => $variant ?? $said, $variants);
        }

        return $product;
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
     * @param int         $height set to the constants the values went through
     * @param string|null $source set to the constant the values follow, each at its rank
     *
     * @return list<ValueExpr>
     */
    private function formatted(ValueExpr $expr, int $depth, int &$height, ?string &$source = null): array
    {
        $formats = $this->resolve($expr->parts[0], $depth, $height);
        if ([] === $formats) {
            return [];
        }

        $pieces = 1 === \count($formats) && ValueExpr::LITERAL === $formats[0]->kind ? self::places($formats[0]->value) : null;
        // A function the file declares is not the sprintf() of PHP.
        if (null === $pieces || !$this->isNative($expr->names) || intdiv(\count($pieces), 2) >= \count($expr->parts)) {
            return [self::rawHole()];
        }

        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        $variants = [null];
        $driver = null;
        $argument = 1;
        foreach ($pieces as $piece) {
            if (\is_string($piece)) {
                $alternatives = PatternArgument::concat($alternatives, [[$piece]]);
            } else {
                $argumentHeight = 0;
                $argumentSource = null;
                $alternatives = $this->followedBy(
                    $alternatives,
                    $variants,
                    $driver,
                    $this->resolve($expr->parts[$argument++], $depth, $argumentHeight, $argumentSource),
                    $argumentSource,
                    static fn (ValueExpr $value): array => !$piece || null !== $value->integer ? self::parts($value, true) : [self::unread()],
                );
                $height = max($height, $argumentHeight);
            }

            if (null === $alternatives) {
                return [];
            }
        }

        $values = $this->composed($alternatives, $variants, $expr);
        $source = \count($values) > 1 ? $driver : null;

        return $values;
    }

    /**
     * The values of an implode() or join() call: the items of its array,
     * each a string or an integer the file proves, joined by its
     * separator, one string the file proves, or by nothing without one.
     * Any other separator or item, and the whole call is a part the file
     * does not prove. The items multiply as the operands of a
     * concatenation do.
     *
     * @param int         $height set to the constants the values went through
     * @param string|null $source set to the constant the values follow, each at its rank
     *
     * @return list<ValueExpr>
     */
    private function joined(ValueExpr $expr, int $depth, int &$height, ?string &$source = null): array
    {
        $parts = $expr->parts;
        $separator = '';
        if (2 === \count($parts)) {
            $separators = $this->resolve($parts[0], $depth, $height);
            if ([] === $separators) {
                return [];
            }

            if (1 !== \count($separators) || ValueExpr::LITERAL !== $separators[0]->kind) {
                return [self::rawHole()];
            }

            $separator = $separators[0]->value;
        }

        $arrayHeight = 0;
        $arrays = $this->resolve($parts[\count($parts) - 1], $depth, $arrayHeight);
        $height = max($height, $arrayHeight);
        if ([] === $arrays) {
            return [];
        }

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

            $items[] = [$values, ValueExpr::SET === $item->kind ? $this->sources[$item] ?? null : null];
        }

        /** @var list<list<string|Hole>> $alternatives */
        $alternatives = [[]];
        $variants = [null];
        $driver = null;
        foreach ($items as $position => [$values, $itemSource]) {
            $before = 0 === $position ? '' : $separator;
            $alternatives = $this->followedBy($alternatives, $variants, $driver, $values, $itemSource, static fn (ValueExpr $value): array => [$before.(ValueExpr::LITERAL === $value->kind ? $value->value : (string) $value->integer)]);
            if (null === $alternatives) {
                return [];
            }
        }

        $values = $this->composed($alternatives, $variants, $expr);
        $source = \count($values) > 1 ? $driver : null;

        return $values;
    }

    /**
     * The values a composition gives, from the parts of each: a whole
     * pattern is reported where the composition starts; around a hole,
     * what is written is kept. One that would fold past MAX_LENGTH is not
     * read: a hole.
     *
     * @param list<list<string|Hole>> $alternatives
     * @param list<string|null>       $variants     what each alternative says of a constant of PLATFORM
     * @param ValueExpr               $expr         the concatenation, or the call
     *
     * @return list<ValueExpr>
     */
    private function composed(array $alternatives, array $variants, ValueExpr $expr): array
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
        foreach ($alternatives as $rank => $parts) {
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
                $value = ValueExpr::literal($text ?? '', $expr->offset, $expr->line, $expr->file, $expr->column, $expr->guard);
            } else {
                if (null !== $text) {
                    $pieces[] = ValueExpr::literal($text, $expr->offset, $expr->line);
                }

                $value = ValueExpr::concat($pieces, $expr->offset, $expr->line);
            }

            $variant = $variants[$rank] ?? null;
            if (null !== $variant) {
                $this->variants[$value] = $variant;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * The values of a constant, at most MAX_DEPTH constants from the call,
     * none of them met twice on the way: a hole past them.
     *
     * @param int         $height set to the constants the values went through, this one included
     * @param string|null $source set to the constant several values are those of, each at its rank: this one, or
     *                            the one it follows when it only names it or joins it to texts
     *
     * @return list<ValueExpr>
     */
    private function fold(string $key, ValueExpr $value, int $depth, int &$height, ?string &$source = null): array
    {
        $source = null;
        if (isset($this->folded[$key])) {
            [$resolved, $height, $followed] = $this->folded[$key];
            if ($depth + $height > self::MAX_DEPTH) {
                $this->cuts++;

                return [self::rawHole()];
            }

            $source = $followed;

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
            [, $resolved, $height, $source] = $this->cutShort[$key];

            return $resolved;
        }

        $cuts = $this->cuts;
        $this->folding[$key] = true;
        $this->constantExpressions++;
        $below = 0;
        $followed = null;
        $resolved = $this->resolve($value, $depth + 1, $below, $followed);
        $this->constantExpressions--;
        unset($this->folding[$key]);
        $height = $below + 1;
        $source = \count($resolved) > 1 ? $followed ?? $key : null;

        // A value a guard cut short is the value from this depth: read
        // from nearer the call, the constant may give more. It is kept
        // for this depth and beyond only.
        if ($cuts === $this->cuts) {
            $this->folded[$key] = [$resolved, $height, $source];
        } else {
            $this->cutShort[$key] = [$depth, $resolved, $height, $source];
        }

        return $resolved;
    }
}
