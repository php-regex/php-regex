<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\TestUtils;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache that keeps values serialized, as a real store does: what
 * comes back is a copy, never the object that went in.
 */
final class InMemorySimpleCache implements CacheInterface
{
    /**
     * @var array<string, string>
     */
    public array $stored = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return isset($this->stored[$key]) ? unserialize($this->stored[$key]) : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->stored[$key] = serialize($value);

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->stored[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->stored = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->stored[$key]);
    }
}
