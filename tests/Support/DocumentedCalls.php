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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\NodeVisitorInterface;

/**
 * The method calls of a PHP code example, each with the library class it is
 * made on, when the example says which class that is.
 *
 * A receiver is resolved only when the example names its class exactly:
 *
 * - a class name, through the example's `use` lines, a fully qualified
 *   name, or (with no `use` line) a short name only one library class has;
 * - `new X(...)`, `X::method(...)` and `$object->method(...)`, typed by the
 *   declared return type of the method (`self`, `static`, `?X` included);
 * - `$node->accept(new SomeVisitor())`, typed by the visitor's
 *   `visit<Node>()` method for that node class;
 * - a variable, by its last plain assignment (`$x = <one of the above>;`)
 *   or by the declared type of the parameter it is;
 * - `$this`, inside a class the example declares, as its parent class; in
 *   an example that declares no class, as the library class whose file the
 *   prose line just above the example names (`src/.../X.php`).
 *
 * Anything else (a class of another library, a variable assigned from an
 * expression, a method the example defines itself) is not resolved and its
 * calls are not checked.
 *
 * A library class the example names that does not exist, or whose file
 * cannot be loaded (a syntax error, a missing library class it depends on),
 * is a problem of every call made on it. A library class that cannot be
 * loaded because a class of another library is missing (the Laravel bridge
 * without Laravel installed) is not checked.
 */
final class DocumentedCalls
{
    private const LOADED = 'loaded';

    private const UNCHECKED = 'unchecked';

