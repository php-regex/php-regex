<?php

namespace App\Parity;

use Closure as AliasedClosure;
use Nette\Utils\Strings;

final class NetteReplaceCallback
{
    /**
     * @var array<int, callable>
     */
    public array $callbacks = [];

    public \Closure $callback;

    public function run(string $subject): void
    {
        Strings::replace($subject, ['closure-key' => '/callback-closure/'], function (array $m): string {
            return 'x';
        });
        Strings::replace($subject, ['arrow-key' => '/callback-arrow/'], fn (array $m): string => 'x');
        Strings::replace($subject, ['static-key' => '/callback-static/'], static fn (array $m): string => 'x');
        Strings::replace($subject, ['first-class-key' => '/callback-first-class/'], strtoupper(...));
        Strings::replace($subject, ['array-key' => '/callback-array/'], [$this, 'upper']);
        Strings::replace($subject, ['invokable-key' => '/callback-invokable/'], new Upper());
        Strings::replace($subject, ['parenthesized-key' => '/callback-parenthesized/'], (fn (array $m): string => 'x'));
        Strings::replace($subject, ['array-syntax-key' => '/callback-array-syntax/'], array($this, 'upper'));
        Strings::replace($subject, ['this-key' => '/callback-this/'], $this);
        Strings::replace($subject, ['object-cast-key' => '/callback-object-cast/'], (object) ['a' => 1]);
        Strings::replace($subject, ['array-cast-key' => '/callback-array-cast/'], (array) $this->callbacks);
        Strings::replace($subject, ['clone-key' => '/callback-clone/'], clone $this->callback);
        Strings::replace($subject, ['from-callable-key' => '/callback-from-callable/'], \Closure::fromCallable('strtoupper'));
        Strings::replace($subject, ['imported-from-callable-key' => '/callback-imported-from-callable/'], AliasedClosure::fromcallable('strtoupper'));
        Strings::replace($subject, ['static-first-class-key' => '/callback-static-first-class/'], static::lower(...));
        Strings::replace($subject, ['attribute-key' => '/callback-attribute/'], #[\JetBrains\PhpStorm\Pure] static function (array $m): string {
            return 'x';
        });
        Strings::replace(subject: $subject, replacement: fn (array $m): string => 'x', pattern: ['named-key' => '/callback-named/']);
    }

    /**
     * @param array<int, string> $m
     */
    public function upper(array $m): string
    {
        return strtoupper($m[0]);
    }

    /**
     * @param array<int, string> $m
     */
    public static function lower(array $m): string
    {
        return strtolower($m[0]);
    }
}
