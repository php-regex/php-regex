<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class NetteReplaceKeys
{
    public function run(string $subject, string $replacement): void
    {
        Strings::replace($subject, ['/keys-no-replacement/' => 'a']);
        Strings::replace($subject, ['/keys-string-a/' => 'a', '/keys-string-b/' => 'b'], 'ignored');
        Strings::replace($subject, ['01' => 'a'], '');
        Strings::replace($subject, ['/keys-unknown-replacement/' => 'a'], $replacement);
        Strings::replace($subject, ['/keys-property-of-new/' => 'a'], (new Suffix())->value);
    }
}
