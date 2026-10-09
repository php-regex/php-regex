<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class NetteReplaceCallback
{
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
        Strings::replace(subject: $subject, replacement: fn (array $m): string => 'x', pattern: ['named-key' => '/callback-named/']);
    }

    /**
     * @param array<int, string> $m
     */
    public function upper(array $m): string
    {
        return strtoupper($m[0]);
    }
}
