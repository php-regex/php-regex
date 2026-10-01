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

namespace PHPRegex\Transpiler\Target;

use PHPRegex\Transpiler\Target\JavaScript\JavaScriptTarget;
use PHPRegex\Transpiler\Target\Python\PythonTarget;
use PHPRegex\Transpiler\TranspileException;

/**
 * Registry for available transpilation targets.
 *
 * @internal
 */
final class TargetRegistry
{
    /**
     * @var array<string, TargetInterface>
     */
    private array $targets = [];

    /**
     * @param array<int, TargetInterface> $targets
     */
    public function __construct(array $targets = [])
    {
        foreach ($targets as $target) {
            $this->register($target);
        }

        $this->register(new JavaScriptTarget());
        $this->register(new PythonTarget());
    }

    public function register(TargetInterface $target): void
    {
        $this->targets[$target->getName()] = $target;

        foreach ($target->getAliases() as $alias) {
            $this->targets[$alias] = $target;
        }
    }

    public function get(string $name): TargetInterface
    {
        $key = strtolower(trim($name));

        if (isset($this->targets[$key])) {
            return $this->targets[$key];
        }

        throw new TranspileException('Unknown transpile target: '.$name.'.');
    }

    /**
     * @return array<int, string>
     */
    public function listTargets(): array
    {
        $names = array_keys($this->targets);
        sort($names);

        return array_values(array_unique($names));
    }
}
