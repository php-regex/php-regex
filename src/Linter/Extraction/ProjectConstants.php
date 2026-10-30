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
use PHPRegex\Parser\Text\Path;

/**
 * The class constants and the enum cases of the project's files, read once
 * before the patterns: what a call may name through a class its own file
 * does not declare, `preg_match(Patterns::EMAIL, $s)`.
 *
 * Each file contributes its classes, each with whether it is final, and
 * their constants whose value the file can prove on its own: a literal, a
 * number, a constant, a concatenation or an array of those. A value that
 * holds a variable, a property or an expression the file does not follow
 * is left out. A class declared in two files is ambiguous and gives
 * nothing; a project class hides a library class of the same name.
 *
 * A contribution is a plain array holding ValueExpr trees, so that it
 * travels from a worker process; each literal knows the file, the line and
 * the column it is written at, where the pattern is reported.
 *
 * @phpstan-type Contribution array{classes: array<string, bool|null>, constants: array<string, array{ValueExpr, bool}>}
 *
 * @internal
 */
final class ProjectConstants
{
    /**
     * @param array<string, bool|null>              $classes   lowercase class => whether it is final; null when ambiguous
     * @param array<string, array{ValueExpr, bool}> $constants "class::NAME" => the value and whether the constant is final
     */
    private function __construct(private readonly array $classes, private readonly array $constants) {}

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
     * prove, each literal placed in $file.
     *
     * @return Contribution
     */
    public static function scan(string $content, string $file): array
    {
        // A text declaring no constant and no backed enum case contributes
        // nothing, and is not tokenized: "const" followed by a name, or
        // "case" followed by a name and "=" (a switch's case takes none).
        if (1 !== LibraryPcre::match('/\b(?:const\s+[A-Za-z_\x80-\xff]|case\s+[A-Za-z_\x80-\xff][\w\x80-\xff]*\s*=)/', $content)) {
            return self::nothing();
        }

        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return self::nothing();
        }

        // The classes, the interfaces and the enums alone are visited:
        // their members declare the constants and the cases; a trait has
        // no class to declare them under, a function body declares none.
        $tokens = token_get_all($content);
        $collector = new TokenValueCollector($tokens, PhpTokens::offsets($tokens), PhpTokens::closers($tokens), $content, [], false);
        $context = new NameResolutionContext();
        for ($i = 0, $totalTokens = \count($tokens); $i < $totalTokens; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $i = TokenNameResolver::readNamespace($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\T_USE === $token[0]) {
                // An import moves on past its statement; a trait use, or a
                // closure's, stays where it is and declares nothing here.
                $i = TokenNameResolver::readUse($tokens, $i, $totalTokens, $context);

                continue;
            }

            if (\in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_ENUM], true)) {
                $collector->visit($i, $context);
            }
        }

        ['classes' => $classes, 'constants' => $constants] = $collector->values()->declarations();
        $kept = [];
        foreach ($constants as $key => [$value, $final]) {
            if (self::proves($value)) {
                $kept[$key] = [$value->inFile($file, $content), $final];
            }
        }

        return ['classes' => $classes, 'constants' => $kept];
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
        [$classes, $constants] = self::merge($project);
        [$libraryClasses, $libraryConstants] = self::merge($libraries);
        foreach ($libraryClasses as $class => $final) {
            if (\array_key_exists($class, $classes)) {
                continue;
            }

            $classes[$class] = $final;
            foreach ($libraryConstants as $key => $constant) {
                if (str_starts_with($key, $class.'::')) {
                    $constants[$key] = $constant;
                }
            }
        }

        return new self($classes, $constants);
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
     * Whether a file proves the value on its own, whatever the names it
     * reads: a name of another file is resolved when the value is.
     */
    private static function proves(ValueExpr $value): bool
    {
        if (\in_array($value->kind, [ValueExpr::LITERAL, ValueExpr::NON_STRING, ValueExpr::CLASS_CONSTANT, ValueExpr::CONSTANT], true)) {
            return true;
        }

        if (ValueExpr::CONCAT !== $value->kind && ValueExpr::ARRAY !== $value->kind) {
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
     * The operands of a concatenation, or the keys and the values of an
     * array.
     *
     * @return list<ValueExpr>
     */
    private static function children(ValueExpr $value): array
    {
        $children = $value->parts;
        foreach ($value->items as [$key, $item]) {
            if (null !== $key) {
                $children[] = $key;
            }

            $children[] = $item;
        }

        return $children;
    }

    /**
     * The classes and the constants of several contributions as one: a
     * class two of them declare is ambiguous.
     *
     * @param list<Contribution> $contributions
     *
     * @return array{array<string, bool|null>, array<string, array{ValueExpr, bool}>}
     */
    private static function merge(array $contributions): array
    {
        $classes = [];
        $constants = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution['classes'] as $class => $final) {
                $classes[$class] = \array_key_exists($class, $classes) ? null : $final;
            }

            $constants += $contribution['constants'];
        }

        return [$classes, $constants];
    }
}
