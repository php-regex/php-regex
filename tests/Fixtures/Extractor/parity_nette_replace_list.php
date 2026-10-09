<?php

namespace App\Parity;

use Nette\Utils\Strings;

final class NetteReplaceList
{
    public function run(string $subject): void
    {
        Strings::replace($subject, ['/list-a/', '/list-b/'], 'x');
        Strings::replace($subject, [0 => '/list-int-key/'], 'x');
        Strings::replace($subject, ['0' => '/list-numeric-string-key/'], 'x');
        Strings::replace($subject, array('/list-array-syntax/'));
        Strings::replace($subject, [-1 => '/list-negative-key/'], 'x');
    }
}