    private const NAME_TOKENS = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED];

    private const OBJECT_OPERATORS = [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR];

    /**
     * Declared types a method cannot be called on.
     */
    private const SCALAR_TYPES = ['array', 'string', 'int', 'float', 'bool', 'false', 'true', 'null', 'void', 'never'];

    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $classesByShortName = null;

    /**
     * Library classes asked for: LOADED, UNCHECKED, or what is wrong.
     *
     * @var array<string, string>
     */
    private static array $loading = [];

    /**
     * @var list<array{0: int, 1: string, 2: int}>
     */
    private array $tokens = [];

    /**
     * @var array<string, string>
     */
    private array $imports = [];

    /**
     * Classes the example declares: name => body range and parent, as the
     * example spells it.
     *
     * @var array<string, array{open: int, close: int, parent: ?string}>
     */
    private array $localClasses = [];

    /**
     * @var array<string, true>
     */
    private array $localMethods = [];

    /**
     * @var array<string, array{kind: string, name: string, scope: int}|null>
     */
    private array $variables = [];

    private ?string $introClass = null;

    private function __construct(
        string $code,
        string $intro,
        private readonly int $lineOffset
    ) {
        $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code;
        $shift = str_starts_with(ltrim($code), '<?php') ? 0 : 1;

        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                $line = $token[2];
                if (\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_CLOSE_TAG, \T_INLINE_HTML], true)) {
                    continue;
                }
                $this->tokens[] = [$token[0], $token[1], $token[2] - $shift];

                continue;
            }

            $this->tokens[] = [0, $token, $line - $shift];
        }

        if (1 === preg_match_all('#\bsrc/[A-Za-z0-9_/]+\.php\b#', $intro, $paths)) {
            $class = 'PHPRegex\\'.str_replace('/', '\\', substr($paths[0][0], 4, -4));
            $this->introClass = self::exists($class) ? $class : null;
        }
    }

    /**
     * Every `->method(` and `::method(` call of the example made on a
     * library class: the page line, the class and method, and what is wrong
     * with it (null when the class has the method).
     *
     * @return list<array{line: int, class: string, method: string, problem: ?string}>
     */
    public static function inExample(string $code, string $intro, int $firstLine): array
    {
        return (new self($code, $intro, $firstLine - 1))->scan();
    }

    /**
     * @return list<array{line: int, class: string, method: string, problem: ?string}>
     */
    private function scan(): array
    {
        $this->collectDeclarations();

        $calls = [];
        $pending = [];
        $count = \count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            if (isset($pending[$i])) {
                foreach ($pending[$i] as [$variable, $type]) {
                    $this->variables[$variable] = $type;
                }
                unset($pending[$i]);
            }

            [$id, $text] = $this->tokens[$i];

            if (\in_array($id, [\T_FUNCTION, \T_FN, \T_CATCH], true)) {
                $this->typeParameters($i);

                continue;
            }

            if (\T_VARIABLE === $id && $this->is($i + 1, '=')) {
                $end = $this->expressionEnd($i + 2);
                $pending[$end][] = [$text, $this->typeOf($i + 2, $end)];

                continue;
            }

            if (\T_VARIABLE === $id && \in_array($this->tokens[$i + 1][0] ?? null, [\T_CONCAT_EQUAL, \T_PLUS_EQUAL, \T_COALESCE_EQUAL, \T_MINUS_EQUAL], true)) {
                $this->variables[$text] = null;

                continue;
            }

            if (']' === $text && 0 === $id && $this->is($i + 1, '=')) {
                for ($j = $this->matchBackward($i); $j < $i; $j++) {
                    if (\T_VARIABLE === $this->tokens[$j][0]) {
                        $this->variables[$this->tokens[$j][1]] = null;
                    }
                }

                continue;
            }

            if (\T_AS === $id) {
                for ($j = $i + 1; $j < $count && !$this->is($j, ')'); $j++) {
                    if (\T_VARIABLE === $this->tokens[$j][0]) {
                        $this->variables[$this->tokens[$j][1]] = null;
                    }
                }

                continue;
            }

            $isObject = \in_array($id, self::OBJECT_OPERATORS, true);
            if ((!$isObject && \T_DOUBLE_COLON !== $id) || \T_STRING !== ($this->tokens[$i + 1][0] ?? null) || !$this->is($i + 2, '(')) {
                continue;
            }

            $method = $this->tokens[$i + 1][1];
            if (isset($this->localMethods[strtolower($method)])) {
                continue;
            }

            $receiver = $isObject
                ? $this->typeOf($this->chainStart($i - 1), $i)
                : $this->staticReceiver($i - 1);

            if (null === $receiver) {
                continue;
            }

            $problem = $this->problem($receiver, $method, !$isObject);
            if (null === $problem && 'class' !== $receiver['kind']) {
                continue;
            }

            $calls[] = [
                'line' => $this->tokens[$i + 1][2] + $this->lineOffset,
                'class' => $receiver['name'],
                'method' => $method,
                'problem' => $problem,
            ];
        }

        return $calls;
    }

    private function collectDeclarations(): void
    {
        $count = \count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id] = $this->tokens[$i];

            if (\T_USE === $id && \in_array($this->tokens[$i + 1][0] ?? null, self::NAME_TOKENS, true) && $this->atStatementStart($i)) {
                $name = ltrim($this->tokens[$i + 1][1], '\\');
                $alias = substr($name, (int) strrpos('\\'.$name, '\\'));
                if (\T_AS === ($this->tokens[$i + 2][0] ?? null)) {
                    $alias = $this->tokens[$i + 3][1] ?? $alias;
                }
                $this->imports[strtolower($alias)] = $name;

                continue;
            }

            if (\T_FUNCTION === $id && \T_STRING === ($this->tokens[$i + 1][0] ?? null)) {
                $this->localMethods[strtolower($this->tokens[$i + 1][1])] = true;

                continue;
            }

            if (\in_array($id, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true)
                && \T_STRING === ($this->tokens[$i + 1][0] ?? null)
                && \T_DOUBLE_COLON !== ($this->tokens[$i - 1][0] ?? null)) {
                $parent = null;
                $open = $i + 2;
                while ($open < $count && !$this->is($open, '{')) {
                    if (\T_EXTENDS === $this->tokens[$open][0] && null === $parent) {
                        $parent = $this->tokens[$open + 1][1] ?? null;
                    }
                    $open++;
                }
                $this->localClasses[strtolower($this->tokens[$i + 1][1])] = [
                    'open' => $open,
                    'close' => $open < $count ? $this->matchForward($open) : $count,
                    'parent' => $parent,
                ];
            }
        }
    }

    /**
     * Types the parameters of the function, closure or catch at $i.
     */
    private function typeParameters(int $i): void
    {
        $open = $i + 1;
        while (isset($this->tokens[$open]) && !$this->is($open, '(') && !$this->is($open, '{') && !$this->is($open, ';')) {
            $open++;
        }
        if (!$this->is($open, '(')) {
            return;
        }

        $close = $this->matchForward($open);
        for ($j = $open + 1; $j < $close; $j++) {
            if (\T_VARIABLE !== $this->tokens[$j][0]) {
                continue;
            }

            $type = null;
            $before = $this->tokens[$j - 1];
            $beforeType = $this->tokens[$j - 2] ?? [0, '', 0];
            if (\in_array($before[0], self::NAME_TOKENS, true) && !\in_array($beforeType[1], ['|', '&'], true)) {
                $type = $this->resolve($before[1]);
            }
            $this->variables[$this->tokens[$j][1]] = $type;
        }
    }

    /**
     * The type of the expression made of the tokens [$start, $end), when it
     * is one chain the class of which can be told.
     *
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function typeOf(int $start, int $end): ?array
    {
        if ($start >= $end || !isset($this->tokens[$start])) {
            return null;
        }

        $i = $start;
        [$id, $text] = $this->tokens[$i];

        if (\T_NEW === $id) {
            if (!\in_array($this->tokens[$i + 1][0] ?? null, self::NAME_TOKENS, true)) {
                return null;
            }
            $type = $this->resolve($this->tokens[$i + 1][1]);
            $i += 2;
            if ($this->is($i, '(')) {
                $i = $this->matchForward($i) + 1;
            }
        } elseif ('(' === $text && 0 === $id) {
            $close = $this->matchForward($i);
            $type = $this->typeOf($i + 1, $close);
            $i = $close + 1;
        } elseif (\in_array($id, self::NAME_TOKENS, true) && $this->is($i + 1, '::', \T_DOUBLE_COLON) && \T_STRING === ($this->tokens[$i + 2][0] ?? null) && $this->is($i + 3, '(')) {
            $class = $this->resolve($text);
            $type = null === $class ? null : $this->returnType($class, $this->tokens[$i + 2][1], $i + 3);
            $i = $this->matchForward($i + 3) + 1;
        } elseif (\T_VARIABLE === $id) {
            $type = '$this' === $text ? $this->thisType($i) : ($this->variables[$text] ?? null);
            $i++;
        } else {
            return null;
        }

        while ($i < $end) {
            if (!\in_array($this->tokens[$i][0], self::OBJECT_OPERATORS, true) || \T_STRING !== ($this->tokens[$i + 1][0] ?? null)) {
                return null;
            }

            $member = $this->tokens[$i + 1][1];
            if ($this->is($i + 2, '(')) {
                $type = null === $type ? null : $this->returnType($type, $member, $i + 2);
                $i = $this->matchForward($i + 2) + 1;
            } else {
                $type = null === $type ? null : $this->propertyType($type, $member);
                $i += 2;
            }
        }

        return $type;
    }

    /**
     * Where the chain ending at token $j starts.
     */
    private function chainStart(int $j): int
    {
        while ($j >= 0) {
            [$id, $text] = $this->tokens[$j];

            if (0 === $id && ')' === $text) {
                $open = $this->matchBackward($j);
                $callee = $open - 1;
                if ($callee < 0 || !\in_array($this->tokens[$callee][0], self::NAME_TOKENS, true)) {
                    return $open;
                }
                $before = $this->tokens[$callee - 1] ?? [0, '', 0];
                if (\in_array($before[0], self::OBJECT_OPERATORS, true)) {
                    $j = $callee - 2;

                    continue;
                }
                if (\T_DOUBLE_COLON === $before[0] || \T_NEW === $before[0]) {
                    return $callee - 2 >= 0 && \T_DOUBLE_COLON === $before[0] ? $callee - 2 : $callee - 1;
                }

                return $callee;
            }

            if (\T_STRING === $id && \in_array($this->tokens[$j - 1][0] ?? null, self::OBJECT_OPERATORS, true)) {
                $j -= 2;

                continue;
            }

            return $j;
        }

        return 0;
    }

    /**
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function staticReceiver(int $j): ?array
    {
        if ($j < 0 || !\in_array($this->tokens[$j][0], self::NAME_TOKENS, true)) {
            return null;
        }

        return $this->resolve($this->tokens[$j][1]);
    }

    /**
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function thisType(int $i): ?array
    {
        $inside = null;
        foreach ($this->localClasses as $class) {
            if ($class['open'] < $i && $i < $class['close'] && (null === $inside || $class['open'] > $inside['open'])) {
                $inside = $class;
            }
        }

        if (null !== $inside) {
            $parent = null === $inside['parent'] ? null : $this->resolve($inside['parent']);

            return null === $parent ? null : ['kind' => $parent['kind'], 'name' => $parent['name'], 'scope' => 1];
        }

        return null === $this->introClass ? null : ['kind' => 'class', 'name' => $this->introClass, 'scope' => 2];
    }

    /**
     * @param array{kind: string, name: string, scope: int} $receiver
     *
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function returnType(array $receiver, string $method, int $open): ?array
    {
        if ('class' !== $receiver['kind'] || !method_exists($receiver['name'], $method)) {
            return null;
        }

        $close = $this->matchForward($open);
        if ('accept' === strtolower($method) && is_subclass_of($receiver['name'], NodeInterface::class)) {
            $visitor = $this->typeOf($open + 1, $close);
            $short = substr($receiver['name'], (int) strrpos($receiver['name'], '\\') + 1);
            if (null !== $visitor && 'class' === $visitor['kind'] && is_a($visitor['name'], NodeVisitorInterface::class, true)
                && str_ends_with($short, 'Node') && method_exists($visitor['name'], 'visit'.substr($short, 0, -4))) {
                return $this->declaredType(new \ReflectionMethod($visitor['name'], 'visit'.substr($short, 0, -4)), $visitor['name']);
            }

            return null;
        }

        return $this->declaredType(new \ReflectionMethod($receiver['name'], $method), $receiver['name']);
    }

    /**
     * @param array{kind: string, name: string, scope: int} $receiver
     *
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function propertyType(array $receiver, string $property): ?array
    {
        $class = 'class' === $receiver['kind'] ? self::reflection($receiver['name']) : null;
        if (null === $class || !$class->hasProperty($property)) {
            return null;
        }

        $type = $class->getProperty($property)->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return $this->namedType($type, $receiver['name']);
    }

    /**
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function declaredType(\ReflectionMethod $method, string $receiver): ?array
    {
        $type = $method->getReturnType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        return $this->namedType($type, 'self' === $type->getName() ? $method->getDeclaringClass()->getName() : $receiver);
    }

    /**
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function namedType(\ReflectionNamedType $type, string $self): ?array
    {
        $name = $type->getName();
        if (\in_array($name, ['self', 'static'], true)) {
            return ['kind' => 'class', 'name' => $self, 'scope' => 0];
        }

        if ($type->isBuiltin()) {
            return \in_array($name, self::SCALAR_TYPES, true) && !$type->allowsNull() ? ['kind' => 'scalar', 'name' => $name, 'scope' => 0] : null;
        }

        return str_starts_with($name, 'PHPRegex\\') && self::exists($name) ? ['kind' => 'class', 'name' => $name, 'scope' => 0] : null;
    }

    /**
     * @param array{kind: string, name: string, scope: int} $receiver
     */
    private function problem(array $receiver, string $method, bool $static): ?string
    {
        if ('scalar' === $receiver['kind']) {
            return '->'.$method.'() is called on a value of type '.$receiver['name'];
        }

        if ('broken' === $receiver['kind']) {
            return self::load($receiver['name']);
        }

        $class = self::reflection($receiver['name']);
        if (null === $class || $class->hasMethod($static ? '__callStatic' : '__call')) {
            return null;
        }

        if (!$class->hasMethod($method)) {
            return $receiver['name'].' has no method '.$method.'()';
        }

        $reflection = $class->getMethod($method);
        if ($reflection->isPrivate() && $receiver['scope'] < 2 || $reflection->isProtected() && $receiver['scope'] < 1) {
            return $receiver['name'].'::'.$method.'() is not public';
        }

        if ($static && !$reflection->isStatic()) {
            return $receiver['name'].'::'.$method.'() is not static';
        }

        return null;
    }

    /**
     * The library class a name in the example stands for: a class that
     * loads, or a broken one (missing, or its file cannot be loaded).
     *
     * @return array{kind: string, name: string, scope: int}|null
     */
    private function resolve(string $name): ?array
    {
        if (str_starts_with($name, '\\')) {
            $class = substr($name, 1);
        } else {
            $parts = explode('\\', $name, 2);
            $first = strtolower($parts[0]);
            if (\in_array($first, ['self', 'static', 'parent'], true) || (1 === \count($parts) && isset($this->localClasses[$first]))) {
                return null;
            }
            if (isset($this->imports[$first])) {
                $class = $this->imports[$first].(isset($parts[1]) ? '\\'.$parts[1] : '');
            } elseif (1 === \count($parts) && 1 === \count(self::classesByShortName()[$name] ?? [])) {
                $class = self::classesByShortName()[$name][0];
            } else {
                return null;
            }
        }

        if (!str_starts_with($class, 'PHPRegex\\')) {
            return null;
        }

        return match (self::load($class)) {
            self::LOADED => ['kind' => 'class', 'name' => $class, 'scope' => 0],
            self::UNCHECKED => null,
            default => ['kind' => 'broken', 'name' => $class, 'scope' => 0],
        };
    }

    /**
     * @return \ReflectionClass<object>|null
     */
    private static function reflection(string $class): ?\ReflectionClass
    {
        if (self::exists($class) && (class_exists($class) || interface_exists($class) || trait_exists($class))) {
            return new \ReflectionClass($class);
        }

        return null;
    }

    private static function exists(string $class): bool
    {
        return self::LOADED === self::load($class);
    }

    /**
     * LOADED when the class loads; UNCHECKED when it cannot, because a class
     * of another library it depends on is missing (a bridge whose framework
     * is not installed); otherwise what is wrong with it.
     */
    private static function load(string $class): string
    {
        if (isset(self::$loading[$class])) {
            return self::$loading[$class];
        }

        try {
            $state = class_exists($class) || interface_exists($class) || trait_exists($class)
                ? self::LOADED
                : $class.' does not exist';
        } catch (\Error $error) {
            $state = 1 === preg_match('/^(?:Class|Interface|Trait|Enum) "\\\\?+([^"]++)" not found$/', $error->getMessage(), $missing)
                && !str_starts_with($missing[1], 'PHPRegex\\')
                ? self::UNCHECKED
                : $class.' cannot be loaded: '.$error->getMessage();
        }

        return self::$loading[$class] = $state;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function classesByShortName(): array
    {
        if (null !== self::$classesByShortName) {
            return self::$classesByShortName;
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(DocumentationPages::ROOT.'/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen(DocumentationPages::ROOT.'/src/'), -4));
            if (str_contains('/'.$relative.'/', '/Tests/') || str_contains('/'.$relative.'/', '/Resources/')) {
                continue;
            }
            $classes[basename($relative)][] = 'PHPRegex\\'.str_replace('/', '\\', $relative);
        }

        return self::$classesByShortName = $classes;
    }

    private function atStatementStart(int $i): bool
    {
        return 0 === $i || \in_array($this->tokens[$i - 1][1] ?? null, [';', '{', '}'], true);
    }

    private function is(int $i, string $text, int $id = 0): bool
    {
        return isset($this->tokens[$i]) && $id === $this->tokens[$i][0] && $text === $this->tokens[$i][1];
    }

    private function expressionEnd(int $i): int
    {
        $depth = 0;
        $count = \count($this->tokens);
        for (; $i < $count; $i++) {
            [$id, $text] = $this->tokens[$i];
            if (0 !== $id && \T_CURLY_OPEN !== $id && \T_DOLLAR_OPEN_CURLY_BRACES !== $id) {
                continue;
            }
            if (\in_array($text, ['(', '[', '{', '${'], true)) {
                $depth++;
            } elseif (\in_array($text, [')', ']', '}'], true)) {
                if (0 === $depth) {
                    return $i;
                }
                $depth--;
            } elseif (0 === $depth && \in_array($text, [';', ','], true)) {
                return $i;
            }
        }

        return $count;
    }

    private function matchForward(int $open): int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $opening = $this->tokens[$open][1];
        $closing = $pairs[$opening] ?? ')';
        $depth = 0;
        $count = \count($this->tokens);
        for ($i = $open; $i < $count; $i++) {
            $text = $this->tokens[$i][1];
            $isOpening = $text === $opening || ('{' === $opening && \in_array($this->tokens[$i][0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true));
            if ($isOpening && (0 === $this->tokens[$i][0] || '{' === $opening)) {
                $depth++;
            } elseif (0 === $this->tokens[$i][0] && $text === $closing && 0 === --$depth) {
                return $i;
            }
        }

        return $count;
    }

    private function matchBackward(int $close): int
    {
        $pairs = [')' => '(', ']' => '['];
        $closing = $this->tokens[$close][1];
        $opening = $pairs[$closing] ?? '(';
        $depth = 0;
        for ($i = $close; $i >= 0; $i--) {
            if (0 !== $this->tokens[$i][0]) {
                continue;
            }
            if ($this->tokens[$i][1] === $closing) {
                $depth++;
            } elseif ($this->tokens[$i][1] === $opening && 0 === --$depth) {
                return $i;
            }
        }

        return 0;
    }
}
