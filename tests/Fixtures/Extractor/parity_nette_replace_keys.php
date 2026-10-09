<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class NetteReplaceKeys
{
    public string $replacement = '';

    public function run(string $subject, string $replacement, ?\Closure $cb, bool $flag): void
    {
        Strings::replace($subject, ['/keys-no-replacement/' => 'a']);
        Strings::replace($subject, ['/keys-string-a/' => 'a', '/keys-string-b/' => 'b'], 'ignored');
        Strings::replace($subject, ['01' => 'a'], '');
        Strings::replace($subject, ['/keys-unknown-replacement/' => 'a'], $replacement);
        Strings::replace($subject, ['/keys-property-of-new/' => 'a'], (new Suffix())->value);
        Strings::replace($subject, ['/keys-coalesce-callable/' => 'a'], $cb ?? strtoupper(...));
        Strings::replace($subject, ['/keys-ternary-callable/' => 'a'], $flag ? 'x' : strtoupper(...));
        Strings::replace($subject, ['/keys-closure-called/' => 'a'], (function (): string {
            return 'x';
        })());
        Strings::replace($subject, ['/keys-property-of-this/' => 'a'], $this->replacement);
        Strings::replace($subject, ['/keys-unimported-closure/' => 'a'], Closure::fromCallable('strtoupper'));
        Strings::replace($subject, [null => 'a', '/keys-after-null/' => 'b']);
    }
}
